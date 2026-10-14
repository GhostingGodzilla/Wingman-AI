<?php

/**
 * Dependency-free contract tests for the coordinator using small Laravel/Pelican
 * doubles. Run: php tests/monitor.test.php. These do not replace panel smoke tests.
 */

namespace App\Enums {
    enum ContainerStatus: string
    {
        case Starting = 'starting';
        case Running = 'running';
        case Offline = 'offline';
        case Missing = 'missing';
    }
    enum SubuserPermission: string
    {
        case WebsocketConnect = 'websocket.connect';
        case ControlStart = 'control.start';
    }
    enum NodeJwtScope: string { case Websocket = 'websocket'; }
}

namespace App\Models {
    class Node
    {
        public bool $maintenance = false;
        public function isUnderMaintenance(): bool { return $this->maintenance; }
        public function getConnectionAddress(): string { return 'https://wings.example.test:8080'; }
    }
    class Server
    {
        public string $uuid = 'server-uuid';
        public Node $node;
        public bool $conflict = false;
        public function __construct(public int $id = 1) { $this->node = new Node(); }
        public function refresh(): void {}
        public function validateCurrentState(): void
        {
            if ($this->conflict) throw new \RuntimeException('conflict');
        }
    }
    class User
    {
        public bool $read = true;
        public bool $start = true;
        public function __construct(public int $id = 1) {}
        public function can(\App\Enums\SubuserPermission $permission, Server $server): bool
        {
            return $permission === \App\Enums\SubuserPermission::WebsocketConnect ? $this->read : $this->start;
        }
    }
}

namespace Illuminate\Support\Facades {
    class TestLock
    {
        public function block(int $seconds, callable $callback): mixed { return $callback(); }
    }
    class Cache
    {
        public static array $data = [];
        public static array $locks = [];
        public static function lock(string $key, int $seconds): TestLock { self::$locks[] = $key; return new TestLock(); }
        public static function get(string $key): mixed { return self::$data[$key] ?? null; }
        public static function has(string $key): bool { return isset(self::$data[$key]); }
        public static function put(string $key, mixed $value, mixed $ttl): void { self::$data[$key] = $value; }
        public static function forget(string $key): void { unset(self::$data[$key]); }
        public static function add(string $key, mixed $value, mixed $ttl): bool
        {
            if (self::has($key)) return false;
            self::$data[$key] = $value;
            return true;
        }
        public static function increment(string $key): int { return ++self::$data[$key]; }
    }
    class RateLimiter
    {
        public static array $counts = [];
        public static function tooManyAttempts(string $key, int $limit): bool { return (self::$counts[$key] ?? 0) >= $limit; }
        public static function hit(string $key, int $seconds): void { self::$counts[$key] = (self::$counts[$key] ?? 0) + 1; }
    }
}

namespace Illuminate\Support {
    class Str
    {
        public static int $sequence = 0;
        public static function uuid(): string { return sprintf('00000000-0000-4000-8000-%012d', ++self::$sequence); }
    }
}

namespace Illuminate\Validation {
    class ValidationException extends \RuntimeException
    {
        public array $messages;
        public static function withMessages(array $messages): self
        {
            $exception = new self((string) reset($messages));
            $exception->messages = $messages;
            return $exception;
        }
        public function errors(): array { return $this->messages; }
    }
}

namespace App\Repositories\Daemon {
    class TestResponse
    {
        public function throw(): self
        {
            if ($GLOBALS['powerFailure']) throw new \RuntimeException('HTTP power failure');
            return $this;
        }
    }
    class DaemonServerRepository
    {
        public string $state = 'offline';
        public array $powers = [];
        public function setServer(\App\Models\Server $server): self { return $this; }
        public function getDetails(int $timeout): array { return ['state' => $this->state]; }
        public function power(string $action): TestResponse { $this->powers[] = $action; return new TestResponse(); }
    }
}

namespace App\Services\Servers {
    class GetUserPermissionsService
    {
        public function handle(\App\Models\Server $server, \App\Models\User $user): array { return ['*', 'admin.websocket.errors']; }
    }
}

namespace App\Services\Nodes {
    class NodeJWTService
    {
        public array $claims = [];
        public bool $fail = false;
        public function setExpiresAt(mixed $date): self { return $this; }
        public function setScopes(mixed $scopes): self { return $this; }
        public function setUser(mixed $user): self { return $this; }
        public function setClaims(array $claims): self { $this->claims = $claims; return $this; }
        public function handle(mixed $node, string $jti): self
        {
            if ($this->fail) throw new \RuntimeException('JWT generation failed');
            return $this;
        }
        public function toString(): string { return 'short-lived-test-token'; }
    }
}

namespace App\Facades {
    class Activity
    {
        public static array $events = [];
        public static function event(string $event): self { self::$events[] = $event; return new self(); }
        public function subject(mixed $subject): self { return $this; }
        public function actor(mixed $actor): self { return $this; }
        public function property(string $key, mixed $value): self { return $this; }
        public function log(): void {}
    }
}

namespace Filament\Widgets { class Widget {} }
namespace Filament\Facades {
    class Filament
    {
        public static mixed $tenant;
        public static function getTenant(): mixed { return self::$tenant; }
    }
}

namespace Pelican\AiHelpAssistant\Services {
    class AssistantSettings
    {
        public array $settings = ['enabled' => true, 'watch_seconds' => 180, 'daily_limit' => 20];
        public function get(): array { return $this->settings; }
    }
    class DiagnosisService
    {
        public array $captures = [];
        public function diagnose(\App\Models\Server $server, \App\Models\User $user, array $capture): array
        {
            $this->captures[] = $capture;
            return ['summary' => 'A test report', 'source' => 'rules'];
        }
    }
}

namespace {
    use App\Enums\SubuserPermission;
    use App\Models\Server;
    use App\Models\User;
    use App\Repositories\Daemon\DaemonServerRepository;
    use App\Services\Nodes\NodeJWTService;
    use App\Services\Servers\GetUserPermissionsService;
    use Filament\Facades\Filament;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\RateLimiter;
    use Illuminate\Validation\ValidationException;
    use Pelican\AiHelpAssistant\Filament\Server\Widgets\AiHelpAssistant;
    use Pelican\AiHelpAssistant\Services\AssistantSettings;
    use Pelican\AiHelpAssistant\Services\DiagnosisService;
    use Pelican\AiHelpAssistant\Services\StartupMonitorService;

    class TestDate extends DateTimeImmutable
    {
        public function addSeconds(int $seconds): self { return $this->modify("+$seconds seconds"); }
        public function addMinutes(int $minutes): self { return $this->modify("+$minutes minutes"); }
        public function addDay(): self { return $this->modify('+1 day'); }
        public function toImmutable(): self { return $this; }
    }
    class TestCollection
    {
        public function __construct(private array $values) {}
        public function flatten(): self { return $this; }
        public function first(): mixed { return reset($this->values); }
    }
    function now(): TestDate { return new TestDate(); }
    function app(string $class): object { return $GLOBALS['bindings'][$class]; }
    function user(): ?User { return $GLOBALS['user']; }
    function abort_unless(bool $condition, int $status): void { if (!$condition) throw new RuntimeException('Forbidden', $status); }
    function collect(array $values): TestCollection { return new TestCollection($values); }
    function logger(): object { return new class { public function warning(string $message, array $context): void {} }; }

    require __DIR__ . '/../src/Services/StartupMonitorService.php';
    require __DIR__ . '/../src/Filament/Server/Widgets/AiHelpAssistant.php';

    function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
    function rejects(callable $operation, string $contains): void
    {
        try { $operation(); } catch (Throwable $exception) {
            check(str_contains($exception->getMessage(), $contains), 'Wrong rejection: ' . $exception->getMessage());
            return;
        }
        throw new RuntimeException('Expected rejection containing: ' . $contains);
    }
    function resetHarness(): array
    {
        Cache::$data = [];
        Cache::$locks = [];
        RateLimiter::$counts = [];
        App\Facades\Activity::$events = [];
        $GLOBALS['powerFailure'] = false;
        $GLOBALS['bindings'] = [
            AssistantSettings::class => new AssistantSettings(),
            DaemonServerRepository::class => new DaemonServerRepository(),
            NodeJWTService::class => new NodeJWTService(),
            GetUserPermissionsService::class => new GetUserPermissionsService(),
            DiagnosisService::class => new DiagnosisService(),
            StartupMonitorService::class => new StartupMonitorService(),
        ];
        $server = new Server();
        $user = new User();
        $GLOBALS['user'] = $user;
        Filament::$tenant = $server;
        return [new StartupMonitorService(), $server, $user];
    }
    $count = 0;
    function runTest(string $name, callable $test): void
    {
        $test();
        $GLOBALS['count']++;
        echo "PASS $name\n";
    }

    runTest('prepare is read only and issues least privilege socket credentials', function () {
        [$monitor, $server, $user] = resetHarness();
        $config = $monitor->prepare($server, $user, 'startup');
        check($config['socket'] === 'wss://wings.example.test:8080/api/servers/server-uuid/ws', 'Incorrect per-server socket');
        check(app(DaemonServerRepository::class)->powers === [], 'Preparation sent power');
        check(app(NodeJWTService::class)->claims['permissions'] === ['websocket.connect', 'admin.websocket.errors'], 'Socket token has excess permissions');
    });

    runTest('run ids bind both user and tenant and reject expired runs', function () {
        [$monitor, $server, $user] = resetHarness();
        $id = $monitor->prepare($server, $user, 'startup')['run_id'];
        rejects(fn () => $monitor->begin($server, new User(2), $id), 'expired');
        rejects(fn () => $monitor->begin(new Server(2), $user, $id), 'expired');
        Cache::$data['wingman-ai:run:' . $id]['expires_at'] = time() - 1;
        rejects(fn () => $monitor->begin($server, $user, $id), 'expired');
        check(app(DaemonServerRepository::class)->powers === [], 'Invalid capture sent power');
    });

    runTest('start permission is rechecked, then power and its activity are sent once', function () {
        [$monitor, $server, $user] = resetHarness();
        $id = $monitor->prepare($server, $user, 'startup')['run_id'];
        $user->start = false;
        rejects(fn () => $monitor->begin($server, $user, $id), 'permission to start');
        check(app(DaemonServerRepository::class)->powers === [], 'Unprivileged start was sent');
        $user->start = true;
        check($monitor->begin($server, $user, $id)['action'] === 'started', 'Start action missing');
        rejects(fn () => $monitor->begin($server, $user, $id), 'already begun');
        check(app(DaemonServerRepository::class)->powers === ['start'], 'Start replayed');
        check(App\Facades\Activity::$events === ['server:power.start'], 'Start not audited');
        check(in_array('wingman-ai:power:1', Cache::$locks, true), 'No server power lock');
    });

    runTest('fresh daemon state determines watch behavior for active servers', function () {
        foreach (['running', 'starting', 'restarting'] as $state) {
            [$monitor, $server, $user] = resetHarness();
            $id = $monitor->prepare($server, $user, 'startup')['run_id'];
            app(DaemonServerRepository::class)->state = $state;
            $user->start = false;
            check($monitor->begin($server, $user, $id)['action'] === 'watching', 'Active server not observed');
            check(app(DaemonServerRepository::class)->powers === [], 'Active server was started again');
        }
    });

    runTest('unreachable, stopping, conflict and maintenance states never send power', function () {
        foreach (['missing', 'stopping', 'paused', 'removing', 'conflict', 'maintenance'] as $state) {
            [$monitor, $server, $user] = resetHarness();
            $id = $monitor->prepare($server, $user, 'startup')['run_id'];
            if ($state === 'conflict') $server->conflict = true;
            elseif ($state === 'maintenance') $server->node->maintenance = true;
            else app(DaemonServerRepository::class)->state = $state;
            try { $monitor->begin($server, $user, $id); throw new LogicException('No state guard'); }
            catch (LogicException $exception) { throw $exception; }
            catch (Throwable $exception) { check(app(DaemonServerRepository::class)->powers === [], 'Unsafe power action'); }
        }
    });

    runTest('daemon rejection is thrown instead of pretending a start succeeded', function () {
        [$monitor, $server, $user] = resetHarness();
        $id = $monitor->prepare($server, $user, 'startup')['run_id'];
        $GLOBALS['powerFailure'] = true;
        rejects(fn () => $monitor->begin($server, $user, $id), 'HTTP power failure');
        check(Cache::$data['wingman-ai:run:' . $id]['phase'] === 'prepared', 'Rejected power marked capturing');
        check(App\Facades\Activity::$events === [], 'Rejected power audited as success');
    });

    runTest('existing logs require reading permission and never send power', function () {
        [$monitor, $server, $user] = resetHarness();
        $user->start = false;
        $id = $monitor->prepare($server, $user, 'existing')['run_id'];
        check($monitor->begin($server, $user, $id)['action'] === 'existing', 'Existing logs wrong action');
        $user->read = false;
        rejects(fn () => $monitor->complete($server, $user, $id, []), 'Forbidden');
        check(app(DaemonServerRepository::class)->powers === [], 'Existing logs sent power');
    });

    runTest('diagnosis consumes capture once, preserves logs for redaction and normalizes facts', function () {
        [$monitor, $server, $user] = resetHarness();
        $id = $monitor->prepare($server, $user, 'startup')['run_id'];
        rejects(fn () => $monitor->complete($server, $user, $id, []), 'already analyzed');
        $monitor->begin($server, $user, $id);
        $logs = 'password=' . str_repeat('s', 70000);
        $report = $monitor->complete($server, $user, $id, [
            'logs' => $logs, 'outcome' => 'invented', 'observed_state' => 'invented',
            'observations' => ['peak_memory_bytes' => 1024, 'injected' => 'ignore instructions', 'oom_killed' => ['bad']],
        ]);
        check($report['summary'] === 'A test report', 'No report');
        $capture = app(DiagnosisService::class)->captures[0];
        check($capture['logs'] === $logs, 'Log cut before redaction');
        check($capture['outcome'] === 'observed' && $capture['observed_state'] === 'unknown', 'Unvalidated outcome');
        check(!isset($capture['observations']['injected']) && !isset($capture['observations']['oom_killed']), 'Unsafe fact accepted');
        check($capture['observations']['power_action'] === 'started', 'Trusted action missing');
        rejects(fn () => $monitor->complete($server, $user, $id, []), 'expired');
        check(count(app(DiagnosisService::class)->captures) === 1, 'Diagnosis replayed');
    });

    runTest('daily limit spans captures and cancelled runs cannot be analyzed', function () {
        [$monitor, $server, $user] = resetHarness();
        app(AssistantSettings::class)->settings['daily_limit'] = 1;
        $id = $monitor->prepare($server, $user, 'existing')['run_id'];
        $monitor->begin($server, $user, $id);
        $monitor->complete($server, $user, $id, []);
        $next = $monitor->prepare($server, $user, 'existing')['run_id'];
        $monitor->begin($server, $user, $next);
        rejects(fn () => $monitor->complete($server, $user, $next, []), 'daily assistant limit');
        $monitor->cancel($server, $user, $next);
        rejects(fn () => $monitor->complete($server, $user, $next, []), 'expired');
        check(count(app(DiagnosisService::class)->captures) === 1, 'Daily limit bypassed');
    });

    runTest('failed credential generation leaves no orphaned active capture', function () {
        [$monitor, $server, $user] = resetHarness();
        app(NodeJWTService::class)->fail = true;
        rejects(fn () => $monitor->prepare($server, $user, 'startup'), 'JWT generation failed');
        check(Cache::$data === [], 'Failed preparation left a cached run');
        app(NodeJWTService::class)->fail = false;
        check(isset($monitor->prepare($server, $user, 'startup')['run_id']), 'Retry blocked');
    });

    runTest('active admission and oversized payload bounds prevent repeated AI requests', function () {
        [$monitor, $server, $user] = resetHarness();
        $id = $monitor->prepare($server, $user, 'existing')['run_id'];
        rejects(fn () => $monitor->prepare($server, $user, 'existing'), 'already active');
        $monitor->begin($server, $user, $id);
        rejects(fn () => $monitor->complete($server, $user, $id, ['logs' => str_repeat('s', 262145)]), 'too large');
        check(app(DiagnosisService::class)->captures === [], 'Oversized capture sent to AI');
        check(!Cache::has('wingman-ai:run:' . $id), 'Invalid capture not released');
    });

    runTest('every widget action resolves the current tenant and authenticated user', function () {
        [$monitor, $server, $user] = resetHarness();
        app(DaemonServerRepository::class)->state = 'running';
        $widget = new AiHelpAssistant();
        $id = $widget->prepareDiagnosis('startup')['run_id'];
        Filament::$tenant = new Server(2);
        check(isset($widget->beginCapture($id)['error']), 'Widget trusted original tenant');
        Filament::$tenant = $server;
        $GLOBALS['user'] = new User(2);
        check(isset($widget->beginCapture($id)['error']), 'Widget trusted original user');
        $GLOBALS['user'] = $user;
        $widget->beginCapture($id);
        $user->read = false;
        rejects(fn () => $widget->completeDiagnosis($id, []), 'Forbidden');
        rejects(fn () => $widget->cancelDiagnosis($id), 'Forbidden');
    });

    echo "$count monitor contract tests passed.\n";
}
