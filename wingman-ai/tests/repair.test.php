<?php

/**
 * Dependency-free contract tests for one-click repairs using small Laravel/Pelican
 * doubles. Run: php tests/repair.test.php. These do not replace panel smoke tests.
 */

namespace App\Enums {
    enum SubuserPermission: string
    {
        case StartupDockerImage = 'startup.docker-image';
        case SettingsReinstall = 'settings.reinstall';
        case FileUpdate = 'file.update';
        case ControlRestart = 'control.restart';
    }
}

namespace App\Models {
    class Server
    {
        public object $egg;
        public bool $conflict = false;
        public bool $saved = false;
        public function __construct(public int $id = 1, public string $image = 'ghcr.io/pelican-eggs/yolks:java_17')
        {
            $this->egg = (object) ['docker_images' => [
                'Java 8' => 'ghcr.io/pelican-eggs/yolks:java_8',
                'Java 17' => 'ghcr.io/pelican-eggs/yolks:java_17',
                'Java 21' => 'ghcr.io/pelican-eggs/yolks:java_21',
                'Java 25' => 'ghcr.io/pelican-eggs/yolks:java_25',
            ]];
        }
        public function refresh(): void {}
        public function validateCurrentState(): void
        {
            if ($this->conflict) throw new \RuntimeException('conflict');
        }
        public function forceFill(array $values): self
        {
            foreach ($values as $key => $value) $this->{$key} = $value;
            return $this;
        }
        public function saveOrFail(): bool { return $this->saved = true; }
    }
    class User
    {
        public function __construct(public array $permissions = [], public int $id = 1) {}
        public function can(\App\Enums\SubuserPermission $permission, Server $server): bool
        {
            return in_array($permission->value, $this->permissions, true);
        }
    }
}

namespace App\Facades {
    class Activity
    {
        public static array $events = [];
        public static function event(string $event): self { self::$events[] = [$event]; return new self(); }
        public function subject(mixed $subject): self { return $this; }
        public function actor(mixed $actor): self { return $this; }
        public function property(string|array $key, mixed $value = null): self
        {
            $last = array_key_last(self::$events);
            self::$events[$last][] = is_array($key) ? $key : [$key => $value];
            return $this;
        }
        public function log(): void {}
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
        public function setServer(\App\Models\Server $server): self { return $this; }
        public function power(string $action): TestResponse { $GLOBALS['actions'][] = 'power:' . $action; return new TestResponse(); }
    }
    class DaemonFileRepository
    {
        public array $writes = [];
        public function setServer(\App\Models\Server $server): self { return $this; }
        public function putContent(string $path, string $content): void { $GLOBALS['actions'][] = 'write:' . $path . '=' . $content; }
    }
}

namespace App\Services\Servers {
    class ReinstallServerService
    {
        public function handle(\App\Models\Server $server): \App\Models\Server { $GLOBALS['actions'][] = 'reinstall:' . $server->id; return $server; }
    }
}

namespace Illuminate\Support\Facades {
    class TestLock
    {
        public function block(int $seconds, callable $callback): mixed { return $callback(); }
    }
    class Cache
    {
        public static array $locks = [];
        public static function lock(string $key, int $seconds): TestLock { self::$locks[] = $key; return new TestLock(); }
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
    class AutomaticStartupService
    {
        public ?\Throwable $failure = null;
        public function attempt(\App\Models\Server $server, string $action = 'start'): void
        {
            $GLOBALS['actions'][] = 'observe:' . $action;
            if ($this->failure !== null) throw $this->failure;
        }
    }
}

namespace {
    use App\Facades\Activity;
    use App\Models\Server;
    use App\Models\User;
    use App\Repositories\Daemon\DaemonFileRepository;
    use App\Repositories\Daemon\DaemonServerRepository;
    use App\Services\Servers\ReinstallServerService;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\RateLimiter;
    use Pelican\AiHelpAssistant\Services\AutomaticStartupService;
    use Pelican\AiHelpAssistant\Services\RepairService;

    function app(string $class): object { return $GLOBALS['bindings'][$class]; }

    require __DIR__ . '/../src/Services/RepairService.php';

    function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
    function rejects(callable $operation, string $contains): void
    {
        try { $operation(); } catch (Throwable $exception) {
            check(str_contains($exception->getMessage(), $contains), 'Wrong rejection: ' . $exception->getMessage());
            return;
        }
        throw new RuntimeException('Expected rejection containing: ' . $contains);
    }
    function harness(array $permissions): array
    {
        Cache::$locks = [];
        RateLimiter::$counts = [];
        Activity::$events = [];
        $GLOBALS['actions'] = [];
        $GLOBALS['powerFailure'] = false;
        $GLOBALS['bindings'] = [
            AutomaticStartupService::class => new AutomaticStartupService(),
            DaemonServerRepository::class => new DaemonServerRepository(),
            DaemonFileRepository::class => new DaemonFileRepository(),
            ReinstallServerService::class => new ReinstallServerService(),
        ];

        return [new RepairService(), new Server(), new User($permissions)];
    }
    function eventNames(): array { return array_map(fn (array $event) => $event[0], Activity::$events); }

    $count = 0;
    $runTest = function (string $name, callable $test) use (&$count): void {
        $test();
        $count++;
        echo "PASS $name\n";
    };

    $java = ['fix' => ['type' => 'java_image', 'java' => 21]];
    $reinstall = ['fix' => ['type' => 'reinstall']];
    $eula = ['fix' => ['type' => 'eula']];

    $runTest('reports without a known repair offer nothing', function () {
        [$repairs, $server, $user] = harness(['startup.docker-image', 'settings.reinstall', 'file.update', 'control.restart']);
        foreach ([null, [], ['fix' => null], ['fix' => 'reinstall'], ['fix' => ['type' => 'delete_files']], ['fix' => ['type' => ['reinstall']]]] as $report) {
            check($repairs->offer($server, $user, $report) === null, 'An unknown or malformed repair was offered.');
        }
        rejects(fn () => $repairs->apply($server, $user, ['fix' => ['type' => 'delete_files']]), 'no longer available');
        check($GLOBALS['actions'] === [], 'A rejected repair performed work.');
    });

    $runTest('each repair requires its own Pelican permission', function () use ($java, $reinstall, $eula) {
        foreach ([[$java, 'startup.docker-image'], [$reinstall, 'settings.reinstall'], [$eula, 'file.update']] as [$report, $permission]) {
            [$repairs, $server] = harness([]);
            $others = array_values(array_diff(['startup.docker-image', 'settings.reinstall', 'file.update', 'control.restart'], [$permission]));
            check($repairs->offer($server, new User($others), $report) === null, 'Repair offered without ' . $permission);
            rejects(fn () => $repairs->apply($server, new User($others), $report, 'ghcr.io/pelican-eggs/yolks:java_21'), 'no longer available');
            check($GLOBALS['actions'] === [] && Activity::$events === [], 'Unauthorized repair performed work for ' . $permission);
            check($repairs->offer($server, new User([$permission]), $report)['type'] === $report['fix']['type'], 'Permitted repair was not offered: ' . $permission);
        }
    });

    $runTest('Java choices come from the egg and suggest the closest compatible version', function () use ($java) {
        [$repairs, $server, $user] = harness(['startup.docker-image']);
        $offer = $repairs->offer($server, $user, $java);
        check(array_keys($offer['images']) === ['ghcr.io/pelican-eggs/yolks:java_8', 'ghcr.io/pelican-eggs/yolks:java_21', 'ghcr.io/pelican-eggs/yolks:java_25'], 'Image choices must be the egg list without the current image.');
        check($offer['images']['ghcr.io/pelican-eggs/yolks:java_21'] === 'Java 21', 'Image labels must come from the egg.');
        check($offer['suggested'] === 'ghcr.io/pelican-eggs/yolks:java_21', 'The exact required Java version must be suggested.');
        check($repairs->offer($server, $user, ['fix' => ['type' => 'java_image', 'java' => 22]])['suggested'] === 'ghcr.io/pelican-eggs/yolks:java_25', 'The closest newer Java version must be suggested.');
        check($repairs->offer($server, $user, ['fix' => ['type' => 'java_image', 'java' => 30]])['suggested'] === null, 'No image may be suggested when none is new enough.');
        check($repairs->offer($server, $user, ['fix' => ['type' => 'java_image']])['suggested'] === null, 'An unknown requirement must not suggest an image.');
        $server->image = 'registry.example.test/admin-custom:latest';
        check($repairs->offer($server, $user, $java) === null, 'An administrator custom image must not be replaced.');
    });

    $runTest('switching Java accepts only an offered image and restarts with permission', function () use ($java) {
        [$repairs, $server, $user] = harness(['startup.docker-image']);
        foreach ([null, '', 'ghcr.io/pelican-eggs/yolks:java_17', 'attacker.example/image:latest'] as $image) {
            rejects(fn () => $repairs->apply($server, $user, $java, $image), 'Choose one of the Java images');
        }
        check($server->image === 'ghcr.io/pelican-eggs/yolks:java_17' && !$server->saved && $GLOBALS['actions'] === [], 'An unoffered image changed the server.');
        check(RateLimiter::$counts === [], 'Rejected image choices must not use up the repair limit.');
        $message = $repairs->apply($server, $user, $java, 'ghcr.io/pelican-eggs/yolks:java_21');
        check($server->image === 'ghcr.io/pelican-eggs/yolks:java_21' && $server->saved, 'The selected image was not saved.');
        check(str_contains($message, 'Java 21') && str_contains($message, 'Start the server'), 'Without restart permission the user must be told to start it.');
        check($GLOBALS['actions'] === [] && eventNames() === ['server:startup.image'], 'Repair restarted without the user\'s restart permission.');
        check(Activity::$events[0][1] === ['old' => 'ghcr.io/pelican-eggs/yolks:java_17', 'new' => 'ghcr.io/pelican-eggs/yolks:java_21', 'source' => 'wingman-ai'], 'Image change must be audited with old and new values.');
        check(Cache::$locks === ['wingman-ai:power:1'], 'Repairs must share the diagnostic power lock.');

        [$repairs, $server] = harness([]);
        $message = $repairs->apply($server, new User(['startup.docker-image', 'control.restart']), $java, 'ghcr.io/pelican-eggs/yolks:java_25');
        check($GLOBALS['actions'] === ['observe:restart', 'power:restart'] && str_contains($message, 'restarting'), 'A permitted restart must be observed, then sent.');
        check(eventNames() === ['server:startup.image', 'server:power.restart'], 'The restart must be audited.');
    });

    $runTest('reinstall runs Pelican reinstall once and never sends power', function () use ($reinstall) {
        [$repairs, $server, $user] = harness(['settings.reinstall', 'control.restart']);
        $message = $repairs->apply($server, $user, $reinstall);
        check($GLOBALS['actions'] === ['reinstall:1'] && str_contains($message, 'Reinstall started'), 'Reinstall did not run exactly once.');
        check(eventNames() === ['server:settings.reinstall'], 'Reinstall must be audited.');
    });

    $runTest('EULA acceptance writes only eula.txt and restarts with permission', function () use ($eula) {
        [$repairs, $server, $user] = harness(['file.update', 'control.restart']);
        $message = $repairs->apply($server, $user, $eula, 'ignored-image');
        check($GLOBALS['actions'] === ['write:eula.txt=eula=true', 'observe:restart', 'power:restart'], 'EULA repair wrote or restarted unexpectedly.');
        check(str_contains($message, 'EULA accepted') && eventNames() === ['server:file.write', 'server:power.restart'], 'EULA repair must be audited.');
        check(Activity::$events[0][1] === ['file' => '/eula.txt', 'source' => 'wingman-ai'], 'EULA write must name the file.');
    });

    $runTest('busy servers, rate limits, and failures stop repairs safely', function () use ($reinstall, $eula) {
        [$repairs, $server, $user] = harness(['settings.reinstall', 'file.update', 'control.restart']);
        $server->conflict = true;
        rejects(fn () => $repairs->apply($server, $user, $reinstall), 'installing, restoring, transferring, or suspended');
        check($GLOBALS['actions'] === [], 'A busy server was repaired.');
        $server->conflict = false;
        $repairs->apply($server, $user, $reinstall);
        $repairs->apply($server, $user, $reinstall);
        rejects(fn () => $repairs->apply($server, $user, $reinstall), 'Please wait a few minutes');
        check(count(array_filter($GLOBALS['actions'], fn ($action) => str_starts_with($action, 'reinstall:'))) === 2, 'Rate limit must stop further repairs.');

        [$repairs, $server, $user] = harness(['file.update', 'control.restart']);
        $GLOBALS['bindings'][AutomaticStartupService::class]->failure = new RuntimeException('queue down');
        $repairs->apply($server, $user, $eula);
        check(in_array('power:restart', $GLOBALS['actions'], true), 'An observation failure must not block the restart.');
        [$repairs, $server, $user] = harness(['file.update', 'control.restart']);
        $GLOBALS['powerFailure'] = true;
        rejects(fn () => $repairs->apply($server, $user, $eula), 'HTTP power failure');
        check(!in_array('server:power.restart', eventNames(), true), 'A failed restart must not be audited as sent.');
    });

    echo "$count repair contract tests passed.\n";
}
