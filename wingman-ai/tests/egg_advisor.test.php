<?php

/**
 * Dependency-free contract tests for AI egg suggestions using small Laravel/Pelican
 * doubles. Run: php tests/egg_advisor.test.php. These do not replace panel smoke tests.
 */

namespace App\Models {
    class Egg
    {
        public int $id = 8;
        public string $name = 'Risk of Rain 2';
        public array $startup_commands = ['Default' => 'xvfb-run wine ./"Risk of Rain 2.exe"'];
        public array $docker_images = ['Wine' => 'ghcr.io/parkervcp/yolks:wine_latest'];
        public ?string $script_install = "#!/bin/bash\n./steamcmd.sh +login anonymous +app_update 1180760 validate +quit\n";
        public string $script_container = 'ghcr.io/parkervcp/installers:debian';
        public string $script_entry = 'bash';
        public ?int $copy_script_from = null;
        public int $serverCount = 1;
        /** @var list<EggVariable> */
        public array $eggVariables = [];
        public function __get(string $name): mixed
        {
            return match ($name) {
                'copy_script_install' => $this->script_install,
                'copy_script_container' => $this->script_container,
                'copy_script_entry' => $this->script_entry,
            };
        }
        public function variables(): object
        {
            return new class($this->eggVariables) { public function __construct(private array $rows) {} public function get(): array { return $this->rows; } };
        }
        public function servers(): object
        {
            return new class($this->serverCount) { public function __construct(private int $count) {} public function count(): int { return $this->count; } };
        }
        public function forceFill(array $values): self
        {
            if ($GLOBALS['dbFailure']) throw new \RuntimeException('database write failed');
            foreach ($values as $key => $value) $this->{$key} = $value;
            return $this;
        }
        public function saveOrFail(): bool { return true; }
        public function refresh(): self { return $this; }
    }
    class EggVariable
    {
        public function __construct(public int $id, public string $env_variable, public ?string $default_value, public bool $user_viewable = true, public bool $user_editable = true, public array $rules = []) {}
        public function forceFill(array $values): self
        {
            if ($GLOBALS['dbFailure']) throw new \RuntimeException('database write failed');
            foreach ($values as $key => $value) $this->{$key} = $value;
            return $this;
        }
        public function saveOrFail(): bool { return true; }
    }
    class Server
    {
        public int $id = 7;
        public string $uuid = 'server-uuid-7';
        public string $startup = 'xvfb-run wine ./"Risk of Rain 2.exe"';
        public string $image = 'ghcr.io/parkervcp/yolks:wine_latest';
        public bool $conflict = false;
        /** @var array<int, ?string> variable id => value; null means no server row. */
        public array $values = [];
        public function __construct(public Egg $egg) {}
        public function variables(): object
        {
            $rows = array_map(fn (EggVariable $variable) => (object) [
                'env_variable' => $variable->env_variable, 'default_value' => $variable->default_value,
                'server_value' => $this->values[$variable->id] ?? null, 'user_viewable' => $variable->user_viewable, 'user_editable' => $variable->user_editable,
            ], $this->egg->eggVariables);
            return new class($rows) { public function __construct(private array $rows) {} public function get(): array { return $this->rows; } };
        }
        public function serverVariables(): object
        {
            return new class($this) {
                private array $where = [];
                public function __construct(private Server $server) {}
                public function where(string $column, mixed $value): self { $this->where[$column] = $value; return $this; }
                public function update(array $values): int
                {
                    $id = $this->where['variable_id'];
                    if (($this->server->values[$id] ?? null) !== $this->where['variable_value']) return 0;
                    $this->server->values[$id] = $values['variable_value'];
                    return 1;
                }
            };
        }
        public function refresh(): self { return $this; }
        public function validateCurrentState(): void { if ($this->conflict) throw new \RuntimeException('conflict'); }
        public function forceFill(array $values): self { foreach ($values as $key => $value) $this->{$key} = $value; return $this; }
        public function saveOrFail(): bool { return true; }
    }
    class User
    {
        public function __construct(public int $id = 1, public bool $editEgg = true, public bool $editServer = true) {}
        public function can(string $ability, object $model): bool
        {
            return $ability === 'update' && ($model instanceof Egg ? $this->editEgg : $this->editServer);
        }
    }
}

namespace App\Facades {
    class Activity
    {
        public static array $events = [];
        public static function event(string $event): self { self::$events[] = $event; return new self(); }
        public function subject(mixed $subject): self { return $this; }
        public function actor(mixed $actor): self { return $this; }
        public function property(string|array $key, mixed $value = null): self { return $this; }
        public function log(): void {}
    }
}

namespace App\Repositories\Daemon {
    class DaemonServerRepository
    {
        public string|\Throwable $installLog = "Installing...\nERROR! Failed to install app '1180760' (Missing configuration)\nSTEAM_PASS=hunter2x\nInstallation completed...";
        public array $console = ['wine: failed to open "./Risk of Rain 2.exe"', 'login with hunter2x'];
        public function setServer(\App\Models\Server $server): self { return $this; }
        public function getInstallLogs(): string
        {
            if ($this->installLog instanceof \Throwable) throw $this->installLog;
            return $this->installLog;
        }
        public function getHttpClient(): object
        {
            return new class($this->console) {
                public function __construct(private array $console) {}
                public function connectTimeout(int $seconds): self { return $this; }
                public function timeout(int $seconds): self { return $this; }
                public function get(string $path, array $query): self { return $this; }
                public function throw(): self { return $this; }
                public function json(string $key): mixed { return $this->console; }
            };
        }
    }
}

namespace App\Services\Servers {
    class ReinstallServerService
    {
        public int $calls = 0;
        public function handle(\App\Models\Server $server): \App\Models\Server { $this->calls++; return $server; }
    }
}

namespace Illuminate\Support\Facades {
    class TestLock { public function block(int $seconds, callable $callback): mixed { return $callback(); } }
    class Cache
    {
        public static array $data = [];
        public static function lock(string $key, int $seconds): TestLock { return new TestLock(); }
        public static function get(string $key): mixed { return self::$data[$key] ?? null; }
        public static function put(string $key, mixed $value, mixed $ttl): void { self::$data[$key] = $value; }
        public static function forget(string $key): void { unset(self::$data[$key]); }
    }
    class DB
    {
        public static function transaction(callable $callback): mixed { return $callback(); }
    }
    class File
    {
        public static array $files = [];
        public static array $modes = [];
        public static function exists(string $path): bool { return isset(self::$files[$path]); }
        public static function get(string $path): string { return self::$files[$path]; }
        public static function ensureDirectoryExists(string $path, int $mode): void { self::$modes[$path] = $mode; }
        public static function replace(string $path, string $content, ?int $mode = null): void { self::$files[$path] = $content; self::$modes[$path] = $mode; }
    }
    class RateLimiter
    {
        public static array $counts = [];
        public static function tooManyAttempts(string $key, int $limit): bool { return (self::$counts[$key] ?? 0) >= $limit; }
        public static function hit(string $key, int $seconds): void { self::$counts[$key] = (self::$counts[$key] ?? 0) + 1; }
    }
    class Validator
    {
        public static function make(array $data, array $rules): object
        {
            return new class($data['value'], $rules['value']) {
                public function __construct(private string $value, private array $rules) {}
                public function fails(): bool { return in_array('numeric', $this->rules, true) && !is_numeric($this->value); }
            };
        }
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
        public static function withMessages(array $messages): self { return new self((string) reset($messages)); }
    }
}

namespace Pelican\AiHelpAssistant\Services {
    class AssistantSettings
    {
        public array $values = ['enabled' => true, 'ai_enabled' => true, 'base_url' => 'https://ai.example.test/v1', 'model' => 'test-model', 'api_key' => 'provider-private-key-778899', 'max_response_tokens' => 3000];
        public function get(): array { return $this->values; }
    }
    class AiClient
    {
        public array $calls = [];
        public string|\Throwable $answer = '';
        public function analyze(array $settings, array $input, ?string $instructions = null, ?int $maxTokens = null, int $timeout = 45): string
        {
            $this->calls[] = compact('input', 'instructions', 'maxTokens', 'timeout');
            if ($this->answer instanceof \Throwable) throw $this->answer;
            return $this->answer;
        }
    }
}

namespace {
    use App\Facades\Activity;
    use App\Models\Egg;
    use App\Models\EggVariable;
    use App\Models\Server;
    use App\Models\User;
    use App\Repositories\Daemon\DaemonServerRepository;
    use App\Services\Servers\ReinstallServerService;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\File;
    use Illuminate\Support\Facades\RateLimiter;
    use Pelican\AiHelpAssistant\Services\AiClient;
    use Pelican\AiHelpAssistant\Services\AssistantSettings;
    use Pelican\AiHelpAssistant\Services\EggAdvisorService;

    function app(string $class): object { return $GLOBALS['bindings'][$class]; }
    function now(): object { return new class { public function addMinutes(int $minutes): int { return $minutes; } }; }
    function logger(): object { return new class { public function info(string $message, array $context = []): void { $GLOBALS['logs'][] = $message; } }; }
    function storage_path(string $path = ''): string { return '/private-egg-test/' . $path; }

    foreach (['SecretRedactor', 'ScriptDiff', 'EggProposalNormalizer'] as $support) {
        require __DIR__ . '/../src/Support/' . $support . '.php';
    }
    require __DIR__ . '/../src/Services/EggAdvisorService.php';

    function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
    function rejects(callable $operation, string $contains): void
    {
        try { $operation(); } catch (Throwable $exception) {
            check(str_contains($exception->getMessage(), $contains), 'Wrong rejection: ' . $exception->getMessage());
            return;
        }
        throw new RuntimeException('Expected rejection containing: ' . $contains);
    }
    /** @return array{0: EggAdvisorService, 1: Server, 2: User, 3: AiClient, 4: AssistantSettings} */
    function harness(): array
    {
        Cache::$data = [];
        File::$files = [];
        File::$modes = [];
        RateLimiter::$counts = [];
        Activity::$events = [];
        $GLOBALS['dbFailure'] = false;
        $GLOBALS['logs'] = [];
        $GLOBALS['bindings'] = [DaemonServerRepository::class => new DaemonServerRepository(), ReinstallServerService::class => new ReinstallServerService()];
        $egg = new Egg();
        $egg->eggVariables = [
            new EggVariable(1, 'SRCDS_APPID', '1180760', false, false),
            new EggVariable(2, 'PLAYERS', '4', true, true, ['required', 'numeric']),
            new EggVariable(3, 'STEAM_PASS', '', true, true),
            new EggVariable(4, 'QUERY', '27016', true, false),
        ];
        $server = new Server($egg);
        $server->values = [1 => '1180760', 2 => '4', 3 => 'hunter2x', 4 => '27100'];
        $settings = new AssistantSettings();
        $client = new AiClient();
        $client->answer = json_encode([
            'summary' => 'SteamCMD fails on its first run.',
            'reason' => 'The install log shows "Missing configuration", a known first-run SteamCMD error.',
            'changes' => [
                'script_install' => "#!/bin/bash\n./steamcmd.sh +login anonymous +app_update 1180760 validate +quit\n./steamcmd.sh +login anonymous +app_update 1180760 validate +quit\n",
                'startup' => 'xvfb-run wine "./Risk of Rain 2.exe"',
                'docker_images' => ['Wine' => 'ghcr.io/parkervcp/yolks:wine_staging'],
                'variables' => ['PLAYERS' => '8', 'QUERY' => '27020'],
            ],
            'reinstall' => true,
        ]);

        return [new EggAdvisorService($settings, $client), $server, new User(), $client, $settings];
    }
    function backups(): array
    {
        $path = '/private-egg-test/app/private/wingman-ai/egg-backups/egg-8.json';
        return isset(File::$files[$path]) ? json_decode(File::$files[$path], true)['entries'] : [];
    }

    $count = 0;
    $runTest = function (string $name, callable $test) use (&$count): void {
        $test();
        $count++;
        echo "PASS $name\n";
    };

    $runTest('egg review is admin-only and needs a configured AI service', function () {
        [$advisor, $server, , $client, $settings] = harness();
        foreach ([new User(2, false, true), new User(3, true, false)] as $user) {
            check(!$advisor->available($server, $user) && $advisor->lastChange($server, $user) === null, 'A user without both admin permissions could use egg review.');
            rejects(fn () => $advisor->propose($server, $user), 'permission to edit this egg and server');
            rejects(fn () => $advisor->undo($server, $user), 'permission to edit this egg and server');
        }
        $settings->values['ai_enabled'] = false;
        check(!$advisor->available($server, new User()), 'Egg review must require an enabled AI connection.');
        check($client->calls === [] && Cache::$data === [], 'A rejected request reached the AI or stored a proposal.');
    });

    $runTest('the AI sees the egg, logs, and readable settings but never secrets', function () {
        [$advisor, $server, $user, $client] = harness();
        $advisor->propose($server, $user);
        $call = $client->calls[0];
        $input = json_encode($call['input']);
        check(str_contains($call['input']['egg']['install_script'], 'app_update 1180760') && str_contains($call['input']['install_log'], 'Missing configuration'), 'The AI must see the install script and install log.');
        check(str_contains($call['input']['console_log'], 'failed to open'), 'The AI must see recent console output.');
        check(str_contains($input, '1180760') && str_contains($input, '27100'), 'Ordinary hidden settings must stay readable.');
        check(!str_contains($input, 'hunter2x') && !str_contains($input, 'provider-private-key-778899'), 'A password or the AI key reached the AI.');
        check(str_contains($call['instructions'], 'UNTRUSTED DATA') && $call['maxTokens'] >= 8000 && $call['timeout'] === 55, 'Egg review must use its own bounded prompt and limits.');
    });

    $runTest('a proposal is stored for the requesting admin and shown for review', function () {
        [$advisor, $server, $user] = harness();
        $view = $advisor->propose($server, $user);
        $stored = Cache::$data['wingman-ai:egg-proposal:' . $view['id']];
        check($stored['user'] === 1 && $stored['server'] === 7 && $stored['egg'] === 8, 'The proposal must be bound to the admin, server, and egg.');
        $labels = array_column($view['changes'], 'label');
        check(in_array('Install script', $labels, true) && in_array('Startup command', $labels, true) && in_array('Default value of PLAYERS', $labels, true), 'Each change must be listed for review.');
        $script = $view['changes'][array_search('Install script', $labels, true)];
        check(in_array(['+', './steamcmd.sh +login anonymous +app_update 1180760 validate +quit'], $script['diff'], true), 'The script change must be shown as a diff.');
        check((bool) array_filter($view['warnings'], fn ($warning) => str_contains($warning, 'wine_staging')), 'A new Docker image must be called out.');
        check($view['servers_using'] === 1 && $view['reinstall'] === true && $view['egg_name'] === 'Risk of Rain 2', 'Review details are missing.');
    });

    $runTest('applying writes the egg, keeps a backup, and updates this server only where it used egg values', function () {
        [$advisor, $server, $user] = harness();
        $id = $advisor->propose($server, $user)['id'];
        $message = $advisor->apply($server, $user, $id, false);
        $egg = $server->egg;
        check(str_contains($egg->script_install, "validate +quit\n./steamcmd.sh") && $egg->startup_commands['Default'] === 'xvfb-run wine "./Risk of Rain 2.exe"', 'The egg was not updated.');
        check($egg->docker_images === ['Wine' => 'ghcr.io/parkervcp/yolks:wine_staging'] && $egg->eggVariables[1]->default_value === '8', 'Egg images or defaults were not updated.');
        check($server->startup === 'xvfb-run wine "./Risk of Rain 2.exe"' && $server->image === 'ghcr.io/parkervcp/yolks:wine_staging', 'This server should follow the egg startup and image it was using.');
        check($server->values[2] === '8' && $server->values[4] === '27100', 'Only server values still on the old default may follow the new default.');
        $entries = backups();
        check(count($entries) === 1 && $entries[0]['before']['variables']['PLAYERS'] === '4' && $entries[0]['after']['variables']['PLAYERS'] === '8', 'The backup must hold the egg before and after.');
        check(str_contains($entries[0]['before']['script_install'], 'app_update') && $entries[0]['server_before']['startup'] === 'xvfb-run wine ./"Risk of Rain 2.exe"', 'The backup must hold the previous script and server values.');
        check(File::$modes['/private-egg-test/app/private/wingman-ai/egg-backups'] === 0700 && in_array(0600, File::$modes, true), 'Backups must be private.');
        check(str_contains($message, 'Undo') && ReinstallServerService::class && app(ReinstallServerService::class)->calls === 0, 'No reinstall may run unless requested.');
        rejects(fn () => $advisor->apply($server, $user, $id, false), 'expired');
    });

    $runTest('applying refuses foreign, stale, or invalid proposals before writing anything', function () {
        [$advisor, $server, $user] = harness();
        $id = $advisor->propose($server, $user)['id'];
        rejects(fn () => $advisor->apply($server, new User(2), $id, false), 'expired');
        rejects(fn () => $advisor->apply($server, $user, 'not-a-proposal', false), 'expired');
        $server->egg->script_install .= "# edited by hand\n";
        rejects(fn () => $advisor->apply($server, $user, $id, false), 'changed after the AI suggestion');
        check(backups() === [] && $server->egg->startup_commands['Default'] === 'xvfb-run wine ./"Risk of Rain 2.exe"', 'A refused apply changed the egg.');

        [$advisor, $server, $user, $client] = harness();
        $client->answer = json_encode(['summary' => 'Players', 'changes' => ['variables' => ['PLAYERS' => 'many']]]);
        $id = $advisor->propose($server, $user)['id'];
        rejects(fn () => $advisor->apply($server, $user, $id, false), "does not meet this egg's rules");
        check(backups() === [] && $server->egg->eggVariables[1]->default_value === '4', 'An invalid default was written.');

        [$advisor, $server, $user, $client] = harness();
        $client->answer = json_encode(['summary' => 'No egg problem.', 'reason' => 'A mod is broken.', 'changes' => []]);
        $view = $advisor->propose($server, $user);
        check($view['changes'] === [] && $view['reinstall'] === false, 'A no-change answer must be shown as such.');
        rejects(fn () => $advisor->apply($server, $user, $view['id'], true), 'no changes to apply');
    });

    $runTest('reinstall runs only when requested and the server is available', function () {
        [$advisor, $server, $user] = harness();
        $id = $advisor->propose($server, $user)['id'];
        $server->conflict = true;
        rejects(fn () => $advisor->apply($server, $user, $id, true), 'installing, restoring, transferring, or suspended');
        check(backups() === [], 'A busy server had its egg changed.');
        $server->conflict = false;
        $message = $advisor->apply($server, $user, $id, true);
        check(app(ReinstallServerService::class)->calls === 1 && Activity::$events === ['server:settings.reinstall'] && str_contains($message, 'Reinstall started'), 'The requested reinstall did not run once.');
    });

    $runTest('a failed database update removes the pending backup', function () {
        [$advisor, $server, $user] = harness();
        $first = $advisor->propose($server, $user)['id'];
        $advisor->apply($server, $user, $first, false);
        $GLOBALS['bindings'][DaemonServerRepository::class] = new DaemonServerRepository();
        $client = (new ReflectionProperty($advisor, 'client'))->getValue($advisor);
        $client->answer = json_encode(['summary' => 'More players', 'changes' => ['variables' => ['PLAYERS' => '12']]]);
        $second = $advisor->propose($server, $user)['id'];
        $GLOBALS['dbFailure'] = true;
        rejects(fn () => $advisor->apply($server, $user, $second, false), 'database write failed');
        check(count(backups()) === 1 && backups()[0]['after'] !== null, 'A failed update left a pending backup or lost the earlier one.');
    });

    $runTest('undo restores the egg and this server, and refuses after later edits', function () {
        [$advisor, $server, $user] = harness();
        $advisor->apply($server, $user, $advisor->propose($server, $user)['id'], false);
        check($advisor->lastChange($server, $user)['undoable'] === true, 'A fresh Wingman change must be undoable.');
        $message = $advisor->undo($server, $user);
        $egg = $server->egg;
        check($egg->startup_commands['Default'] === 'xvfb-run wine ./"Risk of Rain 2.exe"' && $egg->docker_images === ['Wine' => 'ghcr.io/parkervcp/yolks:wine_latest'], 'Undo did not restore the egg.');
        check($egg->eggVariables[1]->default_value === '4' && !str_contains($egg->script_install, "quit\n./steamcmd"), 'Undo did not restore defaults and script.');
        check($server->startup === 'xvfb-run wine ./"Risk of Rain 2.exe"' && $server->image === 'ghcr.io/parkervcp/yolks:wine_latest' && $server->values[2] === '4', 'Undo did not restore this server.');
        check(backups() === [] && $advisor->lastChange($server, $user) === null && str_contains($message, 'Restored'), 'Undo must consume the backup.');
        rejects(fn () => $advisor->undo($server, $user), 'no Wingman egg change to undo');

        [$advisor, $server, $user] = harness();
        $advisor->apply($server, $user, $advisor->propose($server, $user)['id'], false);
        $server->egg->docker_images['Extra'] = 'ghcr.io/example/extra:1';
        check($advisor->lastChange($server, $user)['undoable'] === false, 'A hand-edited egg must not offer automatic undo.');
        rejects(fn () => $advisor->undo($server, $user), 'edited after Wingman changed it');
        check(isset($server->egg->docker_images['Extra']), 'A refused undo overwrote a later edit.');

        [$advisor, $server, $user] = harness();
        $advisor->apply($server, $user, $advisor->propose($server, $user)['id'], false);
        $server->startup = 'custom startup';
        $advisor->undo($server, $user);
        check($server->startup === 'custom startup', 'Undo overwrote a server value changed after Wingman.');
    });

    $runTest('AI failures, unusable answers, and missing logs fail safely', function () {
        [$advisor, $server, $user, $client] = harness();
        $client->answer = new RuntimeException('HTTP 500 with secret body');
        rejects(fn () => $advisor->propose($server, $user), 'did not answer in time');
        $client->answer = 'I think you should reinstall';
        rejects(fn () => $advisor->propose($server, $user), 'could not use');
        check(Cache::$data === [], 'A failed review stored a proposal.');
        $client->answer = json_encode(['summary' => 'No egg problem.', 'changes' => []]);
        app(DaemonServerRepository::class)->installLog = new RuntimeException('Wings offline');
        $advisor->propose($server, $user);
        check(str_contains($client->calls[2]['input']['install_log'], 'could not be retrieved'), 'A missing install log must be reported to the AI, not fabricated.');
        $advisor->propose($server, $user);
        $advisor->propose($server, $user);
        rejects(fn () => $advisor->propose($server, $user), 'Please wait');
    });

    echo "$count egg advisor contract tests passed.\n";
}
