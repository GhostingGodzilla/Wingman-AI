<?php

namespace Pelican\AiHelpAssistant\Services;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonServerRepository;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Pelican\AiHelpAssistant\Jobs\CheckAutomaticStartup;
use Pelican\AiHelpAssistant\Support\AutomaticStartupState;
use Throwable;

/** Background observation of normal starts. This service never sends power actions. */
class AutomaticStartupService
{
    private const RETENTION_SECONDS = 86400;

    /** A normal, already authorized start/restart request was observed. */
    public function attempt(Server $server, string $action = 'start'): void
    {
        if (!in_array($action, ['start', 'restart'], true) || !$this->enabled(app(AssistantSettings::class)->get())) {
            return;
        }
        Cache::lock($this->stateKey($server->id) . ':lock', 10)->block(2, function () use ($server, $action) {
            $state = Cache::get($this->stateKey($server->id), []);
            if (!is_array($state) || ($state['owner_id'] ?? $server->owner_id) !== $server->owner_id) {
                $state = [];
            }
            $result = AutomaticStartupState::intent($state, $this->clock(), (string) Str::uuid());
            if ($result['action'] === 'ignore') {
                return;
            }
            $next = $result['state'];
            if (($state['generation'] ?? null) !== ($next['generation'] ?? null)) {
                unset($next['queued_generation'], $next['next_check_at'], $next['analysis_started']);
            }
            $next['owner_id'] = $server->owner_id;
            $next['intent_observed'] = true;
            $next['intent_action'] = $action;
            $next['monitor_status'] = $this->mapAction($result['action']);
            $next['monitor_reason'] = $result['reason'];
            $this->putState($server->id, $next);
            if ($result['action'] === 'watch') {
                $this->schedule($server->id, $next);
            }
        });
    }

    public function record(Server $server, string $previous, string $current): void
    {
        $settings = app(AssistantSettings::class)->get();
        if (!$this->enabled($settings)) {
            return;
        }

        Cache::lock($this->stateKey($server->id) . ':lock', 10)->block(2, function () use ($server, $previous, $current) {
            $state = Cache::get($this->stateKey($server->id), []);
            if (!is_array($state) || ($state['owner_id'] ?? $server->owner_id) !== $server->owner_id) {
                $state = [];
            }
            $result = AutomaticStartupState::callback($state, $previous, $current, $this->clock(), (string) Str::uuid());
            if ($result['action'] === 'ignore') {
                return;
            }
            $next = $result['state'];
            $newGeneration = ($state['generation'] ?? null) !== ($next['generation'] ?? null);
            $next['owner_id'] = $server->owner_id;
            if ($newGeneration) {
                unset($next['queued_generation'], $next['next_check_at'], $next['analysis_started']);
            }
            $next['monitor_status'] = $this->mapAction($result['action']);
            $next['monitor_reason'] = $result['reason'];
            $this->putState($server->id, $next);
            if ($result['action'] === 'watch') {
                $this->schedule($server->id, $next);
            }
        });
    }

    public function check(int $serverId, string $generation): void
    {
        // A separate processing lock leaves state callbacks responsive during I/O.
        Cache::lock('wingman:auto:processing:' . $serverId . ':' . $generation, 100)->get(function () use ($serverId, $generation) {
            $state = Cache::get($this->stateKey($serverId));
            if (!$this->matches($state, $generation) || !empty($state['analysis_started']) || !empty($state['terminal'])) {
                return;
            }
            $server = Server::query()->find($serverId);
            $settings = app(AssistantSettings::class)->get();
            if (!$server || !$this->eligible($server, $state, $settings) || !$this->queueAvailable()) {
                $this->end($serverId, $generation, 'disabled', 'disabled_or_server_unavailable');
                return;
            }
            $watch = $this->watchSeconds($settings);
            if ($this->clock() - (float) $state['started_at'] > $watch + 120) {
                $this->end($serverId, $generation, 'idle', 'stale_queue_job');
                return;
            }

            // This bypasses Pelican's status cache. Out-of-order callback hints must
            // never establish a failure while the daemon is actually running.
            $repository = app(DaemonServerRepository::class)->setServer($server);
            $daemonState = (string) ($repository->getDetails(5)['state'] ?? 'missing');
            $decision = Cache::lock($this->stateKey($serverId) . ':lock', 10)->block(2, function () use ($serverId, $generation, $daemonState, $watch) {
                $latest = Cache::get($this->stateKey($serverId));
                if (!$this->matches($latest, $generation) || !empty($latest['terminal']) || !empty($latest['analysis_started'])) {
                    return null;
                }
                $result = AutomaticStartupState::observe($latest, $daemonState, $this->clock(), $watch, 20, $generation);
                $next = $result['state'];
                unset($next['queued_generation'], $next['next_check_at']);
                $next['monitor_status'] = $this->mapAction($result['action']);
                $next['monitor_reason'] = $result['reason'];
                if (in_array($result['action'], ['failed', 'timeout'], true)) {
                    $next['analysis_started'] = true;
                }
                if ($result['action'] === 'healthy') {
                    // Callback retries have no source timestamp. Clear prior failure
                    // only after fresh daemon observations confirm a stable run.
                    Cache::forget($this->reportKey($serverId));
                    Cache::forget($this->reportKey($serverId) . ':index');
                }
                $this->putState($serverId, $next);

                return $result + ['current' => $next];
            });
            if (!$decision) {
                return;
            }
            if ($decision['action'] === 'watch') {
                Cache::lock($this->stateKey($serverId) . ':lock', 10)->block(2, function () use ($serverId, $generation) {
                    $latest = Cache::get($this->stateKey($serverId));
                    if ($this->matches($latest, $generation) && empty($latest['terminal'])) {
                        $this->schedule($serverId, $latest);
                    }
                });
                return;
            }
            if (!in_array($decision['action'], ['failed', 'timeout'], true)) {
                return; // A stable running server finishes quietly, with no AI request.
            }
            $this->diagnose($server, $generation, $decision['action'], $decision['current'], $settings, $repository);
        });
    }

    public function latest(Server $server, User $user): ?array
    {
        $this->authorizeReport($server, $user);
        $encrypted = Cache::get($this->reportKey($server->id));
        if (!is_string($encrypted)) {
            return null;
        }
        try {
            $envelope = json_decode(Crypt::decryptString($encrypted), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($envelope) || ($envelope['owner_id'] ?? null) !== $server->owner_id || !is_array($envelope['report'] ?? null)) {
                return null;
            }

            return $envelope['report'];
        } catch (Throwable) {
            return null;
        }
    }

    public function status(Server $server, User $user): array
    {
        $this->authorizeReport($server, $user);
        $settings = app(AssistantSettings::class)->get();
        $state = Cache::get($this->stateKey($server->id), []);
        $index = Cache::get($this->reportKey($server->id) . ':index', []);
        $latest = is_array($index) && ($index['owner_id'] ?? null) === $server->owner_id ? ($index['generated_at'] ?? null) : null;
        if (!$this->enabled($settings)) {
            return ['status' => 'disabled', 'latest_timestamp' => $latest, 'reason' => 'disabled'];
        }
        if (!$this->queueAvailable()) {
            return ['status' => 'disabled', 'latest_timestamp' => $latest, 'reason' => 'requires_queue'];
        }
        if (!is_array($state) || ($state['owner_id'] ?? null) !== $server->owner_id) {
            return ['status' => 'idle', 'latest_timestamp' => $latest];
        }
        $status = $state['monitor_status'] ?? 'idle';
        if (!in_array($status, ['monitoring', 'running', 'failed', 'timeout', 'idle', 'disabled'], true)) {
            $status = 'idle';
        }

        return ['status' => $status, 'latest_timestamp' => $latest, 'reason' => $state['monitor_reason'] ?? ''];
    }

    /** Queue failures expose a fixed status, never exception text or raw logs. */
    public function unavailable(int $serverId, string $generation): void
    {
        try {
            $this->end($serverId, $generation, 'failed', 'monitor_unavailable');
        } catch (Throwable) {
            // Cache/queue outages must not change the application's server behavior.
        }
    }

    protected function clock(): float
    {
        return microtime(true);
    }

    protected function pluginEnabled(): bool
    {
        try {
            // Plugin is a Sushi model; its rows can remain stale in a long-lived
            // queue worker. Read the installed manifest afresh after Disable.
            $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/plugin.json'), true, 16, JSON_THROW_ON_ERROR);

            return ($manifest['meta']['status'] ?? null) === 'enabled';
        } catch (Throwable) {
            return false;
        }
    }

    private function diagnose(Server $server, string $generation, string $outcome, array $state, array $settings, DaemonServerRepository $repository): void
    {
        if (!$this->claimBudget($server->id, $generation, $settings)) {
            return;
        }
        $server->refresh();
        if (!$this->eligible($server, $state, app(AssistantSettings::class)->get()) || !$this->isCurrent($server->id, $generation)) {
            return;
        }
        $owner = $server->user()->first();
        if (!$owner instanceof User || $owner->id !== $state['owner_id']
            || !$owner->can(SubuserPermission::WebsocketConnect, $server) || !$owner->can(SubuserPermission::StartupRead, $server)) {
            return;
        }
        $logs = '';
        $logsAvailable = false;
        $truncated = false;
        try {
            $rows = $repository->getHttpClient()->connectTimeout(5)->timeout(10)
                ->get('/api/servers/' . $server->uuid . '/logs', ['size' => 100])->throw()->json('data');
            if (is_array($rows)) {
                $logsAvailable = true;
                [$logs, $truncated] = $this->boundedLogs(array_slice($rows, -100));
            }
        } catch (Throwable) {
            // Missing logs are evidence of missing information, not a server crash.
        }
        if (!$this->isCurrent($server->id, $generation)) {
            return;
        }
        $server->refresh();
        if (!$this->eligible($server, $state, app(AssistantSettings::class)->get()) || $server->owner_id !== $owner->id) {
            return; // Disable/ownership changes while logs were fetched also stop AI.
        }
        $capture = [
            'logs' => $logs,
            'outcome' => $outcome,
            'observed_state' => $state['last_snapshot_state'] ?? $state['state'] ?? 'unknown',
            'observations' => [
                'capture_mode' => 'automatic',
                'logs_may_include_previous_run' => true,
                'logs_available' => $logsAvailable,
                'log_truncated' => $truncated,
                'capture_seconds' => max(0, (int) ($this->clock() - (float) $state['started_at'])),
                'initial_state' => !empty($state['awaiting_transition']) ? 'unknown' : 'starting',
                'start_requested' => (bool) ($state['intent_observed'] ?? false),
                'startup_transition_confirmed' => empty($state['awaiting_transition']),
            ],
        ];
        $report = app(DiagnosisService::class)->diagnose($server, $owner, $capture);
        // DiagnosisService redacts both the provider response and built-in report.
        // Never retain raw logs; persist only the redacted report under APP_KEY.
        $generatedAt = gmdate('c', (int) $this->clock());
        $report['generated_at'] = $generatedAt;
        $report['outcome'] = $outcome;
        $report['attempt_started_at'] = gmdate('c', (int) $state['started_at']);
        Cache::lock($this->stateKey($server->id) . ':lock', 10)->block(2, function () use ($server, $generation, $owner, $report, $generatedAt) {
            $freshServer = Server::query()->find($server->id);
            if (!$freshServer || $freshServer->owner_id !== $owner->id || !$this->isCurrent($server->id, $generation)
                || !$this->enabled(app(AssistantSettings::class)->get())) {
                return;
            }
            $encrypted = Crypt::encryptString(json_encode(['owner_id' => $owner->id, 'report' => $report], JSON_THROW_ON_ERROR));
            Cache::put($this->reportKey($server->id), $encrypted, now()->addSeconds(self::RETENTION_SECONDS));
            Cache::put($this->reportKey($server->id) . ':index', ['owner_id' => $owner->id, 'generated_at' => $generatedAt], now()->addSeconds(self::RETENTION_SECONDS));
        });
    }

    private function claimBudget(int $serverId, string $generation, array $settings): bool
    {
        return Cache::lock('wingman:auto:budget:' . $serverId . ':lock', 10)->block(2, function () use ($serverId, $generation, $settings) {
            $cooldown = max(30, min(3600, (int) ($settings['auto_cooldown_seconds'] ?? 300)));
            $key = 'wingman:auto:cooldown:' . $serverId;
            if (Cache::has($key)) {
                $this->setReason($serverId, $generation, 'cooldown');
                return false;
            }
            $dailyKey = 'wingman:auto:daily:' . $serverId;
            Cache::add($dailyKey, 0, now()->addSeconds(self::RETENTION_SECONDS));
            if ((int) Cache::get($dailyKey, 0) >= max(1, min(1000, (int) ($settings['auto_daily_limit'] ?? 20)))) {
                $this->setReason($serverId, $generation, 'daily_limit');
                return false;
            }
            Cache::increment($dailyKey);
            Cache::put($key, true, now()->addSeconds($cooldown));

            return true;
        });
    }

    private function boundedLogs(array $rows): array
    {
        $lines = [];
        $bytes = 0;
        $truncated = false;
        foreach (array_reverse($rows) as $row) {
            if (!is_string($row)) {
                continue;
            }
            $length = strlen($row) + 1;
            if ($length > 262144 || $bytes + $length > 262144) {
                $truncated = true;
                continue; // Whole lines only: keep secret labels attached to values.
            }
            $lines[] = $row;
            $bytes += $length;
        }

        return [implode("\n", array_reverse($lines)), $truncated];
    }

    private function schedule(int $serverId, array $state): void
    {
        $generation = $state['generation'] ?? null;
        if (!is_string($generation) || !empty($state['terminal'])
            || ($state['queued_generation'] ?? null) === $generation) {
            return;
        }
        if (!$this->queueAvailable()) {
            $state['monitor_status'] = 'disabled';
            $state['monitor_reason'] = 'requires_queue';
            $state['terminal'] = 'cancel';
            $this->putState($serverId, $state);
            return;
        }
        $state['queued_generation'] = $generation;
        $state['next_check_at'] = $this->clock() + 10;
        $this->putState($serverId, $state);
        try {
            Bus::dispatch((new CheckAutomaticStartup($serverId, $generation))->delay(now()->addSeconds(10)));
        } catch (Throwable) {
            $state['monitor_status'] = 'failed';
            $state['monitor_reason'] = 'queue_unavailable';
            $state['terminal'] = 'cancel';
            unset($state['queued_generation'], $state['next_check_at']);
            $this->putState($serverId, $state);
        }
    }

    private function end(int $serverId, string $generation, string $status, string $reason): void
    {
        Cache::lock($this->stateKey($serverId) . ':lock', 10)->block(2, function () use ($serverId, $generation, $status, $reason) {
            $state = Cache::get($this->stateKey($serverId));
            if (!$this->matches($state, $generation) || ($state['terminal'] ?? null) === 'healthy') {
                return;
            }
            $state['terminal'] = 'cancel';
            $state['monitor_status'] = $status;
            $state['monitor_reason'] = $reason;
            unset($state['queued_generation'], $state['next_check_at']);
            $this->putState($serverId, $state);
        });
    }

    private function setReason(int $serverId, string $generation, string $reason): void
    {
        Cache::lock($this->stateKey($serverId) . ':lock', 10)->block(2, function () use ($serverId, $generation, $reason) {
            $state = Cache::get($this->stateKey($serverId));
            if ($this->matches($state, $generation)) {
                $state['monitor_reason'] = $reason;
                $this->putState($serverId, $state);
            }
        });
    }

    private function eligible(Server $server, array $state, array $settings): bool
    {
        return $this->enabled($settings) && $server->owner_id === ($state['owner_id'] ?? null)
            && !$server->isInConflictState() && !$server->node->isUnderMaintenance();
    }

    private function enabled(array $settings): bool
    {
        return $this->pluginEnabled() && (bool) ($settings['enabled'] ?? true) && (bool) ($settings['automatic_enabled'] ?? true);
    }

    private function queueAvailable(): bool
    {
        $connection = config('queue.default');
        if (!is_string($connection) || in_array($connection, ['', 'sync', 'null'], true)) {
            return false;
        }
        $driver = config('queue.connections.' . $connection . '.driver');

        // Laravel's deferred/background/failover drivers may execute a delay inline
        // or fall back to doing so. Only established delayed queue drivers qualify.
        return in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true);
    }

    private function authorizeReport(Server $server, User $user): void
    {
        abort_unless($user->can(SubuserPermission::WebsocketConnect, $server) && $user->can(SubuserPermission::StartupRead, $server), 403);
    }

    private function mapAction(string $action): string
    {
        return match ($action) {
            'watch' => 'monitoring',
            'healthy' => 'running',
            'failed', 'timeout' => $action,
            default => 'idle',
        };
    }

    private function watchSeconds(array $settings): int
    {
        return max(30, min(600, (int) ($settings['watch_seconds'] ?? 180)));
    }

    private function matches(mixed $state, string $generation): bool
    {
        return is_array($state) && ($state['generation'] ?? null) === $generation;
    }

    private function isCurrent(int $serverId, string $generation): bool
    {
        $state = Cache::get($this->stateKey($serverId));

        return $this->matches($state, $generation) && !empty($state['analysis_started'])
            && in_array($state['terminal'] ?? null, ['failed', 'timeout'], true);
    }

    private function putState(int $serverId, array $state): void
    {
        Cache::put($this->stateKey($serverId), $state, now()->addSeconds(self::RETENTION_SECONDS));
    }

    private function stateKey(int $serverId): string
    {
        return 'wingman:auto:state:' . $serverId;
    }

    private function reportKey(int $serverId): string
    {
        return 'wingman:auto:report:' . $serverId;
    }
}
