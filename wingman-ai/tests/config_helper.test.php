<?php

/**
 * Dependency-free contract tests for AI-assisted settings changes using small
 * Laravel/Pelican doubles. Run: php tests/config_helper.test.php.
 */

namespace App\Enums {
    enum SubuserPermission: string
    {
        case FileRead = 'file.read';
        case FileReadContent = 'file.read-content';
        case FileUpdate = 'file.update';
    }
}

namespace App\Models {
    class User
    {
        public function __construct(public int $id = 1, public array $permissions = ['file.read', 'file.read-content', 'file.update']) {}
        public function can(\App\Enums\SubuserPermission $permission, Server $server): bool { return in_array($permission->value, $this->permissions, true); }
    }
    class Server
    {
        public int $id = 7;
        public object $egg;
        public function __construct()
        {
            $this->egg = (object) ['name' => 'Paper', 'inherit_config_files' => null,
                'config_files' => '{"server.properties":{"parser":"properties","find":{"server-port":"{{server.build.default.port}}","server-ip":"0.0.0.0"}}}'];
        }
        public function variables(): object
        {
            return new class { public function get(): array { return [(object) ['env_variable' => 'RCON_PASS', 'server_value' => 'steam-secret-7788', 'default_value' => '', 'user_viewable' => true]]; } };
        }
    }
}

namespace App\Repositories\Daemon {
    class DaemonFileRepository
    {
        public array $directories = [];
        public array $files = [];
        public array $writes = [];
        public function setServer(\App\Models\Server $server): self { return $this; }
        public function getDirectory(string $path): array
        {
            if (!isset($this->directories[$path])) throw new \RuntimeException('not found');
            return $this->directories[$path];
        }
        public function getContent(string $path, ?int $notLargerThan = null): string
        {
            if (!isset($this->files[$path])) throw new \RuntimeException('missing');
            return $this->files[$path];
        }
        public function putContent(string $path, string $content): void { $this->writes[] = [$path, $content]; $this->files[$path] = $content; }
    }
}

namespace App\Facades {
    class Activity
    {
        public static array $events = [];
        public static function event(string $event): self { self::$events[] = $event; return new self(); }
        public function subject(mixed $subject): self { return $this; }
        public function actor(mixed $actor): self { return $this; }
        public function property(string|array $key, mixed $value = null): self { self::$events[] = $key; return $this; }
        public function log(): void {}
    }
}

namespace Illuminate\Support\Facades {
    class Cache
    {
        public static array $data = [];
        public static function get(string $key): mixed { return self::$data[$key] ?? null; }
        public static function put(string $key, mixed $value, mixed $ttl): void { self::$data[$key] = $value; }
        public static function forget(string $key): void { unset(self::$data[$key]); }
    }
    class Crypt
    {
        public static function encryptString(string $value): string { return 'enc:' . base64_encode($value); }
        public static function decryptString(string $value): string { if (!str_starts_with($value, 'enc:')) throw new \RuntimeException('bad'); return base64_decode(substr($value, 4)); }
    }
    class RateLimiter
    {
        public static array $counts = [];
        public static function tooManyAttempts(string $key, int $limit): bool { return (self::$counts[$key] ?? 0) >= $limit; }
        public static function hit(string $key, int $seconds): void { self::$counts[$key] = (self::$counts[$key] ?? 0) + 1; }
    }
}

namespace Illuminate\Support {
    class Str { public static int $n = 0; public static function uuid(): string { return 'proposal-' . ++self::$n; } }
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
        public array $values = ['enabled' => true, 'config_helper_enabled' => true, 'ai_enabled' => true, 'base_url' => 'https://ai.example.test/v1', 'model' => 'test', 'api_key' => 'provider-private-key-778899', 'max_response_tokens' => 3000];
        public function get(): array { return $this->values; }
    }
    class AiClient
    {
        public array $calls = [];
        public array $answers = [];
        public function analyze(array $settings, array $input, ?string $instructions = null, ?int $maxTokens = null, int $timeout = 45): string
        {
            $this->calls[] = compact('input', 'instructions', 'maxTokens', 'timeout');
            $answer = array_shift($this->answers);
            if ($answer instanceof \Throwable) throw $answer;
            return is_string($answer) ? $answer : json_encode($answer);
        }
    }
}

namespace {
    use App\Facades\Activity;
    use App\Models\Server;
    use App\Models\User;
    use App\Repositories\Daemon\DaemonFileRepository;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\RateLimiter;
    use Pelican\AiHelpAssistant\Services\AiClient;
    use Pelican\AiHelpAssistant\Services\AssistantSettings;
    use Pelican\AiHelpAssistant\Services\ConfigHelperService;

    function app(string $class): object { return $GLOBALS['bindings'][$class]; }
    function now(): object { return new class { public function addMinutes(int $minutes): int { return $minutes; } }; }

    require __DIR__ . '/../src/Services/AiServiceException.php';
    foreach (['Support/SecretRedactor', 'Support/ScriptDiff', 'Support/ConfigEdits', 'Services/ConfigHelperService'] as $file) {
        require __DIR__ . '/../src/' . $file . '.php';
    }

    function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
    function rejects(callable $operation, string $contains): void
    {
        try { $operation(); } catch (Throwable $exception) {
            check(str_contains($exception->getMessage(), $contains), 'Wrong rejection: ' . $exception->getMessage());
            return;
        }
        throw new RuntimeException('Expected rejection containing: ' . $contains);
    }
    function harness(): array
    {
        Cache::$data = [];
        RateLimiter::$counts = [];
        Activity::$events = [];
        $files = new DaemonFileRepository();
        $file = fn (string $name, int $size = 100, bool $directory = false, bool $symlink = false) => ['name' => $name, 'size' => $size, 'directory' => $directory, 'file' => !$directory, 'symlink' => $symlink, 'mime' => 'text/plain'];
        $files->directories = [
            '/' => [$file('server.properties'), $file('bukkit.yml'), $file('server.jar', 50000000), $file('huge.json', 900000), $file('.env'), $file('linked.cfg', 10, false, true),
                $file('logs', 0, true), $file('steamapps', 0, true), $file('world', 0, true), $file('plugins', 0, true), $file('aaa', 0, true), $file('config', 0, true), $file('server.properties.wingman-20261014-120000.bak')],
            '/config' => [$file('paper-global.yml'), $file('deep', 0, true)],
            '/config/deep' => [$file('nested.toml'), $file('deeper', 0, true)],
            '/config/deep/deeper' => [$file('too-deep.ini')],
            '/aaa' => [$file('notes.txt')],
            '/logs' => [$file('latest.txt')],
        ];
        $files->files['server.properties'] = "motd=Hello\nmax-players=4\nwhite-list=false\nserver-port=25565\nrcon.password=hunter22\nmotd2=owner steam-secret-7788\n";
        $client = new AiClient();
        $GLOBALS['bindings'] = [DaemonFileRepository::class => $files];

        return [new ConfigHelperService(new AssistantSettings(), $client), new Server(), new User(), $client, $files];
    }

    $count = 0;
    $runTest = function (string $name, callable $test) use (&$count): void {
        $test();
        $count++;
        echo "PASS $name\n";
    };

    $whitelist = ['summary' => 'Turn on the whitelist.', 'edits' => [['find' => 'white-list=false', 'replace' => 'white-list=true']], 'append' => ['enforce-whitelist=true'], 'restart' => true, 'notes' => ''];

    $runTest('settings changes need permission, an AI connection, and a sensible request', function () {
        [$helper, $server, $user, $client] = harness();
        check($helper->available($server, $user), 'Editing must be available with file permissions and AI.');
        foreach (['file.read', 'file.read-content', 'file.update'] as $missing) {
            check(!$helper->available($server, new User(2, array_diff(['file.read', 'file.read-content', 'file.update'], [$missing]))), 'Missing ' . $missing . ' must hide the helper.');
        }
        rejects(fn () => $helper->propose($server, new User(2, ['file.read']), 'turn on whitelist'), 'permission to read and edit');
        rejects(fn () => $helper->propose($server, $user, 'hi'), '3 to 300 characters');
        rejects(fn () => $helper->propose($server, $user, str_repeat('x', 301)), '3 to 300 characters');
        check($client->calls === [], 'Refused requests must not reach the AI.');
    });

    $runTest('candidate files come from the egg and a bounded, filtered scan', function () {
        [$helper, $server, $user, $client] = harness();
        $client->answers = [['file' => null, 'reason' => 'No match.']];
        rejects(fn () => $helper->propose($server, $user, 'turn on whitelist'), 'could not tell which file');
        $paths = array_column($client->calls[0]['input']['files'], 'path');
        check($paths[0] === 'server.properties' && $client->calls[0]['input']['files'][0]['managed_by_pelican'] === true, 'Egg-managed files must come first and be marked.');
        check(in_array('bukkit.yml', $paths, true) && in_array('config/paper-global.yml', $paths, true) && in_array('config/deep/nested.toml', $paths, true) && in_array('aaa/notes.txt', $paths, true), 'Text settings files must be found, including in subfolders.');
        foreach (['server.jar', 'huge.json', '.env', 'linked.cfg', 'logs/latest.txt', 'config/deep/deeper/too-deep.ini', 'server.properties.wingman-20261014-120000.bak'] as $excluded) {
            check(!in_array($excluded, $paths, true), 'Must not offer ' . $excluded);
        }
        check(array_search('config/paper-global.yml', $paths, true) < array_search('aaa/notes.txt', $paths, true), 'Settings-like folders must be scanned first.');
        check(str_contains($client->calls[0]['instructions'], 'UNTRUSTED DATA'), 'File choice must use its own prompt.');
    });

    $runTest('the AI sees a masked file and its proposal is applied only to exact lines', function () use ($whitelist) {
        [$helper, $server, $user, $client] = harness();
        $client->answers = [['file' => 'server.properties', 'reason' => 'Whitelist lives here.'], $whitelist];
        $view = $helper->propose($server, $user, 'turn   on the whitelist');
        $edit = $client->calls[1]['input'];
        check($edit['file'] === 'server.properties' && $edit['pelican_managed_settings'] === ['server-port', 'server-ip'], 'The edit step must name the file and the settings Pelican manages.');
        $sent = json_encode($client->calls);
        check(!str_contains($sent, 'hunter22') && !str_contains($sent, 'steam-secret-7788') && !str_contains($sent, 'provider-private-key-778899'), 'No secret may reach the AI.');
        check(str_contains($edit['content'], 'rcon.password=[REDACTED]') && str_contains($edit['content'], 'motd2=owner [REDACTED]'), 'Masked lines must keep their place.');
        check($view['file'] === 'server.properties' && $view['restart'] === true && is_string($view['id']), 'The proposal must be shown with an id.');
        check(in_array(['+', 'white-list=true'], $view['diff'], true) && in_array(['+', 'enforce-whitelist=true'], $view['diff'], true) && !str_contains(json_encode($view), 'hunter22'), 'The diff must show the change without secrets.');
        $stored = json_decode(base64_decode(substr(Cache::$data['wingman-ai:config-proposal:' . $view['id']], 4)), true);
        check($stored['user'] === 1 && $stored['server'] === 7 && str_contains($stored['content'], "rcon.password=hunter22\n") && str_contains($stored['content'], 'white-list=true'), 'The stored change must keep the real secret and be bound to the requester.');
        check(str_starts_with(Cache::$data['wingman-ai:config-proposal:' . $view['id']], 'enc:'), 'The pending change must be stored encrypted.');
    });

    $runTest('unsafe or impossible edits are refused with a clear reason', function () {
        foreach ([
            [['find' => 'rcon.password=[REDACTED]', 'replace' => 'rcon.password=x'], 'hidden value'],
            [['find' => 'server-port=25565', 'replace' => 'server-port=1'], 'set by Pelican from the Startup page'],
            [['find' => 'max-players=99', 'replace' => 'max-players=8'], 'not found exactly once'],
        ] as [$edit, $reason]) {
            [$helper, $server, $user, $client] = harness();
            $client->answers = [['file' => 'server.properties'], ['summary' => 'x', 'edits' => [$edit], 'append' => []]];
            rejects(fn () => $helper->propose($server, $user, 'change something'), $reason);
            check(Cache::$data === [], 'A refused edit must not be stored.');
        }
        [$helper, $server, $user, $client] = harness();
        $client->answers = [['file' => 'not/listed.cfg']];
        rejects(fn () => $helper->propose($server, $user, 'change something'), 'could not tell which file');
        [$helper, $server, $user, $client] = harness();
        $client->answers = ['not json at all'];
        rejects(fn () => $helper->propose($server, $user, 'change something'), 'could not use');
        [$helper, $server, $user, $client] = harness();
        $client->answers = [['file' => 'server.properties'], ['summary' => 'Nothing to do.', 'edits' => [], 'append' => [], 'notes' => 'The port is set on the Startup page.']];
        $view = $helper->propose($server, $user, 'change the port');
        check($view['id'] === null && $view['diff'] === [] && $view['notes'] === 'The port is set on the Startup page.', 'A no-change answer must explain itself without an apply button.');
        [$helper, $server, $user] = harness();
        foreach (range(1, 10) as $ignored) { RateLimiter::hit('wingman-ai:config:1:7', 3600); }
        rejects(fn () => $helper->propose($server, $user, 'change something'), 'wait a while');
    });

    $runTest('applying rechecks the file, keeps a backup, and is bound to the requester', function () use ($whitelist) {
        [$helper, $server, $user, $client, $files] = harness();
        $client->answers = [['file' => 'server.properties'], $whitelist];
        $id = $helper->propose($server, $user, 'turn on the whitelist')['id'];
        $original = $files->files['server.properties'];
        rejects(fn () => $helper->apply($server, new User(2), $id), 'expired');
        rejects(fn () => $helper->apply($server, new User(1, ['file.read']), $id), 'permission to edit');
        $files->files['server.properties'] .= "pvp=true\n";
        rejects(fn () => $helper->apply($server, $user, $id), 'changed since the suggestion');
        $files->files['server.properties'] = $original;
        check($files->writes === [], 'Refused applies must not write.');
        $message = $helper->apply($server, $user, $id);
        check(count($files->writes) === 2 && preg_match('/^server\.properties\.wingman-\d{8}-\d{6}\.bak$/', $files->writes[0][0]) === 1 && $files->writes[0][1] === $original, 'The old file must be kept as a .bak copy first.');
        check($files->writes[1][0] === 'server.properties' && str_contains($files->writes[1][1], 'white-list=true') && str_contains($files->writes[1][1], 'rcon.password=hunter22'), 'The new file must keep its real secrets.');
        check(Activity::$events[0] === 'server:file.write' && str_contains($message, 'Restart the server'), 'The write must be audited and explained.');
        rejects(fn () => $helper->apply($server, $user, $id), 'expired');
    });

    echo "$count config helper contract tests passed.\n";
}
