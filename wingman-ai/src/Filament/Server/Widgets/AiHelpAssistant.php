<?php

namespace Pelican\AiHelpAssistant\Filament\Server\Widgets;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Pelican\AiHelpAssistant\Services\AssistantSettings;
use Pelican\AiHelpAssistant\Services\StartupMonitorService;
use Throwable;

class AiHelpAssistant extends Widget
{
    protected string $view = 'wingman-ai::widgets.wingman-ai';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    #[Locked]
    public ?array $report = null;

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
