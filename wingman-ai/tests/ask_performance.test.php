<?php

/**
 * Dependency-free contract tests for Ask Wingman and the performance check using
 * small Laravel/Pelican doubles. Run: php tests/ask_performance.test.php.
 */

namespace App\Enums {
    enum SubuserPermission: string
    {
        case WebsocketConnect = 'websocket.connect';
        case StartupRead = 'startup.read';
    }
}

namespace App\Models {
    class User
    {
        public function __construct(public int $id = 1, public array $permissions = ['websocket.connect', 'startup.read']) {}
        public function can(\App\Enums\SubuserPermission $permission, Server $server): bool { return in_array($permission->value, $this->permissions, true); }
    }
    class Server
    {
        public int $id = 7;
        public object $egg;
        public int $memory = 2048;
        public int $cpu = 200;
        public int $disk = 10000;
        public string $startup = 'java -Xmx2048M -jar server.jar --password hunter2x';
        public string $image = 'ghcr.io/pelican-eggs/yolks:java_21';
        public function __construct() { $this->egg = (object) ['name' => 'Paper']; }
        public function variables(): object
        {
            return new class { public function get(): array { return [(object) ['env_variable' => 'RCON_PASSWORD', 'server_value' => 'hunter2x', 'default_value' => '', 'user_viewable' => true]]; } };
        }
    }
}

namespace App\Repositories\Daemon {
    class DaemonServerRepository
    {
        public array $details = [];
        public int $calls = 0;
        public function setServer(\App\Models\Server $server): self { return $this; }
        public function getDetails(int $timeout = 1): array
        {
            $next = $this->details[min($this->calls, count($this->details) - 1)] ?? new \RuntimeException('Wings offline');
            $this->calls++;
            if ($next instanceof \Throwable) throw $next;
            return $next;
        }
    }
}

namespace Illuminate\Support\Facades {
    class Cache
    {
        public static array $data = [];
        public static function get(string $key, mixed $default = null): mixed { return self::$data[$key] ?? $default; }
        public static function add(string $key, mixed $value, mixed $ttl): bool { if (isset(self::$data[$key])) return false; self::$data[$key] = $value; return true; }
        public static function increment(string $key): int { return ++self::$data[$key]; }
    }
    class RateLimiter
    {
        public static array $counts = [];
        public static function tooManyAttempts(string $key, int $limit): bool { return (self::$counts[$key] ?? 0) >= $limit; }
        public static function hit(string $key, int $seconds): void { self::$counts[$key] = (self::$counts[$key] ?? 0) + 1; }
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
        public array $values = ['enabled' => true, 'ask_enabled' => true, 'ask_daily_limit' => 2, 'daily_limit' => 1, 'ai_enabled' => true,
            'base_url' => 'https://ai.example.test/v1', 'model' => 'test', 'api_key' => 'provider-private-key-778899', 'max_response_tokens' => 6000];
        public function get(): array { return $this->values; }
    }
    class AiClient
    {
        public array $calls = [];
        public string|\Throwable $answer = '';
        public function analyze(array $settings, array $input, ?string $instructions = null, ?int $maxTokens = null, int $timeout = 45): string
        {
            $this->calls[] = compact('input', 'instructions', 'maxTokens');
            if ($this->answer instanceof \Throwable) throw $this->answer;
            return $this->answer;
        }
    }
    class ConsoleHistory
    {
        public string $history = "[12:00:00] Player joined\n[12:00:05] rcon login hunter2x\n";
        public function recent(\App\Models\Server $server, int $lines = 100, int $maxBytes = 48000): string { return $this->history; }
    }
}

namespace {
    use App\Models\Server;
    use App\Models\User;
    use App\Repositories\Daemon\DaemonServerRepository;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\RateLimiter;
    use Pelican\AiHelpAssistant\Services\AiClient;
    use Pelican\AiHelpAssistant\Services\AskService;
    use Pelican\AiHelpAssistant\Services\AssistantSettings;
    use Pelican\AiHelpAssistant\Services\ConsoleHistory;
    use Pelican\AiHelpAssistant\Services\PerformanceService;

    function app(string $class): object { return $GLOBALS['bindings'][$class]; }
    function now(): object { return new class { public function addDay(): int { return 86400; } }; }

    require __DIR__ . '/../src/Services/AiServiceException.php';
    foreach (['Support/SecretRedactor', 'Support/ReportNormalizer', 'Support/PerformanceRules', 'Services/AskService', 'Services/PerformanceService'] as $file) {
        require __DIR__ . '/../src/' . $file . '.php';
    }

    class InstantPerformanceService extends PerformanceService
    {
        protected function pause(): void {}
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
        $settings = new AssistantSettings();
        $client = new AiClient();
        $GLOBALS['bindings'] = [ConsoleHistory::class => new ConsoleHistory(), DaemonServerRepository::class => new DaemonServerRepository()];

        return [$settings, $client, new Server(), new User()];
    }

    $count = 0;
    $runTest = function (string $name, callable $test) use (&$count): void {
        $test();
        $count++;
        echo "PASS $name\n";
    };

    $runTest('ask is available only with an AI connection, the setting, and console access', function () {
        [$settings, $client, $server, $user] = harness();
        $ask = new AskService($settings, $client);
        check($ask->available($server, $user), 'Ask must be available by default with AI configured.');
        check(!$ask->available($server, new User(2, ['startup.read'])), 'Ask needs console access.');
        $settings->values['ask_enabled'] = false;
        check(!$ask->available($server, $user), 'The setting must hide Ask.');
        $settings->values = array_replace($settings->values, ['ask_enabled' => true, 'model' => '']);
        check(!$ask->available($server, $user), 'Ask needs a configured model.');
        rejects(fn () => $ask->ask($server, $user, 'Why is it slow?'), 'not available');
        check($client->calls === [], 'An unavailable ask must not reach the AI.');
    });

    $runTest('questions are validated, limited per user, and answered without secrets or Markdown', function () {
        [$settings, $client, $server, $user] = harness();
        $ask = new AskService($settings, $client);
        rejects(fn () => $ask->ask($server, $user, ' a '), 'Type a question');
        rejects(fn () => $ask->ask($server, $user, str_repeat('x', 501)), 'under 500 characters');
        $client->answer = "**Short answer:** add mods to the mods folder.\n```\nrm -rf /\n```\nYour key provider-private-key-778899 and hunter2x.";
        $answer = $ask->ask($server, $user, "How   do I\nadd mods?");
        check($client->calls[0]['input']['question'] === 'How do I add mods?', 'Whitespace must be collapsed.');
        $sent = json_encode($client->calls[0]['input']);
        check(str_contains($sent, 'Paper') && str_contains($sent, 'Player joined') && str_contains($sent, 'java -Xmx2048M'), 'The game, console, and startup must be context.');
        check(!str_contains($sent, 'hunter2x') && !str_contains($sent, 'provider-private-key-778899'), 'Secrets must never reach the AI.');
        check(!str_contains($answer, 'hunter2x') && !str_contains($answer, 'provider-private-key') && !str_contains($answer, '**') && !str_contains($answer, '```'), 'The answer must be redacted plain text.');
        check(str_contains($client->calls[0]['instructions'], 'UNTRUSTED DATA') && $client->calls[0]['maxTokens'] === 4000, 'Ask must use its own bounded prompt.');
        $ask->ask($server, $user, 'Second question?');
        rejects(fn () => $ask->ask($server, $user, 'Third question?'), "today's question limit");
        check(count($client->calls) === 2, 'The daily limit must stop further AI requests.');

        [$settings, $client, $server, $user] = harness();
        $settings->values['ask_daily_limit'] = 100;
        $ask = new AskService($settings, $client);
        $client->answer = 'Answer.';
        foreach (['One?', 'Two?', 'Three?'] as $question) { $ask->ask($server, $user, $question); }
        rejects(fn () => $ask->ask($server, $user, 'Four?'), 'wait a moment');
        $context = (function () use ($settings, $server) { $client = new AiClient(); $client->answer = 'Ok.'; RateLimiter::$counts = []; (new AskService($settings, $client))->ask($server, new User(3, ['websocket.connect']), 'Without startup?'); return json_encode($client->calls[0]['input']); })();
        check(!str_contains($context, 'java -Xmx') && !str_contains($context, 'yolks'), 'Startup details need startup access.');
        $client->answer = new RuntimeException('HTTP 500 secret provider body');
        RateLimiter::$counts = [];
        rejects(fn () => $ask->ask($server, $user, 'Five?'), 'could not get an answer');
    });

    $runTest('performance checks sample a running server and stop early when it is offline', function () {
        [$settings, $client, $server, $user] = harness();
        $settings->values['ai_enabled'] = false;
        $wings = app(DaemonServerRepository::class);
        $wings->details = [['state' => 'running', 'utilization' => ['cpu_absolute' => 190, 'memory_bytes' => 1990 * 1048576, 'disk_bytes' => 1048576]]];
        $report = (new InstantPerformanceService($settings, $client))->check($server, $user);
        check($wings->calls === 3 && $report['kind'] === 'performance' && $report['source'] === 'rules', 'A running server must be sampled three times.');
        check(str_contains($report['summary'], 'Memory is nearly full') && $client->calls === [], 'Without AI the built-in analysis must be returned.');
        [$settings, $client, $server, $user] = harness();
        app(DaemonServerRepository::class)->details = [['state' => 'offline', 'utilization' => []]];
        $report = (new InstantPerformanceService($settings, $client))->check($server, $user);
        check(app(DaemonServerRepository::class)->calls === 1 && $report['summary'] === 'The server is not running, so there is nothing to measure.' && $client->calls === [], 'An offline server must not be sampled further or sent to AI.');
        [$settings, $client, $server, $user] = harness();
        rejects(fn () => (new InstantPerformanceService($settings, $client))->check($server, $user), 'could not reach this server');
        rejects(fn () => (new InstantPerformanceService($settings, $client))->check($server, new User(2, ['startup.read'])), 'console access');
    });

    $runTest('performance AI explanations are bounded, secret-free, and share the daily limit', function () {
        [$settings, $client, $server, $user] = harness();
        app(DaemonServerRepository::class)->details = [['state' => 'running', 'utilization' => ['cpu_absolute' => 10, 'memory_bytes' => 1048576, 'disk_bytes' => 1048576]]];
        $client->answer = json_encode(['summary' => 'Lag comes from a heavy farm.', 'likely_cause' => 'Many entities.', 'confidence' => 'medium', 'evidence' => [], 'steps' => ['Reduce the farm.'], 'needs_more_information' => []]);
        $service = new InstantPerformanceService($settings, $client);
        $report = $service->check($server, $user);
        check($report['source'] === 'ai' && $report['kind'] === 'performance' && str_contains($report['notice'], 'short check'), 'An AI explanation must be labelled.');
        $sent = json_encode($client->calls[0]['input']);
        check(!str_contains($sent, 'hunter2x') && !str_contains($sent, 'provider-private-key-778899') && str_contains($sent, 'Paper'), 'The AI must get context without secrets.');
        $report = $service->check($server, $user);
        check(count($client->calls) === 1 && $report['source'] === 'rules', 'Over the daily limit, only built-in checks may run.');
        [$settings, $client, $server, $user] = harness();
        app(DaemonServerRepository::class)->details = [['state' => 'running', 'utilization' => ['cpu_absolute' => 10, 'memory_bytes' => 1048576, 'disk_bytes' => 1048576]]];
        $client->answer = new RuntimeException('HTTP 500 secret provider body');
        $report = (new InstantPerformanceService($settings, $client))->check($server, $user);
        check($report['source'] === 'rules' && str_contains($report['notice'], 'Basic checks are shown instead') && !str_contains(json_encode($report), 'secret provider body'), 'An AI failure must fall back without details.');
        $service = new InstantPerformanceService($settings, $client);
        foreach (range(1, 5) as $ignored) { $service->check($server, $user); }
        rejects(fn () => $service->check($server, $user), 'wait a few minutes');
    });

    echo "$count ask and performance contract tests passed.\n";
}
