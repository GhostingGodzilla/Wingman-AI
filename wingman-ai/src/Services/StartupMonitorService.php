<?php

namespace Pelican\AiHelpAssistant\Services;

use App\Enums\ContainerStatus;
use App\Enums\NodeJwtScope;
use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Services\Nodes\NodeJWTService;
use App\Services\Servers\GetUserPermissionsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Coordinates short lived, user/server bound captures. Power is only sent here. */
class StartupMonitorService
{
    public static function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/resources/js/startup-monitor.js');
    }

    public function prepare(Server $server, User $user, string $mode): array
    {
        $this->authorize($server, $user);
        $settings = app(AssistantSettings::class)->get();
        $this->assert((bool) ($settings['enabled'] ?? true), 'The assistant is disabled by your panel administrator.');
        $this->assert(in_array($mode, ['startup', 'existing'], true), 'Choose a supported diagnostic action.');

        $admissionKey = 'wingman-ai:admission:' . $user->id . ':' . $server->id;
        $this->assert(!RateLimiter::tooManyAttempts($admissionKey, 6), 'Please wait a few minutes before starting another capture.');
        RateLimiter::hit($admissionKey, 300);

        return Cache::lock($this->activeKey($server, $user) . ':lock', 15)->block(3, function () use ($server, $user, $settings, $mode) {
            $activeId = Cache::get($this->activeKey($server, $user));
            $this->assert(!$activeId || !Cache::has($this->runKey($activeId)), 'A diagnostic capture is already active. Cancel it or let it finish first.');
            $watch = max(30, min(600, (int) ($settings['watch_seconds'] ?? 180)));
            $id = (string) Str::uuid();
            $ttl = now()->addSeconds($watch + 120);
            $config = [
                'run_id' => $id,
                'mode' => $mode,
                'watch_seconds' => $watch,
                'max_log_bytes' => max(4096, min(64000, (int) ($settings['max_log_bytes'] ?? 48000))),
            ];
            // History needs no browser socket. Fresh captures attach a read-only
            // socket before the authorized backend start action is sent.
            if ($mode === 'startup') {
                $permissions = app(GetUserPermissionsService::class)->handle($server, $user);
                $scopes = [SubuserPermission::WebsocketConnect->value];
                if (in_array('admin.websocket.errors', $permissions, true)) {
                    $scopes[] = 'admin.websocket.errors';
                }
                $token = app(NodeJWTService::class)
                    ->setExpiresAt(now()->addMinutes(12)->toImmutable())
                    ->setScopes(NodeJwtScope::Websocket)
                    ->setUser($user)
                    ->setClaims(['server_uuid' => $server->uuid, 'permissions' => $scopes])
                    ->handle($server->node, $user->id . $server->uuid)->toString();
                $config += [
                    'socket' => str_replace(['https://', 'http://'], ['wss://', 'ws://'], $server->node->getConnectionAddress()) . '/api/servers/' . $server->uuid . '/ws',
                    'token' => $token,
                ];
            }
            Cache::put($this->runKey($id), [
                'user' => $user->id,
                'server' => $server->id,
                'mode' => $mode,
                'phase' => 'prepared',
                'prepared_at' => time(),
                'expires_at' => time() + $watch + 120,
            ], $ttl);
            Cache::put($this->activeKey($server, $user), $id, $ttl);

            return $config;
        });
    }

    /** Fresh starts wait for socket authentication; history is read through REST. */
    public function begin(Server $server, User $user, string $id): array
    {
        $this->authorize($server, $user);

        return Cache::lock($this->runKey($id) . ':lock', 15)->block(3, function () use ($server, $user, $id) {
            $run = $this->getRun($server, $user, $id);
            $this->assert($run['phase'] === 'prepared', 'This capture has already begun or expired.');
            $this->assert(time() - $run['prepared_at'] <= 30, 'The connection took too long. Please try again.');
            $this->assert((bool) (app(AssistantSettings::class)->get()['enabled'] ?? true), 'The assistant is disabled.');

            if ($run['mode'] === 'existing') {
                return $this->beginExisting($server, $user, $id, $run);
            }

            return Cache::lock('wingman-ai:power:' . $server->id, 15)->block(3, function () use ($server, $user, $id, $run) {
                $server->refresh();
                $server->validateCurrentState();
                $this->assert(!$server->node->isUnderMaintenance(), 'This node is under maintenance. Wait until maintenance ends.');
                // Do not use retrieveStatus(): its 15 second cache can race with a start.
                $repository = app(DaemonServerRepository::class)->setServer($server);
                $state = (string) ($repository->getDetails(5)['state'] ?? 'missing');
                $this->assert($state !== 'missing', 'Pelican cannot reach this server through Wings. Check the node connection first.');
                $action = 'existing';
                if ($run['mode'] === 'startup') {
                    if (in_array($state, ['running', 'starting', 'restarting'], true)) {
                        $action = 'watching';
                    } else {
                        $this->assert(in_array($state, ['offline', 'exited', 'dead', 'created'], true), 'The server is changing state. Wait until it has stopped before trying again.');
                        $this->assert($user->can(SubuserPermission::ControlStart, $server), 'You need permission to start this server. You can still analyze existing logs.');
                        $repository->power('start')->throw();
                        Activity::event('server:power.start')->subject($server)->actor($user)->property('source', 'wingman-ai')->log();
                        $action = 'started';
                    }
                }
                $run['phase'] = 'capturing';
                $run['action'] = $action;
                $run['initial_state'] = $state;
                $run['started_at'] = time();
                $this->putRun($id, $run);

                return ['action' => $action, 'initial_state' => $state];
            });
        });
    }

    private function beginExisting(Server $server, User $user, string $id, array $run): array
    {
        $this->authorize($server, $user);
        $server->refresh();
        $server->validateCurrentState();
        $this->assert(!$server->node->isUnderMaintenance(), 'This node is under maintenance. Wait until maintenance ends.');
        $repository = app(DaemonServerRepository::class)->setServer($server);
        $state = (string) ($repository->getDetails(5)['state'] ?? 'missing');
        $rows = $repository->getHttpClient()->connectTimeout(5)->timeout(10)
            ->get('/api/servers/' . $server->uuid . '/logs', ['size' => 100])->throw()->json('data');
        $this->assert(is_array($rows), 'Wings did not return a recent console log. Try again or check the node connection.');
        [$logs, $truncated] = $this->boundedHistory($rows);
        $run['phase'] = 'capturing';
        $run['action'] = 'existing';
        $run['initial_state'] = $state;
        $run['started_at'] = time();
        $this->putRun($id, $run);

        return ['action' => 'existing', 'initial_state' => $state, 'logs' => $logs, 'log_truncated' => $truncated];
    }

    /** Keep complete recent lines so truncation cannot separate a secret label. */
    private function boundedHistory(array $rows): array
    {
        $lines = [];
        $bytes = 0;
        $truncated = false;
        foreach ($rows as $row) {
            if (!is_string($row)) {
                continue;
            }
            foreach (preg_split('/\r\n|\r|\n/', $row) as $line) {
                $size = strlen($line) + 1;
                if ($size > 262144) {
                    $truncated = true;
                    continue;
                }
                $lines[] = $line;
                $bytes += $size;
                while ($bytes > 262144) {
                    $bytes -= strlen(array_shift($lines)) + 1;
                    $truncated = true;
                }
            }
        }

        return [implode("\n", $lines), $truncated];
    }

    public function complete(Server $server, User $user, string $id, array $capture): array
    {
        $this->authorize($server, $user);
        $settings = app(AssistantSettings::class)->get();
        $this->assert((bool) ($settings['enabled'] ?? true), 'The assistant is disabled.');
        $run = Cache::lock($this->runKey($id) . ':lock', 15)->block(3, function () use ($server, $user, $id, $settings) {
            $run = $this->getRun($server, $user, $id);
            $this->assert($run['phase'] === 'capturing', 'This capture was already analyzed, cancelled, or expired.');
            $limitKey = 'wingman-ai:daily:' . $user->id;
            $limit = max(1, min(1000, (int) ($settings['daily_limit'] ?? 20)));
            // Cache::add + increment is shared by concurrent sessions and panel workers.
            Cache::add($limitKey, 0, now()->addDay());
            $count = Cache::increment($limitKey);
            $this->assert($count <= $limit, 'Your daily assistant limit has been reached. Try again tomorrow.');
            $run['phase'] = 'analyzing';
            $this->putRun($id, $run);

            return $run;
        });

        try {
            $safe = $this->normalizeCapture($capture, $settings);
            $safe['observations']['capture_mode'] = $run['mode'];
            $safe['observations']['power_action'] = $run['action'];
            $safe['observations']['initial_state'] = $run['initial_state'];
            $safe['observations']['capture_seconds'] = max(0, min(720, time() - $run['started_at']));

            return app(DiagnosisService::class)->diagnose($server, $user, $safe);
        } finally {
            $this->forgetRun($server, $user, $id);
        }
    }

    public function cancel(Server $server, User $user, string $id): void
    {
        $this->authorize($server, $user);
        Cache::lock($this->runKey($id) . ':lock', 15)->block(3, function () use ($server, $user, $id) {
            $run = $this->getRun($server, $user, $id);
            $this->assert($run['phase'] !== 'analyzing', 'The diagnosis is already being prepared.');
            $this->forgetRun($server, $user, $id);
        });
    }

    private function authorize(Server $server, User $user): void
    {
        abort_unless($user->can(SubuserPermission::WebsocketConnect, $server), 403);
    }

    private function getRun(Server $server, User $user, string $id): array
    {
        $this->assert((bool) preg_match('/^[a-f0-9-]{36}$/i', $id), 'This capture is invalid. Start a new diagnosis.');
        $run = Cache::get($this->runKey($id));
        $this->assert(is_array($run) && $run['user'] === $user->id && $run['server'] === $server->id && $run['expires_at'] > time(), 'This capture expired. Start a new diagnosis.');

        return $run;
    }

    private function normalizeCapture(array $capture, array $settings): array
    {
        $allowed = ['running', 'failed', 'timeout', 'connection_error', 'observed'];
        $logs = is_string($capture['logs'] ?? null) ? $capture['logs'] : '';
        $this->assert(strlen($logs) <= 262144, 'This capture is too large. Start a new diagnosis with a shorter log.');
        $observations = [];
        foreach (['log_truncated', 'saw_transition', 'oom_killed', 'peak_memory_bytes', 'peak_cpu_percent', 'disconnect_reason'] as $key) {
            $value = $capture['observations'][$key] ?? null;
            if (is_scalar($value)) {
                $observations[$key] = is_string($value) ? mb_substr($value, 0, 240) : $value;
            }
        }

        return [
            // DiagnosisService redacts before applying the final provider budget.
            'logs' => $logs,
            'outcome' => in_array($capture['outcome'] ?? null, $allowed, true) ? $capture['outcome'] : 'observed',
            'observed_state' => in_array($capture['observed_state'] ?? null, array_column(ContainerStatus::cases(), 'value'), true) ? $capture['observed_state'] : 'unknown',
            'observations' => $observations,
        ];
    }

    private function putRun(string $id, array $run): void
    {
        Cache::put($this->runKey($id), $run, now()->addSeconds(max(1, $run['expires_at'] - time())));
    }

    private function forgetRun(Server $server, User $user, string $id): void
    {
        Cache::forget($this->runKey($id));
        if (Cache::get($this->activeKey($server, $user)) === $id) {
            Cache::forget($this->activeKey($server, $user));
        }
    }

    private function activeKey(Server $server, User $user): string
    {
        return 'wingman-ai:active:' . $user->id . ':' . $server->id;
    }

    private function runKey(string $id): string
    {
        return 'wingman-ai:run:' . $id;
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw ValidationException::withMessages(['assistant' => $message]);
        }
    }
}
