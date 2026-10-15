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
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Pelican\AiHelpAssistant\Services\AssistantSettings;
use Pelican\AiHelpAssistant\Services\AutomaticStartupService;
use Pelican\AiHelpAssistant\Services\RepairService;
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
            $this->autoReport = $automatic->latest($server, $user);
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
            ->schema($type === 'java_image' ? [
                Select::make('image')->label('Java image')
                    ->options($offer['images'])
                    ->default($suggested)
                    ->selectablePlaceholder(false)
                    ->required(),
            ] : [])
            ->action(function (array $data): void {
                [$server, $user] = $this->context();
                // Re-read the automatic report from encrypted storage; browser input only picks an offered image.
                $report = $this->report ?? app(AutomaticStartupService::class)->latest($server, $user);
                $image = is_string($data['image'] ?? null) ? $data['image'] : null;

                try {
                    $message = app(RepairService::class)->apply($server, $user, $report, $image);
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
