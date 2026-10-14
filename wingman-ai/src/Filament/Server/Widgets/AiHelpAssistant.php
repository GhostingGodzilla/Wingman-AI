<?php

namespace Pelican\AiHelpAssistant\Filament\Server\Widgets;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Pelican\AiHelpAssistant\Services\AssistantSettings;
use Pelican\AiHelpAssistant\Services\AutomaticStartupService;
use Pelican\AiHelpAssistant\Services\StartupMonitorService;
use Throwable;

class AiHelpAssistant extends Widget
{
    protected string $view = 'wingman-ai::widgets.wingman-ai';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    #[Locked]
    public ?array $report = null;

    #[Locked]
    public ?array $autoReport = null;

    #[Locked]
    public array $autoStatus = [];

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
