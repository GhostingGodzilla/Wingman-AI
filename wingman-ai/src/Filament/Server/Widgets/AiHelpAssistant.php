<?php

namespace Pelican\AiHelpAssistant\Filament\Server\Widgets;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Pelican\AiHelpAssistant\Services\AskService;
use Pelican\AiHelpAssistant\Services\AssistantSettings;
use Pelican\AiHelpAssistant\Services\AutomaticStartupService;
use Pelican\AiHelpAssistant\Services\EggAdvisorService;
use Pelican\AiHelpAssistant\Services\IncidentService;
use Pelican\AiHelpAssistant\Services\PerformanceService;
use Pelican\AiHelpAssistant\Services\RepairService;
use Pelican\AiHelpAssistant\Services\SafeReinstallService;
use Pelican\AiHelpAssistant\Services\StartupMonitorService;
use Throwable;

class AiHelpAssistant extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected string $view = 'wingman-ai::widgets.wingman-ai';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    #[Locked]
    public ?array $report = null;

    #[Locked]
    public ?array $autoReport = null;

    #[Locked]
    public array $autoStatus = [];

    /** Hides the fix button for a report after its repair was applied on this page. */
    #[Locked]
    public ?string $appliedFix = null;

    /** Display data for an AI egg suggestion; applying re-reads the stored proposal by id. */
    #[Locked]
    public ?array $eggProposal = null;

    /** The Ask Wingman input; validated on the server like any browser input. */
    public string $question = '';

    #[Locked]
    public ?array $answer = null;

    public function mount(): void
    {
        $this->refreshAutomaticDiagnosis();
    }

    #[On('setServerState')]
    public function observeNormalPower(string $uuid, string $state): void
    {
        try {
            [$server, $user] = $this->context();
            if ($uuid !== $server->uuid) {
                return;
            }
            $permission = match ($state) {
                'start' => SubuserPermission::ControlStart,
                'restart' => SubuserPermission::ControlRestart,
                default => null,
            };
            if ($permission !== null && $user->can($permission, $server)) {
                app(AutomaticStartupService::class)->attempt($server, $state);
            }
        } catch (Throwable) {
            // An observer failure must never interrupt the console's power action.
        }
    }

    /** Polling reads results only; the backend watches starts and queues diagnosis. */
    public function refreshAutomaticDiagnosis(): void
    {
        [$server, $user] = $this->context();
        if (!$user->can(SubuserPermission::StartupRead, $server)) {
            $this->autoReport = null;
            $this->autoStatus = ['status' => 'permission_required'];

            return;
        }

        try {
            $automatic = app(AutomaticStartupService::class);
            $status = $automatic->status($server, $user);
            // Only fixed display states enter Livewire's browser-visible snapshot.
            $this->autoStatus = [
                'status' => in_array($status['status'] ?? '', ['monitoring', 'running', 'failed', 'timeout', 'idle', 'disabled', 'requires_queue'], true)
                    ? $status['status'] : 'idle',
                'reason' => in_array($status['reason'] ?? '', ['requires_queue', 'queue_unavailable', 'monitor_unavailable', 'disabled_or_server_unavailable', 'daily_limit', 'cooldown'], true)
                    ? $status['reason'] : null,
            ];
            $startup = $automatic->latest($server, $user);
            if (is_array($startup)) {
                $startup['kind'] ??= 'startup';
            }
            try {
                $incident = app(IncidentService::class)->latest($server, $user);
            } catch (Throwable) {
                $incident = null;
            }
            // Show the newest of the startup, crash, and install reports.
            $this->autoReport = $incident !== null && ($startup === null || strcmp((string) ($incident['generated_at'] ?? ''), (string) ($startup['generated_at'] ?? '')) > 0)
                ? $incident : $startup;
        } catch (Throwable) {
            $this->autoReport = null;
            $this->autoStatus = ['status' => 'unavailable'];
        }
    }

    protected function automaticStatusText(): string
    {
        $status = $this->autoStatus['status'] ?? 'idle';
        $reason = $this->autoStatus['reason'] ?? null;
        if ($status === 'requires_queue' || in_array($reason, ['requires_queue', 'queue_unavailable'], true)) {
            return 'Automatic diagnosis needs a running background queue worker. Ask your panel administrator to enable it.';
        }
        if (in_array($reason, ['monitor_unavailable', 'disabled_or_server_unavailable'], true)) {
            return 'Automatic checks could not finish. Ask your panel administrator to check the node connection and background queue. Manual diagnosis is available.';
        }
        if ($reason === 'daily_limit') {
            return 'The automatic diagnosis limit has been reached for this server. Manual diagnosis is still available.';
        }
        if ($reason === 'cooldown') {
            return 'Automatic diagnosis is waiting before checking another failure. Manual diagnosis is still available.';
        }

        return match ($status) {
            'monitoring' => 'Watching a startup attempt in the background. A diagnosis will appear here if it fails or takes too long.',
            'running' => 'The latest startup reached a running state. Automatic checks are ready for the next attempt.',
            'failed' => 'The latest startup failed. Its automatic diagnosis appears below when ready.',
            'timeout' => 'The latest startup exceeded the watch period. Its automatic diagnosis appears below when ready.',
            'disabled' => 'Automatic startup diagnosis is turned off in plugin settings. Manual diagnosis is available.',
            'permission_required' => 'Automatic diagnosis reports require startup settings access. You can still diagnose the console manually.',
            'unavailable' => 'Automatic diagnosis status is temporarily unavailable. Manual diagnosis is available.',
            default => 'Automatic checks are ready. Failed or unusually slow starts will be diagnosed here without opening a capture.',
        };
    }

    protected function automaticReportTime(): string
    {
        $timestamp = $this->autoReport['generated_at'] ?? null;
        if (!is_string($timestamp) || $timestamp === '') {
            return 'Time unavailable';
        }

        try {
            return (new \DateTimeImmutable($timestamp))
                ->setTimezone(new \DateTimeZone('UTC'))->format('M j, Y H:i \\U\\T\\C');
        } catch (Throwable) {
            return 'Time unavailable';
        }
    }

    public static function canView(): bool
    {
        $server = Filament::getTenant();

        return $server instanceof Server
            && user() instanceof User
            && user()->can(SubuserPermission::WebsocketConnect, $server)
            && (bool) (app(AssistantSettings::class)->get()['enabled'] ?? true);
    }

    public function prepareDiagnosis(string $mode = 'startup'): array
    {
        [$server, $user] = $this->context();

        return $this->respond(function () use ($server, $user, $mode) {
            $config = app(StartupMonitorService::class)->prepare($server, $user, $mode);
            $this->report = null;

            return $config;
        });
    }

    public function beginCapture(string $runId): array
    {
        [$server, $user] = $this->context();

        return $this->respond(fn () => app(StartupMonitorService::class)->begin($server, $user, $runId));
    }

    public function completeDiagnosis(string $runId, array $capture): array
    {
        [$server, $user] = $this->context();

        return $this->respond(function () use ($server, $user, $runId, $capture) {
            $this->report = app(StartupMonitorService::class)->complete($server, $user, $runId, $capture);

            return ['complete' => true];
        });
    }

    public function cancelDiagnosis(string $runId): array
    {
        [$server, $user] = $this->context();

        return $this->respond(function () use ($server, $user, $runId) {
            app(StartupMonitorService::class)->cancel($server, $user, $runId);

            return ['cancelled' => true];
        });
    }

    /** The repair for the displayed report, after checking this user's permission for it. */
    protected function fixOffer(): ?array
    {
        $report = $this->report ?? $this->autoReport;
        if ($report === null || ($this->appliedFix !== null && $this->appliedFix === $this->fixFingerprint($report))) {
            return null;
        }

        try {
            [$server, $user] = $this->context();

            return app(RepairService::class)->offer($server, $user, $report);
        } catch (Throwable) {
            return null;
        }
    }

    protected function fixSummary(array $offer): string
    {
        return match ($offer['type'] ?? null) {
            'java_image' => 'Switch this server to a newer Java image and restart it.',
            'reinstall' => 'Reinstall the server so its install script downloads the missing files again.',
            'eula' => 'Accept the Minecraft EULA for this server and restart it.',
            default => '',
        };
    }

    /** Started only by a person from the report, with confirmation; never by the watcher. */
    public function applyFixAction(): Action
    {
        $offer = $this->fixOffer();
        $type = $offer['type'] ?? null;
        $suggested = $offer['suggested'] ?? null;

        return Action::make('applyFix')
            ->label(match ($type) {
                'java_image' => 'Switch Java version',
                'reinstall' => 'Reinstall server',
                'eula' => 'Accept EULA',
                default => 'Apply fix',
            })
            ->visible($offer !== null)
            ->color($type === 'reinstall' ? 'danger' : 'primary')
            ->requiresConfirmation()
            ->modalHeading(match ($type) {
                'java_image' => 'Switch Java version?',
                'reinstall' => 'Reinstall this server?',
                'eula' => 'Accept the Minecraft EULA?',
                default => 'Apply fix?',
            })
            ->modalDescription(match ($type) {
                'java_image' => 'This server stopped because its Java version is too old for the game or its mods. Choose a newer Java image; Wingman switches the server to it and restarts it.'
                    . ($suggested !== null ? ' Suggested: ' . $offer['images'][$suggested] . '.' : ''),
                'reinstall' => 'Reinstalling stops the server and runs its install script again to download the missing files. Some files may be deleted or overwritten, so back up worlds and settings you want to keep first.',
                'eula' => new HtmlString('By clicking &ldquo;I accept&rdquo; you agree to the <a href="https://www.minecraft.net/eula" target="_blank" rel="noopener noreferrer" class="underline">Minecraft EULA</a>. Wingman writes <code>eula=true</code> to <code>eula.txt</code> and restarts the server.'),
                default => null,
            })
            ->modalSubmitActionLabel(match ($type) {
                'reinstall' => 'Yes, reinstall',
                'eula' => 'I accept',
                default => 'Apply fix',
            })
            ->schema(match ($type) {
                'java_image' => [
                    Select::make('image')->label('Java image')
                        ->options($offer['images'])
                        ->default($suggested)
                        ->selectablePlaceholder(false)
                        ->required(),
                ],
                'reinstall' => [$this->backupToggle($offer['backup'] ?? null)],
                default => [],
            })
            ->action(function (array $data): void {
                [$server, $user] = $this->context();
                // Re-read the automatic report from encrypted storage; browser input only picks an offered image.
                $report = $this->report ?? app(AutomaticStartupService::class)->latest($server, $user);
                $image = is_string($data['image'] ?? null) ? $data['image'] : null;

                try {
                    $message = app(RepairService::class)->apply($server, $user, $report, $image, (bool) ($data['backup_first'] ?? false));
                } catch (ValidationException $exception) {
                    Notification::make()->title('Fix not applied')
                        ->body(collect($exception->errors())->flatten()->first() ?? 'The fix could not be applied.')
                        ->danger()->send();

                    return;
                } catch (Throwable $exception) {
                    logger()->warning('Wingman AI repair failed.', ['exception_type' => $exception::class]);
                    Notification::make()->title('Fix failed')
                        ->body('Check the panel logs or the Wings connection, then try again.')
                        ->danger()->send();

                    return;
                }

                $this->appliedFix = $this->fixFingerprint($report);
                Notification::make()->title('Fix applied')->body($message)->success()->send();
                $this->refreshAutomaticDiagnosis();
            });
    }

    /** Admin egg tools, or null when this user cannot use them. */
    protected function eggTools(): ?array
    {
        try {
            [$server, $user] = $this->context();
            $advisor = app(EggAdvisorService::class);
            if (!$advisor->available($server, $user)) {
                return null;
            }

            return ['last_change' => $advisor->lastChange($server, $user)];
        } catch (Throwable) {
            return null;
        }
    }

    /** Asks the AI to review this server's egg; only an admin can, and nothing is changed. */
    public function checkEgg(): void
    {
        [$server, $user] = $this->context();
        try {
            $this->eggProposal = app(EggAdvisorService::class)->propose($server, $user);
        } catch (ValidationException $exception) {
            $this->eggProposal = null;
            Notification::make()->title('Egg check failed')
                ->body(collect($exception->errors())->flatten()->first() ?? 'The egg could not be checked.')
                ->danger()->send();
        } catch (Throwable $exception) {
            $this->eggProposal = null;
            logger()->warning('Wingman AI egg check failed.', ['exception_type' => $exception::class]);
            Notification::make()->title('Egg check failed')->body('Check the panel logs, then try again.')->danger()->send();
        }
    }

    public function dismissEggProposal(): void
    {
        $this->eggProposal = null;
    }

    public function applyEggFixAction(): Action
    {
        $proposal = $this->eggProposal;
        $count = (int) ($proposal['servers_using'] ?? 0);

        return Action::make('applyEggFix')
            ->label('Apply to egg')
            ->color('danger')
            ->visible($proposal !== null && ($proposal['changes'] ?? []) !== [])
            ->requiresConfirmation()
            ->modalHeading('Change the "' . ($proposal['egg_name'] ?? 'egg') . '" egg?')
            ->modalDescription('This egg is used by ' . $count . ' ' . ($count === 1 ? 'server' : 'servers')
                . '. Install-script changes apply to each of them the next time it is installed or reinstalled. Wingman saves the current egg first so you can undo this.')
            ->modalSubmitActionLabel('Apply change')
            ->schema(fn () => [
                Toggle::make('reinstall')->label('Reinstall this server afterwards')
                    ->helperText('Runs the updated install script. Some files may be deleted or overwritten.')
                    ->default((bool) ($proposal['reinstall'] ?? false)),
                $this->backupToggle($this->backupStatus())->label('Back up the server first (when reinstalling)'),
            ])
            ->action(function (array $data): void {
                [$server, $user] = $this->context();
                $this->runEggTask('Egg updated', fn () => app(EggAdvisorService::class)->apply($server, $user, (string) ($this->eggProposal['id'] ?? ''), (bool) ($data['reinstall'] ?? false), (bool) ($data['backup_first'] ?? false)));
            });
    }

    public function undoEggFixAction(): Action
    {
        return Action::make('undoEggFix')
            ->label('Undo')
            ->color('gray')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading('Undo Wingman\'s egg change?')
            ->modalDescription('Restores the egg to how it was before Wingman changed it. This server\'s startup, image, and variables are restored only where they still have the values Wingman set.')
            ->modalSubmitActionLabel('Undo change')
            ->action(function (): void {
                [$server, $user] = $this->context();
                $this->runEggTask('Egg restored', fn () => app(EggAdvisorService::class)->undo($server, $user));
            });
    }

    private function backupToggle(?array $status): Toggle
    {
        $possible = (bool) ($status['possible'] ?? false);

        return Toggle::make('backup_first')->label('Back up the server first')
            ->default($possible && (bool) (app(AssistantSettings::class)->get()['backup_before_reinstall'] ?? true))
            ->disabled(!$possible)
            ->helperText($possible
                ? 'Makes a panel backup first; the reinstall starts by itself when the backup finishes.'
                : 'No backup can be made: ' . ($status['reason'] ?? 'backups are not available for this server.'));
    }

    private function backupStatus(): ?array
    {
        try {
            [$server, $user] = $this->context();

            return app(SafeReinstallService::class)->backupStatus($server, $user);
        } catch (Throwable) {
            return null;
        }
    }

    /** A backup-then-reinstall in progress, and the latest result of one. */
    protected function reinstallStatus(): array
    {
        try {
            [$server] = $this->context();
            $reinstalls = app(SafeReinstallService::class);

            return ['pending' => $reinstalls->pending($server), 'notice' => $reinstalls->lastNotice($server)];
        } catch (Throwable) {
            return ['pending' => null, 'notice' => null];
        }
    }

    protected function canAsk(): bool
    {
        try {
            [$server, $user] = $this->context();

            return app(AskService::class)->available($server, $user);
        } catch (Throwable) {
            return false;
        }
    }

    /** Ask Wingman a question about this server. Reads context only. */
    public function ask(): void
    {
        [$server, $user] = $this->context();
        $question = $this->question;
        try {
            $this->answer = ['question' => trim($question), 'answer' => app(AskService::class)->ask($server, $user, $question)];
            $this->question = '';
        } catch (ValidationException $exception) {
            Notification::make()->title('No answer')
                ->body(collect($exception->errors())->flatten()->first() ?? 'Wingman could not answer.')
                ->danger()->send();
        } catch (Throwable $exception) {
            logger()->warning('Wingman AI question failed.', ['exception_type' => $exception::class]);
            Notification::make()->title('No answer')->body('Wingman could not answer right now. Try again later.')->danger()->send();
        }
    }

    /** Lag doctor: a short, read-only usage and console check. */
    public function checkPerformance(): void
    {
        [$server, $user] = $this->context();
        try {
            $this->report = app(PerformanceService::class)->check($server, $user);
        } catch (ValidationException $exception) {
            Notification::make()->title('Performance check failed')
                ->body(collect($exception->errors())->flatten()->first() ?? 'The check could not run.')
                ->danger()->send();
        } catch (Throwable $exception) {
            logger()->warning('Wingman AI performance check failed.', ['exception_type' => $exception::class]);
            Notification::make()->title('Performance check failed')->body('Check the Wings connection, then try again.')->danger()->send();
        }
    }

    private function runEggTask(string $title, callable $task): void
    {
        try {
            $message = $task();
        } catch (ValidationException $exception) {
            Notification::make()->title('Egg not changed')
                ->body(collect($exception->errors())->flatten()->first() ?? 'The egg could not be changed.')
                ->danger()->send();

            return;
        } catch (Throwable $exception) {
            logger()->warning('Wingman AI egg change failed.', ['exception_type' => $exception::class]);
            Notification::make()->title('Egg change failed')->body('Check the panel logs, then try again.')->danger()->send();

            return;
        }

        $this->eggProposal = null;
        Notification::make()->title($title)->body($message)->success()->send();
    }

    private function fixFingerprint(?array $report): string
    {
        return hash('sha256', json_encode([$report['generated_at'] ?? null, $report['summary'] ?? null, $report['fix'] ?? null]) ?: '');
    }

    protected function isAiConfigured(): bool
    {
        $settings = app(AssistantSettings::class)->get();

        return (bool) ($settings['ai_enabled'] ?? false)
            && trim((string) ($settings['base_url'] ?? '')) !== ''
            && trim((string) ($settings['model'] ?? '')) !== '';
    }

    /** Always resolve the authenticated context again; widget input is never identity. */
    private function context(): array
    {
        $server = Filament::getTenant();
        $user = user();
        abort_unless($server instanceof Server && $user instanceof User, 403);
        abort_unless($user->can(SubuserPermission::WebsocketConnect, $server), 403);

        return [$server, $user];
    }

    private function respond(callable $operation): array
    {
        try {
            return $operation();
        } catch (ValidationException $exception) {
            return ['error' => collect($exception->errors())->flatten()->first() ?? 'The diagnosis could not continue.'];
        } catch (Throwable $exception) {
            // Request exceptions may contain provider secrets or sensitive console text.
            logger()->warning('Wingman AI action failed.', ['exception_type' => $exception::class]);

            return ['error' => 'The diagnostic could not continue. Check the panel logs or the Wings connection, then try again.'];
        }
    }
}
