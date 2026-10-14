<?php

/** Run in a separate process: php tests/automatic.test.php. No panel installation needed. */
namespace App\Enums {
    enum SubuserPermission: string { case WebsocketConnect = 'websocket.connect'; case StartupRead = 'startup.read'; }
}
namespace App\Http\Controllers\Api\Remote\Servers { class ServerContainersController {} }
namespace App\Models {
    class Node
    {
        public bool $maintenance = false;
        public string $daemon_token = 'private-node-test-credential';
        public function isUnderMaintenance(): bool { return $this->maintenance; }
        public function is(mixed $node): bool { return $node === $this; }
        public function getConnectionAddress(): string { return 'https://wings.example.test:8080'; }
    }
    class User
    {
        public bool $console = true;
        public bool $startup = true;
        public function __construct(public int $id = 1) {}
        public function can(\App\Enums\SubuserPermission $permission, Server $server): bool
        {
            return $permission === \App\Enums\SubuserPermission::WebsocketConnect ? $this->console : $this->startup;
        }
    }
    class Server
    {
        public int $owner_id = 1;
        public string $uuid = 'test-server-uuid';
        public bool $conflict = false;
        public Node $node;
        public User $owner;
        public function __construct(public int $id = 1) { $this->node = new Node(); $this->owner = new User(); }
        public function refresh(): void {}
        public function isInConflictState(): bool { return $this->conflict; }
        public function user(): object { return new class($this->owner) { public function __construct(private User $owner) {} public function first(): User { return $this->owner; } }; }
        public static function query(): Query { return new Query(); }
    }
    class Query
    {
        private ?string $uuid = null;
        public function where(string $key, string $value): self { $this->uuid = $value; return $this; }
        public function first(): ?Server { foreach ($GLOBALS['servers'] as $server) if ($server->uuid === $this->uuid) return $server; return null; }
        public function find(int $id): ?Server { return $GLOBALS['servers'][$id] ?? null; }
    }
}
namespace Illuminate\Support\Facades {
    class Lock
    {
        public function __construct(private string $key) {}
        public function block(int $seconds, callable $callback): mixed { return $callback(); }
        public function get(callable $callback): mixed
        {
            if (!empty(Cache::$held[$this->key])) return false;
            Cache::$held[$this->key] = true;
            try { return $callback(); } finally { unset(Cache::$held[$this->key]); }
        }
    }
    class Cache
    {
        public static array $data = [];
        public static array $held = [];
        public static function lock(string $key, int $seconds): Lock { return new Lock($key); }
        public static function get(string $key, mixed $default = null): mixed { return self::$data[$key] ?? $default; }
        public static function has(string $key): bool { return isset(self::$data[$key]); }
        public static function put(string $key, mixed $value, mixed $ttl): void { self::$data[$key] = $value; }
        public static function forget(string $key): void { unset(self::$data[$key]); }
        public static function add(string $key, mixed $value, mixed $ttl): bool { if (self::has($key)) return false; self::$data[$key] = $value; return true; }
        public static function increment(string $key): int { return ++self::$data[$key]; }
    }
    class Bus
    {
        public static array $jobs = [];
        public static bool $fail = false;
        public static function dispatch(object $job): void
        {
            if (self::$fail) throw new \RuntimeException('queue unavailable');
            self::$jobs[] = $job;
        }
    }
    class Crypt
    {
        // Test double verifies encryption/decryption boundaries, not cryptography.
        public static function encryptString(string $text): string { return 'encrypted:' . base64_encode($text); }
        public static function decryptString(string $text): string
        {
            if (!str_starts_with($text, 'encrypted:')) throw new \RuntimeException('corrupt ciphertext');
            return base64_decode(substr($text, 10), true);
        }
    }
}
namespace Illuminate\Support {
    class Str { public static int $id = 0; public static function uuid(): string { return 'generation-' . ++self::$id; } }
}
namespace Illuminate\Bus { trait Queueable { public mixed $delay = null; public function delay(mixed $delay): self { $this->delay = $delay; return $this; } } }
namespace Illuminate\Contracts\Queue { interface ShouldQueue {} }
namespace Illuminate\Foundation\Bus { trait Dispatchable {} }
namespace Illuminate\Queue { trait InteractsWithQueue {} }
namespace Illuminate\Foundation\Http\Events { class RequestHandled { public function __construct(public object $request, public object $response) {} } }
namespace Illuminate\Http\Client\Events { class RequestSending { public function __construct(public object $request) {} } }
namespace App\Repositories\Daemon {
    class Response
    {
        public function throw(): self { return $this; }
        public function json(string $key): array { return $GLOBALS['rows']; }
    }
    class Client
    {
        public function connectTimeout(int $seconds): self { return $this; }
        public function timeout(int $seconds): self { return $this; }
        public function get(string $url, array $query): Response
        {
            $GLOBALS['logRequests'][] = [$url, $query];
            if ($GLOBALS['logFailure']) throw new \RuntimeException('sensitive daemon error');
            return new Response();
        }
    }
    class DaemonServerRepository
    {
        public string $state = 'starting';
        public int $requests = 0;
        public function setServer(\App\Models\Server $server): self { return $this; }
        public function getDetails(int $timeout): array
        {
            $this->requests++;
            if (is_callable($GLOBALS['onSnapshot'])) ($GLOBALS['onSnapshot'])();
            return ['state' => $this->state];
        }
        public function getHttpClient(): Client { return new Client(); }
        public function power(string $action): never { throw new \LogicException('Automatic monitor must never send power'); }
    }
}
namespace Pelican\AiHelpAssistant\Services {
    class AssistantSettings
    {
        public array $settings = ['enabled' => true, 'automatic_enabled' => true, 'watch_seconds' => 30, 'auto_daily_limit' => 20, 'auto_cooldown_seconds' => 300];
        public function get(): array { return $this->settings; }
    }
    class DiagnosisService
    {
        public array $captures = [];
        public function diagnose(\App\Models\Server $server, \App\Models\User $owner, array $capture): array
        {
            $this->captures[] = $capture;
            if (is_callable($GLOBALS['onDiagnosis'])) ($GLOBALS['onDiagnosis'])();
            return ['summary' => 'Redacted diagnosis', 'source' => 'rules', 'steps' => ['Review settings.']];
        }
    }
}
namespace {
    use App\Models\Server;
    use App\Models\User;
    use App\Repositories\Daemon\DaemonServerRepository;
    use Illuminate\Foundation\Http\Events\RequestHandled;
    use Illuminate\Http\Client\Events\RequestSending;
    use Illuminate\Support\Facades\Bus;
    use Illuminate\Support\Facades\Cache;
    use Pelican\AiHelpAssistant\Listeners\ObserveContainerStatus;
    use Pelican\AiHelpAssistant\Listeners\ObservePowerRequest;
    use Pelican\AiHelpAssistant\Services\AssistantSettings;
    use Pelican\AiHelpAssistant\Services\AutomaticStartupService;
    use Pelican\AiHelpAssistant\Services\DiagnosisService;

    class Date extends DateTimeImmutable { public function addSeconds(int $seconds): self { return $this->modify("+$seconds seconds"); } }
    function now(): Date { return new Date(); }
    function app(string $class): object { return $GLOBALS['bindings'][$class]; }
    function config(string $key): mixed { return $GLOBALS['config'][$key] ?? null; }
    function abort_unless(bool $condition, int $code): void { if (!$condition) throw new RuntimeException('Forbidden', $code); }
    function logger(): object { return new class { public function warning(string $message, array $context): void {} }; }

    require __DIR__ . '/../src/Support/AutomaticStartupState.php';
    require __DIR__ . '/../src/Jobs/CheckAutomaticStartup.php';
    require __DIR__ . '/../src/Services/AutomaticStartupService.php';
    require __DIR__ . '/../src/Listeners/ObserveContainerStatus.php';
    require __DIR__ . '/../src/Listeners/ObservePowerRequest.php';

    class Monitor extends AutomaticStartupService
    {
        public float $time = 1000;
        public bool $installedEnabled = true;
        protected function clock(): float { return $this->time; }
        protected function pluginEnabled(): bool { return $this->installedEnabled; }
    }
    function check(bool $pass, string $message): void { if (!$pass) throw new RuntimeException($message); }
    function reject(callable $call): void
    {
        try { $call(); } catch (Throwable $error) { check($error->getCode() === 403, 'Wrong access rejection'); return; }
        throw new RuntimeException('Expected report access rejection');
    }
    function resetHarness(): array
    {
        Cache::$data = [];
        Cache::$held = [];
        Bus::$jobs = [];
        Bus::$fail = false;
        $GLOBALS['config'] = ['queue.default' => 'database', 'queue.connections.database.driver' => 'database'];
        $GLOBALS['rows'] = ['Missing executable'];
        $GLOBALS['logRequests'] = [];
        $GLOBALS['logFailure'] = false;
        $GLOBALS['onSnapshot'] = null;
        $GLOBALS['onDiagnosis'] = null;
        $monitor = new Monitor();
        $server = new Server();
        $GLOBALS['servers'] = [1 => $server];
        $GLOBALS['bindings'] = [AutomaticStartupService::class => $monitor, AssistantSettings::class => new AssistantSettings(), DaemonServerRepository::class => new DaemonServerRepository(), DiagnosisService::class => new DiagnosisService()];
        return [$monitor, $server, $server->owner];
    }
    function begin(Monitor $monitor, Server $server): string
    {
        $monitor->record($server, 'offline', 'starting');
        return Cache::get('wingman:auto:state:1')['generation'];
    }
    function failAttempt(Monitor $monitor, Server $server): string
    {
        $generation = begin($monitor, $server);
        $monitor->time += 2;
        $monitor->record($server, 'starting', 'offline');
        app(DaemonServerRepository::class)->state = 'offline';
        $monitor->time += 8;
        $monitor->check(1, $generation);
        return $generation;
    }
    $count = 0;
    function test(string $name, callable $call): void { $call(); $GLOBALS['count']++; echo "PASS $name\n"; }

    test('callback records state and queues identifiers without network or AI', function () {
        [$monitor, $server] = resetHarness();
        $generation = begin($monitor, $server);
        $monitor->record($server, 'offline', 'starting');
        check(count(Bus::$jobs) === 1, 'Duplicate callback queued extra checks');
        check(Bus::$jobs[0]->serverId === 1 && Bus::$jobs[0]->generation === $generation, 'Queue identifiers incorrect');
        check(app(DaemonServerRepository::class)->requests === 0 && $GLOBALS['logRequests'] === [] && app(DiagnosisService::class)->captures === [], 'Callback performed I/O');
    });

    test('power intent preserves deadline across duplicates and authentic starting keeps its generation', function () {
        [$monitor, $server] = resetHarness();
        $monitor->attempt($server);
        $first = Cache::get('wingman:auto:state:1');
        $monitor->time = 1005;
        $monitor->attempt($server);
        $duplicate = Cache::get('wingman:auto:state:1');
        check($first['generation'] === $duplicate['generation'] && $first['started_at'] === $duplicate['started_at'], 'Duplicate intent reset deadline');
        check(!empty($duplicate['awaiting_transition']) && count(Bus::$jobs) === 1, 'Intent sent duplicate job or fabricated transition');
        $monitor->record($server, 'offline', 'starting');
        $confirmed = Cache::get('wingman:auto:state:1');
        check($confirmed['generation'] === $first['generation'] && empty($confirmed['awaiting_transition']), 'Authentic transition rotated intent');
        check(app(DaemonServerRepository::class)->requests === 0 && app(DiagnosisService::class)->captures === [], 'Intent performed I/O');
    });

    test('offline preboot rejection remains pending until an inconclusive timeout', function () {
        [$monitor, $server] = resetHarness();
        $monitor->attempt($server);
        $generation = Cache::get('wingman:auto:state:1')['generation'];
        app(DaemonServerRepository::class)->state = 'offline';
        $monitor->time = 1010;
        $monitor->check(1, $generation);
        check(app(DiagnosisService::class)->captures === [], 'Preboot offline incorrectly classified crash');
        $monitor->time = 1030;
        $monitor->check(1, $generation);
        $capture = app(DiagnosisService::class)->captures[0];
        check($capture['outcome'] === 'timeout' && $capture['observations']['startup_transition_confirmed'] === false, 'Unconfirmed start did not remain inconclusive');
        check($capture['observations']['initial_state'] === 'unknown' && $capture['observations']['start_requested'] === true, 'Intent evidence missing or claimed actual starting');
    });

    test('pending restart cannot treat its old running process as a healthy new start', function () {
        [$monitor, $server] = resetHarness();
        $monitor->attempt($server, 'restart');
        $generation = Cache::get('wingman:auto:state:1')['generation'];
        app(DaemonServerRepository::class)->state = 'running';
        $monitor->time = 1010;
        $monitor->check(1, $generation);
        $monitor->time = 1030;
        $monitor->check(1, $generation);
        check(app(DiagnosisService::class)->captures[0]['outcome'] === 'timeout', 'Old restart process marked healthy');
    });

    test('restart shutdown remains pending then actual startup settles quietly', function () {
        [$monitor, $server, $owner] = resetHarness();
        $monitor->attempt($server, 'restart');
        $generation = Cache::get('wingman:auto:state:1')['generation'];
        $monitor->time = 1001;
        $monitor->record($server, 'running', 'stopping');
        $monitor->record($server, 'stopping', 'offline');
        app(DaemonServerRepository::class)->state = 'offline';
        $monitor->time = 1010;
        $monitor->check(1, $generation);
        check($monitor->status($server, $owner)['status'] === 'monitoring', 'Restart shutdown cancelled pending restart');
        $monitor->time = 1011;
        $monitor->record($server, 'offline', 'starting');
        $monitor->time = 1012;
        $monitor->record($server, 'starting', 'running');
        app(DaemonServerRepository::class)->state = 'running';
        $monitor->time = 1020;
        $monitor->check(1, $generation);
        $monitor->time = 1040;
        $monitor->check(1, $generation);
        check($monitor->status($server, $owner)['status'] === 'running' && app(DiagnosisService::class)->captures === [], 'Confirmed restart did not settle quietly');
    });

    test('stable running finishes quietly without logs or AI', function () {
        [$monitor, $server, $owner] = resetHarness();
        $generation = begin($monitor, $server);
        app(DaemonServerRepository::class)->state = 'running';
        $monitor->time = 1010;
        $monitor->check(1, $generation);
        $monitor->time = 1030;
        $monitor->check(1, $generation);
        check($monitor->status($server, $owner)['status'] === 'running', 'Healthy startup not settled');
        check(app(DiagnosisService::class)->captures === [] && $GLOBALS['logRequests'] === [], 'Healthy run used provider/logs');
    });

    test('confirmed failure is diagnosed once and only encrypted redacted report is cached', function () {
        [$monitor, $server, $owner] = resetHarness();
        $generation = failAttempt($monitor, $server);
        $monitor->check(1, $generation);
        check(count(app(DiagnosisService::class)->captures) === 1, 'Failure duplicate or missing');
        $capture = app(DiagnosisService::class)->captures[0];
        check($capture['outcome'] === 'failed' && $capture['observations']['logs_may_include_previous_run'] === true, 'Historical capture attribution missing');
        check($GLOBALS['logRequests'][0] === ['/api/servers/test-server-uuid/logs', ['size' => 100]], 'Wrong Wings log request');
        $report = $monitor->latest($server, $owner);
        check($report['outcome'] === 'failed' && isset($report['generated_at'], $report['attempt_started_at']), 'Report metadata missing');
        check(str_starts_with(Cache::get('wingman:auto:report:1'), 'encrypted:'), 'Report not encrypted');
        check(!str_contains(json_encode(Cache::$data), 'Missing executable'), 'Raw console data was cached');
    });

    test('startup timeout is inconclusive and missing logs are explicit', function () {
        [$monitor, $server] = resetHarness();
        $generation = begin($monitor, $server);
        $GLOBALS['logFailure'] = true;
        $monitor->time = 1030;
        $monitor->check(1, $generation);
        $capture = app(DiagnosisService::class)->captures[0];
        check($capture['outcome'] === 'timeout' && $capture['observations']['logs_available'] === false && $capture['logs'] === '', 'Timeout claimed failure or fabricated logs');
    });

    test('explicit normal stop cancels startup monitoring without diagnosis', function () {
        [$monitor, $server, $owner] = resetHarness();
        $generation = begin($monitor, $server);
        $monitor->time = 1001;
        $monitor->record($server, 'starting', 'stopping');
        $monitor->record($server, 'stopping', 'offline');
        $monitor->check(1, $generation);
        check($monitor->status($server, $owner)['status'] === 'idle', 'Normal stop shown as failure');
        check(app(DiagnosisService::class)->captures === [], 'Normal stop diagnosed');
    });

    test('fresh daemon state overrides out-of-order offline callback hints', function () {
        [$monitor, $server] = resetHarness();
        $generation = begin($monitor, $server);
        $monitor->time = 1001;
        $monitor->record($server, 'starting', 'offline');
        app(DaemonServerRepository::class)->state = 'running';
        $monitor->time = 1010;
        $monitor->check(1, $generation);
        check(app(DiagnosisService::class)->captures === [], 'Healthy daemon diagnosed from stale hint');
    });

    test('stale generations and delayed jobs do not fetch history or invoke AI', function () {
        [$monitor, $server] = resetHarness();
        $generation = begin($monitor, $server);
        $monitor->time = 1001;
        $monitor->record($server, 'starting', 'stopping');
        $monitor->time = 1002;
        $current = begin($monitor, $server);
        check($generation !== $current, 'New attempt did not rotate generation');
        $monitor->check(1, $generation);
        check(app(DaemonServerRepository::class)->requests === 0, 'Stale generation queried daemon');
        $monitor->time = 1200;
        $monitor->check(1, $current);
        check(app(DaemonServerRepository::class)->requests === 0 && app(DiagnosisService::class)->captures === [], 'Late queued job analyzed old attempt');
    });

    test('ownership, maintenance, conflict, settings and plugin Disable are rechecked', function () {
        foreach (['owner', 'maintenance', 'conflict', 'setting', 'plugin'] as $guard) {
            [$monitor, $server] = resetHarness();
            $generation = begin($monitor, $server);
            if ($guard === 'owner') $server->owner_id = 2;
            if ($guard === 'maintenance') $server->node->maintenance = true;
            if ($guard === 'conflict') $server->conflict = true;
            if ($guard === 'setting') app(AssistantSettings::class)->settings['automatic_enabled'] = false;
            if ($guard === 'plugin') $monitor->installedEnabled = false;
            $monitor->check(1, $generation);
            check(app(DaemonServerRepository::class)->requests === 0 && app(DiagnosisService::class)->captures === [], 'Execution guard failed: ' . $guard);
        }
    });

    test('sync/null queues cannot execute delayed jobs recursively', function () {
        foreach (['sync', 'null', null] as $connection) {
            [$monitor, $server, $owner] = resetHarness();
            $GLOBALS['config']['queue.default'] = $connection;
            begin($monitor, $server);
            check(Bus::$jobs === [], 'Unsupported queue dispatched inline');
            check($monitor->status($server, $owner)['reason'] === 'requires_queue', 'Queue setup issue missing');
        }
        [$monitor, $server, $owner] = resetHarness();
        Bus::$fail = true;
        begin($monitor, $server);
        check($monitor->status($server, $owner)['reason'] === 'queue_unavailable', 'Queue failure not retained safely');
        foreach (['deferred', 'background', 'failover'] as $driver) {
            [$monitor, $server, $owner] = resetHarness();
            $GLOBALS['config']['queue.connections.database.driver'] = $driver;
            begin($monitor, $server);
            check(Bus::$jobs === [] && $monitor->status($server, $owner)['reason'] === 'requires_queue', 'Unsafe queue driver accepted: ' . $driver);
        }
    });

    test('cooldown and daily limits bound automatic provider use across crash loops', function () {
        [$monitor, $server] = resetHarness();
        failAttempt($monitor, $server);
        $monitor->time = 1020;
        failAttempt($monitor, $server);
        check(count(app(DiagnosisService::class)->captures) === 1, 'Crash-loop cooldown bypassed');
        Cache::forget('wingman:auto:cooldown:1');
        app(AssistantSettings::class)->settings['auto_daily_limit'] = 1;
        $monitor->time = 1040;
        failAttempt($monitor, $server);
        check(count(app(DiagnosisService::class)->captures) === 1, 'Per-server daily limit bypassed');
    });

    test('new generation during provider call prevents stale report publication', function () {
        [$monitor, $server, $owner] = resetHarness();
        $GLOBALS['onDiagnosis'] = function () use ($monitor, $server) {
            $monitor->time += 1;
            begin($monitor, $server);
        };
        failAttempt($monitor, $server);
        check($monitor->latest($server, $owner) === null, 'Stale failure saved over new attempt');
    });

    test('previous report survives callback retries and clears only after healthy confirmation', function () {
        [$monitor, $server, $owner] = resetHarness();
        failAttempt($monitor, $server);
        check($monitor->latest($server, $owner) !== null, 'Failure report missing');
        $monitor->time = 1020;
        $generation = begin($monitor, $server);
        check($monitor->latest($server, $owner) !== null, 'Callback erased confirmed report');
        app(DaemonServerRepository::class)->state = 'running';
        $monitor->time = 1030;
        $monitor->check(1, $generation);
        $monitor->time = 1050;
        $monitor->check(1, $generation);
        check($monitor->latest($server, $owner) === null, 'Old failure remains after healthy startup');
    });

    test('automatic report access requires console and startup permission and current owner', function () {
        [$monitor, $server, $owner] = resetHarness();
        failAttempt($monitor, $server);
        $reader = new User(9);
        $reader->startup = false;
        reject(fn () => $monitor->latest($server, $reader));
        reject(fn () => $monitor->status($server, $reader));
        $reader->startup = true;
        $reader->console = false;
        reject(fn () => $monitor->latest($server, $reader));
        $server->owner_id = 2;
        check($monitor->latest($server, $owner) === null, 'Previous owner report disclosed after ownership changed');
        check($monitor->status($server, $owner)['latest_timestamp'] === null, 'Previous owner metadata disclosed');
    });

    test('oversized lines are omitted whole before redaction; payload remains bounded', function () {
        [$monitor, $server] = resetHarness();
        $GLOBALS['rows'] = ['password=' . str_repeat('secret', 50000), 'Useful recent error'];
        failAttempt($monitor, $server);
        $capture = app(DiagnosisService::class)->captures[0];
        check($capture['logs'] === 'Useful recent error' && $capture['observations']['log_truncated'] === true, 'Line truncation orphaned secret label');
    });

    class Request
    {
        public object $attributes;
        public string $action = App\Http\Controllers\Api\Remote\Servers\ServerContainersController::class . '@status';
        public string $method = 'POST';
        public array $data = ['data.previous_state' => 'offline', 'data.new_state' => 'starting'];
        public function __construct(public Server $server, public mixed $node)
        {
            $this->attributes = new class($node) { public function __construct(private mixed $node) {} public function get(string $key): mixed { return $this->node; } };
        }
        public function route(?string $key = null): mixed
        {
            if ($key === 'server') return $this->server;
            return new class($this->action) { public function __construct(private string $action) {} public function getActionName(): string { return $this->action; } };
        }
        public function isMethod(string $method): bool { return $this->method === $method; }
        public function json(string $key): mixed { return $this->data[$key] ?? null; }
    }
    class HttpResponse { public function __construct(private int $status = 200) {} public function getStatusCode(): int { return $this->status; } }

    class OutgoingRequest
    {
        public string $verb = 'POST';
        public array $payload = ['action' => 'start'];
        public array $authorization = ['Bearer private-node-test-credential'];
        public function __construct(public string $address) {}
        public function method(): string { return $this->verb; }
        public function url(): string { return $this->address; }
        public function data(): array { return $this->payload; }
        public function header(string $name): array { return $this->authorization; }
    }

    test('outgoing API and scheduled power intent require exact node URL and daemon credentials', function () {
        foreach (['start', 'restart', 'wrong_host', 'wrong_token', 'missing_token', 'multiple_headers', 'query', 'wrong_path', 'stop', 'wrong_method', 'unknown_server'] as $variant) {
            [$monitor, $server] = resetHarness();
            $server->uuid = '11111111-2222-4333-8444-555555555555';
            $request = new OutgoingRequest($server->node->getConnectionAddress() . '/api/servers/' . $server->uuid . '/power');
            if ($variant === 'restart') $request->payload['action'] = 'restart';
            if ($variant === 'wrong_host') $request->address = str_replace('wings.example.test', 'other.example.test', $request->address);
            if ($variant === 'wrong_token') $request->authorization = ['Bearer incorrect'];
            if ($variant === 'missing_token') $request->authorization = [];
            if ($variant === 'multiple_headers') $request->authorization[] = 'Bearer another';
            if ($variant === 'query') $request->address .= '?extra=1';
            if ($variant === 'wrong_path') $request->address .= '/extra';
            if ($variant === 'stop') $request->payload['action'] = 'stop';
            if ($variant === 'wrong_method') $request->verb = 'GET';
            if ($variant === 'unknown_server') $GLOBALS['servers'] = [];
            (new ObservePowerRequest())->handle(new RequestSending($request));
            check(count(Bus::$jobs) === (in_array($variant, ['start', 'restart'], true) ? 1 : 0), 'Outgoing power request guard failed: ' . $variant);
            check(app(DaemonServerRepository::class)->requests === 0 && app(DiagnosisService::class)->captures === [], 'HTTP observer performed network or AI');
            check(!str_contains(json_encode(Cache::$data), 'private-node-test-credential'), 'HTTP observer persisted authorization credential');
        }
    });

    test('listener accepts only successful exact authenticated node callback action', function () {
        foreach (['success', 'wrong_node', 'error', 'wrong_action', 'wrong_method', 'bad_payload'] as $variant) {
            [$monitor, $server] = resetHarness();
            $request = new Request($server, $variant === 'wrong_node' ? new App\Models\Node() : $server->node);
            if ($variant === 'wrong_action') $request->action = 'OtherController@status';
            if ($variant === 'wrong_method') $request->method = 'GET';
            if ($variant === 'bad_payload') $request->data['data.new_state'] = ['starting'];
            (new ObserveContainerStatus())->handle(new RequestHandled($request, new HttpResponse($variant === 'error' ? 403 : 200)));
            check(count(Bus::$jobs) === ($variant === 'success' ? 1 : 0), 'Listener accepted invalid callback: ' . $variant);
        }
    });

    echo "$count automatic coordinator contract tests passed.\n";
}
