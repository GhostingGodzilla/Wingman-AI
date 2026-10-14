<?php

declare(strict_types=1);

namespace Illuminate\Support\Facades {
    class Cache
    {
        public static array $values = [];
        public static function get(string $key, mixed $default = null): mixed { return self::$values[$key] ?? $default; }
        public static function has(string $key): bool { return array_key_exists($key, self::$values); }
        public static function put(string $key, mixed $value, mixed $ttl = null): void { self::$values[$key] = $value; }
        public static function forget(string $key): void { unset(self::$values[$key]); }
        public static function add(string $key, mixed $value, mixed $ttl): bool
        {
            if (self::has($key)) { return false; }
            self::put($key, $value, $ttl);
            return true;
        }
        public static function increment(string $key): int { return ++self::$values[$key]; }
        public static function lock(string $key, int $seconds): object
        {
            return new class {
                public function block(int $seconds, callable $callback): mixed { return $callback(); }
            };
        }
    }

    class RateLimiter
    {
        public static function tooManyAttempts(string $key, int $limit): bool { return false; }
        public static function hit(string $key, int $seconds): void {}
    }
}

namespace Illuminate\Support {
    class Str
    {
        public static function uuid(): string { return '11111111-1111-1111-1111-111111111111'; }
    }
}

namespace App\Repositories\Daemon {
    class DaemonServerRepository
    {
        public ?\App\Models\Server $server = null;
        public string $state = 'offline';
        public mixed $rows = ['Error: Unable to access jarfile server.jar'];
        public array $requests = [];
        public array $powerCalls = [];

        public function setServer(\App\Models\Server $server): self { $this->server = $server; return $this; }
        public function getDetails(int $timeout): array { return ['state' => $this->state]; }
        public function power(string $action): never
        {
            $this->powerCalls[] = $action;
            throw new \RuntimeException('History must never send power.');
        }
        public function getHttpClient(): object
        {
            return new class($this) {
                public function __construct(private DaemonServerRepository $repository) {}
                public function connectTimeout(int $seconds): self { return $this; }
                public function timeout(int $seconds): self { return $this; }
                public function get(string $path, array $query): self
                {
                    $this->repository->requests[] = [$path, $query];
                    return $this;
                }
                public function throw(): self { return $this; }
                public function json(string $key): mixed { return $key === 'data' ? $this->repository->rows : null; }
            };
        }
    }
}

namespace Pelican\AiHelpAssistant\Services {
    class AssistantSettings
    {
        public array $settings = ['enabled' => true, 'watch_seconds' => 180, 'daily_limit' => 20, 'max_log_bytes' => 48000];
        public function get(): array { return $this->settings; }
    }

    class DiagnosisService
    {
        public array $captures = [];
        public function diagnose(\App\Models\Server $server, \App\Models\User $user, array $capture): array
        {
            $this->captures[] = $capture;
            return ['summary' => 'Historical console diagnosis', 'source' => 'rules'];
        }
    }
}

namespace {
    require __DIR__ . '/integration/framework-stubs.php';

    use App\Models\Server;
    use App\Models\User;
    use App\Repositories\Daemon\DaemonServerRepository;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Validation\ValidationException;
    use Pelican\AiHelpAssistant\Services\AssistantSettings;
    use Pelican\AiHelpAssistant\Services\DiagnosisService;
    use Pelican\AiHelpAssistant\Services\StartupMonitorService;

    class HistoryServer extends Server
    {
        public object $node;
        public function __construct()
        {
            parent::__construct();
            $this->node = new class {
                public function isUnderMaintenance(): bool { return false; }
            };
        }
        public function refresh(): self { return $this; }
        public function validateCurrentState(): void {}
    }

    class HistoryUser extends User
    {
        public int $id = 7;
    }

    function app(string $class): object
    {
        return $GLOBALS['history_services'][$class] ?? throw new RuntimeException('History requested an unexpected dependency: ' . $class);
    }

    function now(): object
    {
        return new class {
            public function addSeconds(int $seconds): self { return $this; }
            public function addDay(): self { return $this; }
        };
    }

    require dirname(__DIR__) . '/src/Services/StartupMonitorService.php';

    function historyExpect(bool $condition, string $message): void
    {
        if (!$condition) { throw new RuntimeException($message); }
    }

    function historyThrows(callable $operation, string $class): void
    {
        try { $operation(); }
        catch (Throwable $error) {
            historyExpect($error instanceof $class, 'Unexpected error type: ' . $error::class);
            return;
        }
        throw new RuntimeException('Expected request rejection.');
    }

    function historyFixture(): array
    {
        Cache::$values = [];
        $repository = new DaemonServerRepository();
        $diagnosis = new DiagnosisService();
        $GLOBALS['history_services'] = [DaemonServerRepository::class => $repository, DiagnosisService::class => $diagnosis, AssistantSettings::class => new AssistantSettings()];

        return [new StartupMonitorService(), new HistoryServer(), new HistoryUser(['websocket.connect']), $repository, $diagnosis];
    }

    $tests = [];
    $tests['offline existing history uses bound authorized Wings REST with no browser JWT or power'] = static function (): void {
        [$service, $server, $user, $repository, $diagnosis] = historyFixture();
        $config = $service->prepare($server, $user, 'existing');
        historyExpect(!isset($config['socket'], $config['token']), 'History generated browser socket credentials.');
        $info = $service->begin($server, $user, $config['run_id']);
        historyExpect($info['action'] === 'existing' && $info['initial_state'] === 'offline', 'Offline history was not admitted.');
        historyExpect(str_contains($info['logs'], 'Unable to access jarfile'), 'Wings REST history was lost.');
        historyExpect($repository->requests === [['/api/servers/' . $server->uuid . '/logs', ['size' => 100]]], 'History request targeted the wrong server or endpoint.');
        historyExpect($repository->powerCalls === [], 'Existing history sent a power action.');
        historyExpect(!str_contains(json_encode(Cache::$values), 'Unable to access jarfile'), 'Raw console history was persisted in capture cache.');
        $service->complete($server, $user, $config['run_id'], ['logs' => $info['logs'], 'outcome' => 'observed', 'observed_state' => 'offline']);
        historyExpect($diagnosis->captures[0]['observations']['capture_mode'] === 'existing', 'Historical evidence was not labeled as existing capture.');
    };

    $tests['existing history rechecks console permission run ownership and run phase before daemon access'] = static function (): void {
        [$service, $server, $user, $repository] = historyFixture();
        $config = $service->prepare($server, $user, 'existing');
        $other = new HistoryUser(['websocket.connect']);
        $other->id = 8;
        historyThrows(fn () => $service->begin($server, $other, $config['run_id']), ValidationException::class);
        historyExpect($repository->requests === [], 'Another user could read the history run.');
        $user->permissions = [];
        historyThrows(fn () => $service->begin($server, $user, $config['run_id']), IntegrationAbortException::class);
        historyExpect($repository->requests === [], 'Revoked console permission still read history.');
        $user->permissions = ['websocket.connect'];
        $service->begin($server, $user, $config['run_id']);
        historyThrows(fn () => $service->begin($server, $user, $config['run_id']), ValidationException::class);
        historyExpect(count($repository->requests) === 1, 'A consumed run fetched history twice.');
    };

    $tests['existing history bounds complete lines and drops oversized credential lines intact'] = static function (): void {
        [$service, $server, $user, $repository] = historyFixture();
        $repository->rows = ['password=' . str_repeat('s', 262145), str_repeat("earlier line\n", 22000), 'Useful recent error'];
        $config = $service->prepare($server, $user, 'existing');
        $info = $service->begin($server, $user, $config['run_id']);
        historyExpect(strlen($info['logs']) <= 262144 && $info['log_truncated'] === true, 'Backend history exceeded its budget.');
        historyExpect(!str_contains($info['logs'], 'password=') && !str_contains($info['logs'], str_repeat('s', 100)), 'Truncation retained a partial credential line.');
        historyExpect(str_ends_with($info['logs'], 'Useful recent error'), 'The newest complete error line was lost.');
    };

    $tests['invalid REST history fails without fabricated output or a power action'] = static function (): void {
        [$service, $server, $user, $repository] = historyFixture();
        $repository->rows = null;
        $config = $service->prepare($server, $user, 'existing');
        historyThrows(fn () => $service->begin($server, $user, $config['run_id']), ValidationException::class);
        historyExpect($repository->powerCalls === [], 'Unavailable history caused a power action.');
        $service->cancel($server, $user, $config['run_id']);
        historyExpect(!Cache::has('wingman-ai:run:' . $config['run_id']), 'Failed history run could not be released.');
    };

    $failed = 0;
    foreach ($tests as $name => $test) {
        try { $test(); fwrite(STDOUT, 'PASS ' . $name . PHP_EOL); }
        catch (Throwable $error) { $failed++; fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL); }
    }
    fwrite(STDOUT, count($tests) . ' manual history scenarios, ' . $failed . ' failures.' . PHP_EOL);
    exit($failed > 0 ? 1 : 0);
}
