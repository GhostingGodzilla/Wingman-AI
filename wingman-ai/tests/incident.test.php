<?php

/**
 * Dependency-free contract tests for crash and install explanations using small
 * Laravel/Pelican doubles. Run: php tests/incident.test.php. Not a panel smoke test.
 */

namespace App\Enums {
    enum SubuserPermission: string
    {
        case WebsocketConnect = 'websocket.connect';
        case StartupRead = 'startup.read';
    }
}

namespace App\Models {
    class Node { public bool $maintenance = false; public function isUnderMaintenance(): bool { return $this->maintenance; } }
    class User
    {
        public function __construct(public int $id = 1, public array $permissions = ['websocket.connect', 'startup.read']) {}
        public function can(\App\Enums\SubuserPermission $permission, Server $server): bool { return in_array($permission->value, $this->permissions, true); }
    }
    class Server
    {
        public static array $servers = [];
        public int $owner_id = 1;
        public string $uuid = 'server-uuid-7';
        public object $egg;
        public Node $node;
        public array $variableRows = [];
        public function __construct(public int $id = 7, public ?User $owner = null)
        {
            $this->egg = (object) ['name' => 'Risk of Rain 2'];
            $this->node = new Node();
            $this->owner ??= new User();
        }
        public function getMorphClass(): string { return 'server'; }
        public static function query(): object
        {
            return new class { public function find(mixed $id): ?Server { return Server::$servers[$id] ?? null; } };
        }
        public function user(): object { return new class($this->owner) { public function __construct(private User $owner) {} public function first(): User { return $this->owner; } }; }
        public function variables(): object { return new class($this->variableRows) { public function __construct(private array $rows) {} public function get(): array { return $this->rows; } }; }
    }
    class ActivityLog
    {
        public static array $logs = [];
        public static function query(): object
        {
            return new class { public function find(mixed $id): ?object { return ActivityLog::$logs[$id] ?? null; } };
        }
    }
}

namespace App\Events {
    class ActivityLogged
    {
        public function __construct(public object $model) {}
        public function is(string $event): bool { return $this->model->event === $event; }
    }
}

namespace App\Events\Server {
    class Installed { public function __construct(public \App\Models\Server $server, public bool $successful, public bool $initialInstall) {} }
}

namespace App\Repositories\Daemon {
    class DaemonServerRepository
    {
        public string|\Throwable $installLog = '';
        public function setServer(\App\Models\Server $server): self { return $this; }
        public function getInstallLogs(): string { if ($this->installLog instanceof \Throwable) throw $this->installLog; return $this->installLog; }
    }
}

namespace Illuminate\Support {
    class Collection { public function __construct(private array $items) {} public function all(): array { return $this->items; } }
}

namespace Illuminate\Support\Facades {
    class TestLock { public function block(int $seconds, callable $callback): mixed { return $callback(); } }
    class Cache
    {
        public static array $data = [];
        public static function lock(string $key, int $seconds): TestLock { return new TestLock(); }
        public static function get(string $key, mixed $default = null): mixed { return self::$data[$key] ?? $default; }
        public static function has(string $key): bool { return isset(self::$data[$key]); }
        public static function put(string $key, mixed $value, mixed $ttl): void { self::$data[$key] = $value; }
        public static function add(string $key, mixed $value, mixed $ttl): bool { if (isset(self::$data[$key])) return false; self::$data[$key] = $value; return true; }
        public static function increment(string $key): int { return ++self::$data[$key]; }
        public static function forget(string $key): void { unset(self::$data[$key]); }
    }
    class Crypt
    {
        public static function encryptString(string $value): string { return 'enc:' . base64_encode($value); }
        public static function decryptString(string $value): string { if (!str_starts_with($value, 'enc:')) throw new \RuntimeException('bad'); return base64_decode(substr($value, 4)); }
    }
    class Bus
    {
        public static array $jobs = [];
        public static bool $fail = false;
        public static function dispatch(object $job): void { if (self::$fail) throw new \RuntimeException('queue down'); self::$jobs[] = $job; }
    }
}

namespace Illuminate\Bus { trait Queueable {} }
namespace Illuminate\Contracts\Queue { interface ShouldQueue {} }
namespace Illuminate\Foundation\Bus { trait Dispatchable {} }
namespace Illuminate\Queue { trait InteractsWithQueue {} }

namespace Pelican\AiHelpAssistant\Services {
    class AssistantSettings
    {
        public array $values = ['enabled' => true, 'automatic_enabled' => true, 'crash_watch_enabled' => true, 'install_check_enabled' => true, 'ai_enabled' => false,
            'base_url' => '', 'model' => '', 'api_key' => 'provider-private-key-778899', 'auto_daily_limit' => 20, 'auto_cooldown_seconds' => 300];
        public function get(): array { return $this->values; }
    }
    class AutomaticStartupService
    {
        public bool $ready = true;
        public array $covered = [];
        public function ready(): bool { return $this->ready; }
        public function covers(int $serverId, float $at): bool { return in_array($at, $this->covered, true); }
    }
    class ConsoleHistory
    {
        public string $history = "[20:00:00] Server started\n[20:15:00] Player joined";
        public function recent(\App\Models\Server $server, int $lines = 100, int $maxBytes = 48000): string { return $this->history; }
    }
    class DiagnosisService
    {
        public array $captures = [];
        public function diagnose(\App\Models\Server $server, \App\Models\User $user, array $capture): array
        {
            $this->captures[] = $capture;
            return ['summary' => 'The container was reported as killed for exceeding memory.', 'source' => 'rules', 'confidence' => 'high', 'evidence' => [], 'steps' => [], 'needs_more_information' => []];
        }
    }
    class AiClient
    {
        public array $inputs = [];
        public string|\Throwable $answer = '';
        public function analyze(array $settings, array $input, ?string $instructions = null, ?int $maxTokens = null, int $timeout = 45): string
        {
            $this->inputs[] = $input;
            if ($this->answer instanceof \Throwable) throw $this->answer;
            return $this->answer;
        }
    }
}

namespace {
    use App\Events\ActivityLogged;
    use App\Events\Server\Installed;
    use App\Models\ActivityLog;
    use App\Models\Server;
    use App\Models\User;
    use App\Repositories\Daemon\DaemonServerRepository;
    use Illuminate\Support\Collection;
    use Illuminate\Support\Facades\Bus;
    use Illuminate\Support\Facades\Cache;
    use Pelican\AiHelpAssistant\Jobs\ProcessIncident;
    use Pelican\AiHelpAssistant\Listeners\ObserveCrash;
    use Pelican\AiHelpAssistant\Listeners\ObserveInstall;
    use Pelican\AiHelpAssistant\Services\AiClient;
    use Pelican\AiHelpAssistant\Services\AssistantSettings;
    use Pelican\AiHelpAssistant\Services\AutomaticStartupService;
    use Pelican\AiHelpAssistant\Services\ConsoleHistory;
    use Pelican\AiHelpAssistant\Services\DiagnosisService;
    use Pelican\AiHelpAssistant\Services\IncidentService;

    function app(string $class): object { return $GLOBALS['bindings'][$class]; }
    function now(): object { return new class { public function addSeconds(int $seconds): int { return $seconds; } }; }
    function logger(): object { return new class { public function warning(string $message, array $context = []): void { $GLOBALS['warnings'][] = $message; } }; }

    foreach (['Support/SecretRedactor', 'Support/ReportNormalizer', 'Support/InstallLogChecker', 'Jobs/ProcessIncident', 'Services/IncidentService', 'Listeners/ObserveCrash', 'Listeners/ObserveInstall'] as $file) {
        require __DIR__ . '/../src/' . $file . '.php';
    }

    function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
    function harness(): array
    {
        Cache::$data = [];
        Bus::$jobs = [];
        Bus::$fail = false;
        ActivityLog::$logs = [];
        $GLOBALS['warnings'] = [];
        $server = new Server();
        $server->variableRows = [(object) ['env_variable' => 'STEAM_PASS', 'server_value' => 'hunter2x', 'default_value' => '', 'user_viewable' => true], (object) ['env_variable' => 'XVFB', 'server_value' => '1', 'default_value' => '1', 'user_viewable' => false]];
        Server::$servers = [7 => $server];
        $GLOBALS['bindings'] = [
            AssistantSettings::class => new AssistantSettings(),
            AutomaticStartupService::class => new AutomaticStartupService(),
            ConsoleHistory::class => new ConsoleHistory(),
            DiagnosisService::class => new DiagnosisService(),
            AiClient::class => new AiClient(),
            DaemonServerRepository::class => new DaemonServerRepository(),
            IncidentService::class => new IncidentService(),
        ];

        return [app(IncidentService::class), $server];
    }
    function stored(string $kind): ?array
    {
        $value = Cache::$data['wingman:incident:' . $kind . ':7'] ?? null;
        return is_string($value) ? json_decode(base64_decode(substr($value, 4)), true) : null;
    }
    function activity(int $id, string $event, array $properties, int $timestamp = 1792030000): object
    {
        $log = new class($id, $event, new Collection($properties), $timestamp) {
            public object $timestamp;
            public function __construct(public int $id, public string $event, public Collection $properties = new Collection([]), int $at = 0)
            {
                $this->timestamp = new class($at) { public function __construct(private int $at) {} public function getTimestamp(): int { return $this->at; } };
            }
            public function subjects(): object
            {
                return new class { public function where(string $column, string $value): self { return $this; } public function value(string $column): int { return 7; } };
            }
        };
        ActivityLog::$logs[$id] = $log;

        return $log;
    }

    $count = 0;
    $runTest = function (string $name, callable $test) use (&$count): void {
        $test();
        $count++;
        echo "PASS $name\n";
    };

    $steamLog = "Using anonymous user.\nERROR! Failed to install app '1180760' (Missing configuration)\nlogin anonymous hunter2x\nInstallation completed...\n";

    $runTest('crash events queue an explanation unless covered, disabled, or queueless', function () {
        [$incidents, $server] = harness();
        (new ObserveCrash())->handle(new ActivityLogged(activity(73, 'server:crashed', ['exit_code' => 137, 'oomkilled' => true])));
        check(count(Bus::$jobs) === 1 && Bus::$jobs[0] instanceof ProcessIncident && Bus::$jobs[0]->kind === 'crash' && Bus::$jobs[0]->activityId === 73, 'A crash must queue one job with identifiers only.');
        $fields = get_object_vars(Bus::$jobs[0]);
        ksort($fields);
        check($fields === ['activityId' => 73, 'failOnTimeout' => true, 'kind' => 'crash', 'serverId' => 7, 'successful' => null, 'timeout' => 80, 'tries' => 1], 'Console data must never enter the queue.');
        (new ObserveCrash())->handle(new ActivityLogged(activity(74, 'server:power.start', [])));
        check(count(Bus::$jobs) === 1, 'Other activity must be ignored.');
        app(AutomaticStartupService::class)->covered = [1792030000.0];
        (new ObserveCrash())->handle(new ActivityLogged(activity(75, 'server:crashed', [])));
        check(count(Bus::$jobs) === 1, 'A crash during a watched start belongs to the startup report.');
        app(AutomaticStartupService::class)->covered = [];
        app(AssistantSettings::class)->values['crash_watch_enabled'] = false;
        $incidents->crashed($server, 76, 1.0);
        app(AssistantSettings::class)->values['crash_watch_enabled'] = true;
        app(AutomaticStartupService::class)->ready = false;
        $incidents->crashed($server, 77, 1.0);
        check(count(Bus::$jobs) === 1, 'Disabled crash watch or automatic checks must queue nothing.');
        app(AutomaticStartupService::class)->ready = true;
        Bus::$fail = true;
        (new ObserveCrash())->handle(new ActivityLogged(activity(78, 'server:crashed', [])));
        check(Cache::$data === [], 'A queue outage must not break the activity upload.');
    });

    $runTest('a crash report uses the crash lines, adds console history, and keeps the crash facts', function () {
        [$incidents, $server] = harness();
        activity(73, 'server:crashed', ['exit_code' => 137, 'oomkilled' => true, 'logs' => ['[20:30:00] java.lang.OutOfMemoryError', 'Killed']]);
        $incidents->process(7, 'crash', 73);
        $capture = app(DiagnosisService::class)->captures[0];
        check(str_ends_with($capture['logs'], "[20:15:00] Player joined\n[20:30:00] java.lang.OutOfMemoryError\nKilled"), 'Crash lines must come last, after console history.');
        check($capture['observations']['capture_mode'] === 'crash' && $capture['observations']['crashed_while_running'] === true && $capture['observations']['oom_killed'] === true && $capture['observations']['exit_code'] === '137', 'Crash facts must reach the diagnosis.');
        check($capture['observations']['logs_may_include_previous_run'] === true && $capture['outcome'] === 'failed', 'Added history may include a restart and must be flagged.');
        $report = stored('crash');
        check($report['owner_id'] === 1 && $report['report']['kind'] === 'crash' && isset($report['report']['generated_at']), 'The crash report must be stored for the owner.');
        $incidents->process(7, 'crash', 73);
        check(count(app(DiagnosisService::class)->captures) === 1, 'A second crash inside the cooldown must not be diagnosed again.');
    });

    $runTest('crash reports need an owner with console and startup access', function () {
        [$incidents, $server] = harness();
        $server->owner = new User(1, ['websocket.connect']);
        activity(73, 'server:crashed', ['logs' => ['crash']]);
        $incidents->process(7, 'crash', 73);
        $server->node->maintenance = true;
        $server->owner = new User();
        $incidents->process(7, 'crash', 73);
        check(app(DiagnosisService::class)->captures === [] && stored('crash') === null, 'No report may be made without owner access or during maintenance.');
    });

    $runTest('install checks report failures, clear after clean installs, and keep built-in fixes', function () use ($steamLog) {
        [$incidents, $server] = harness();
        app(DaemonServerRepository::class)->installLog = $steamLog;
        (new ObserveInstall())->handle(new Installed($server, true, false));
        check(count(Bus::$jobs) === 1 && Bus::$jobs[0]->kind === 'install' && Bus::$jobs[0]->successful === true, 'An install must queue a check.');
        $incidents->process(7, 'install', null, true);
        $report = stored('install')['report'];
        check($report['summary'] === 'The install finished with errors: SteamCMD could not download the game.' && $report['fix'] === ['type' => 'reinstall'] && $report['kind'] === 'install', 'A failed download must be reported with a reinstall fix.');
        check(!str_contains(json_encode($report), 'hunter2x'), 'Install evidence must not contain a password.');
        app(DaemonServerRepository::class)->installLog = "Success! App '1180760' fully installed.\n";
        $incidents->process(7, 'install', null, true);
        check(stored('install') === null, 'A clean install must clear the earlier warning.');
        app(DaemonServerRepository::class)->installLog = new RuntimeException('Wings offline');
        $incidents->process(7, 'install', null, false);
        check(stored('install')['report']['summary'] === 'The installation failed.', 'A failed install must be reported even without a log.');
        app(AssistantSettings::class)->values['install_check_enabled'] = false;
        Cache::$data = [];
        $incidents->process(7, 'install', null, false);
        check(stored('install') === null, 'A disabled install check must do nothing.');
    });

    $runTest('install checks use AI when configured, without secrets, and fall back safely', function () use ($steamLog) {
        [$incidents, $server] = harness();
        app(AssistantSettings::class)->values = array_replace(app(AssistantSettings::class)->values, ['ai_enabled' => true, 'base_url' => 'https://ai.example.test/v1', 'model' => 'test']);
        app(DaemonServerRepository::class)->installLog = $steamLog;
        app(AiClient::class)->answer = json_encode(['summary' => 'SteamCMD failed on its first run.', 'likely_cause' => 'A known SteamCMD first-run error.', 'confidence' => 'high', 'evidence' => [], 'steps' => ['Reinstall.'], 'needs_more_information' => [], 'fix' => ['type' => 'eula']]);
        $incidents->process(7, 'install', null, true);
        $report = stored('install')['report'];
        check($report['source'] === 'ai' && $report['fix'] === ['type' => 'reinstall'], 'An AI explanation must keep the rule-based fix, not the model\'s.');
        $sent = json_encode(app(AiClient::class)->inputs[0]);
        check(!str_contains($sent, 'hunter2x') && !str_contains($sent, 'provider-private-key-778899') && str_contains($sent, 'Missing configuration'), 'The AI must see the log without secrets.');
        Cache::$data = [];
        app(AiClient::class)->answer = new RuntimeException('HTTP 500 secret body');
        $incidents->process(7, 'install', null, true);
        $report = stored('install')['report'];
        check($report['source'] === 'rules' && str_contains($report['notice'], 'Basic error checks are shown instead') && !str_contains(json_encode($report), 'secret body'), 'An AI failure must fall back to built-in checks without details.');
    });

    $runTest('the newest crash or install report is shown only to permitted users of the same owner', function () use ($steamLog) {
        [$incidents, $server] = harness();
        activity(73, 'server:crashed', ['logs' => ['crash']]);
        $incidents->process(7, 'crash', 73);
        $crash = stored('crash');
        $crash['report']['generated_at'] = '2026-10-14T20:00:00+00:00';
        Cache::$data['wingman:incident:crash:7'] = 'enc:' . base64_encode(json_encode($crash));
        app(DaemonServerRepository::class)->installLog = $steamLog;
        $incidents->process(7, 'install', null, true);
        check($incidents->latest($server, new User())['kind'] === 'install', 'The newer install report must be shown.');
        check($incidents->latest($server, new User(2, ['websocket.connect'])) === null, 'Reports need startup access.');
        $server->owner_id = 2;
        check($incidents->latest($server, new User()) === null, 'A new owner must not see the previous owner\'s reports.');
        Cache::$data['wingman:incident:crash:7'] = 'garbage';
        $server->owner_id = 1;
        check($incidents->latest($server, new User())['kind'] === 'install', 'An unreadable report must be skipped.');
    });

    echo "$count incident contract tests passed.\n";
}
