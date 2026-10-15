<?php

/**
 * Dependency-free contract tests for backed-up reinstalls using small Laravel/Pelican
 * doubles. Run: php tests/safe_reinstall.test.php. Not a panel smoke test.
 */

namespace App\Enums {
    enum SubuserPermission: string
    {
        case BackupCreate = 'backup.create';
        case SettingsReinstall = 'settings.reinstall';
    }
}

namespace App\Models {
    class Server
    {
        public static array $servers = [];
        public int $backup_limit = 2;
        public int $existingBackups = 0;
        public bool $conflict = false;
        public function __construct(public int $id = 7) {}
        public static function query(): object { return new class { public function find(mixed $id): ?Server { return Server::$servers[$id] ?? null; } }; }
        public function backups(): object
        {
            return new class($this->existingBackups) {
                public function __construct(private int $count) {}
                public function nonFailed(): self { return $this; }
                public function count(): int { return $this->count; }
            };
        }
        public function validateCurrentState(): void { if ($this->conflict) throw new \RuntimeException('conflict'); }
    }
    class User
    {
        public static array $users = [];
        public function __construct(public int $id = 1, public array $permissions = ['backup.create', 'settings.reinstall'], public bool $admin = false) {}
        public static function query(): object { return new class { public function find(mixed $id): ?User { return User::$users[$id] ?? null; } }; }
        public function can(\App\Enums\SubuserPermission|string $permission, Server $server): bool
        {
            return $permission === 'update' ? $this->admin : in_array($permission->value, $this->permissions, true);
        }
    }
    class Backup
    {
        public function __construct(public string $uuid = 'backup-uuid-1', public string $name = 'Wingman: before reinstall', public bool $is_successful = false, public int $server_id = 7) {}
    }
}

namespace App\Exceptions\Service\Backup {
    class TooManyBackupsException extends \RuntimeException {}
}

namespace Symfony\Component\HttpKernel\Exception {
    class TooManyRequestsHttpException extends \RuntimeException {}
}

namespace App\Services\Backups {
    class InitiateBackupService
    {
        public array $calls = [];
        public ?\Throwable $failure = null;
        public function handle(\App\Models\Server $server, ?string $name = null, bool $override = false): \App\Models\Backup
        {
            $this->calls[] = [$server->id, $name, $override];
            if ($this->failure !== null) throw $this->failure;
            return new \App\Models\Backup();
        }
    }
}

namespace App\Services\Servers {
    class ReinstallServerService
    {
        public array $calls = [];
        public function handle(\App\Models\Server $server): \App\Models\Server { $this->calls[] = $server->id; return $server; }
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

namespace Illuminate\Support\Facades {
    class Cache
    {
        public static array $data = [];
        public static function get(string $key): mixed { return self::$data[$key] ?? null; }
        public static function put(string $key, mixed $value, mixed $ttl): void { self::$data[$key] = $value; }
        public static function pull(string $key): mixed { $value = self::$data[$key] ?? null; unset(self::$data[$key]); return $value; }
        public static function forget(string $key): void { unset(self::$data[$key]); }
    }
    class Bus
    {
        public static array $jobs = [];
        public static bool $fail = false;
        public static function dispatch(object $job): void { if (self::$fail) throw new \RuntimeException('queue down'); self::$jobs[] = $job; }
    }
}

namespace Illuminate\Validation {
    class ValidationException extends \RuntimeException
    {
        public static function withMessages(array $messages): self { return new self((string) reset($messages)); }
    }
}

namespace Illuminate\Bus { trait Queueable {} }
namespace Illuminate\Contracts\Queue { interface ShouldQueue {} }
namespace Illuminate\Foundation\Bus { trait Dispatchable {} }
namespace Illuminate\Queue { trait InteractsWithQueue {} }

namespace {
    use App\Exceptions\Service\Backup\TooManyBackupsException;
    use App\Facades\Activity;
    use App\Models\Backup;
    use App\Models\Server;
    use App\Models\User;
    use App\Services\Backups\InitiateBackupService;
    use App\Services\Servers\ReinstallServerService;
    use Illuminate\Support\Facades\Bus;
    use Illuminate\Support\Facades\Cache;
    use Pelican\AiHelpAssistant\Jobs\ReinstallAfterBackup;
    use Pelican\AiHelpAssistant\Services\SafeReinstallService;

    function app(string $class): object { return $GLOBALS['bindings'][$class]; }
    function now(): object { return new class { public function addSeconds(int $seconds): int { return $seconds; } public function addDay(): int { return 86400; } }; }

    require __DIR__ . '/../src/Jobs/ReinstallAfterBackup.php';
    require __DIR__ . '/../src/Services/SafeReinstallService.php';

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
        Bus::$jobs = [];
        Bus::$fail = false;
        Activity::$events = [];
        $server = new Server();
        $user = new User();
        Server::$servers = [7 => $server];
        User::$users = [1 => $user];
        $GLOBALS['bindings'] = [InitiateBackupService::class => new InitiateBackupService(), ReinstallServerService::class => new ReinstallServerService(), SafeReinstallService::class => new SafeReinstallService()];

        return [app(SafeReinstallService::class), $server, $user];
    }
    function reinstalls(): array { return app(ReinstallServerService::class)->calls; }

    $count = 0;
    $runTest = function (string $name, callable $test) use (&$count): void {
        $test();
        $count++;
        echo "PASS $name\n";
    };

    $runTest('backup availability explains every reason it is not possible', function () {
        [$service, $server, $user] = harness();
        check($service->backupStatus($server, $user) === ['possible' => true, 'reason' => null], 'A permitted backup under the limit must be possible.');
        check(str_contains($service->backupStatus($server, new User(1, ['settings.reinstall']))['reason'], 'permission to create backups'), 'Missing backup permission must be explained.');
        $server->backup_limit = 0;
        check(str_contains($service->backupStatus($server, $user)['reason'], 'allows 0 backups'), 'A zero backup limit must be explained.');
        $server->backup_limit = 1;
        $server->existingBackups = 1;
        check(str_contains($service->backupStatus($server, $user)['reason'], 'maximum of 1 backup.'), 'A full backup limit must be explained.');
    });

    $runTest('without a backup the reinstall starts at once and is audited', function () {
        [$service, $server, $user] = harness();
        $message = $service->reinstall($server, $user, false, 'repair');
        check(reinstalls() === [7] && Activity::$events === ['server:settings.reinstall'] && str_contains($message, 'Reinstall started'), 'The reinstall must run once.');
        check(app(InitiateBackupService::class)->calls === [] && Cache::$data === [], 'No backup may be made when not requested.');
    });

    $runTest('with a backup, the reinstall waits and impossible or failed backups refuse first', function () {
        [$service, $server, $user] = harness();
        $server->backup_limit = 0;
        rejects(fn () => $service->reinstall($server, $user, true, 'repair'), 'No backup could be made: This server allows 0 backups');
        $server->backup_limit = 2;
        app(InitiateBackupService::class)->failure = new TooManyBackupsException('Cannot create a new backup, this server has reached its limit of 2 backups.');
        rejects(fn () => $service->reinstall($server, $user, true, 'repair'), 'reached its limit of 2 backups');
        app(InitiateBackupService::class)->failure = new RuntimeException('internal adapter detail');
        rejects(fn () => $service->reinstall($server, $user, true, 'repair'), 'backup location');
        check(reinstalls() === [] && Cache::$data === [], 'A refused backup must not reinstall or leave markers.');

        app(InitiateBackupService::class)->failure = null;
        $message = $service->reinstall($server, $user, true, 'repair');
        check(str_contains($message, 'Backing up first') && reinstalls() === [] && Activity::$events === ['server:backup.start'], 'The reinstall must wait for the backup.');
        check(end(app(InitiateBackupService::class)->calls) === [7, 'Wingman: before reinstall', false], 'The backup must be named and must never delete older backups.');
        check($service->pending($server)['backup'] === 'backup-uuid-1', 'The waiting reinstall must be visible.');
        rejects(fn () => $service->reinstall($server, $user, true, 'repair'), 'already in progress');
    });

    $runTest('a finished backup continues only the matching, successful, still-permitted reinstall', function () {
        [$service, $server, $user] = harness();
        $service->backupFinished(new Backup('unknown-backup', 'x', true));
        check(Bus::$jobs === [] && $service->lastNotice($server) === null, 'An unrelated backup must be ignored.');

        $service->reinstall($server, $user, true, 'repair');
        $service->backupFinished(new Backup('backup-uuid-1', 'x', false));
        check(reinstalls() === [] && Bus::$jobs === [] && $service->pending($server) === null, 'A failed backup must cancel the reinstall.');
        check(str_contains($service->lastNotice($server)['message'], 'backup failed'), 'A failed backup must be explained.');

        $service->reinstall($server, $user, true, 'repair');
        $service->backupFinished(new Backup('backup-uuid-1', 'x', true));
        check(count(Bus::$jobs) === 1 && Bus::$jobs[0] instanceof ReinstallAfterBackup && reinstalls() === [], 'A successful backup must queue the reinstall.');
        Bus::$jobs[0]->handle($service);
        check(reinstalls() === [7] && str_contains($service->lastNotice($server)['message'], 'reinstall started'), 'The queued reinstall must run.');
        $service->backupFinished(new Backup('backup-uuid-1', 'x', true));
        check(count(Bus::$jobs) === 1, 'A completion event must be handled only once.');

        [$service, $server, $user] = harness();
        $service->reinstall($server, $user, true, 'repair');
        Bus::$fail = true;
        $service->backupFinished(new Backup('backup-uuid-1', 'x', true));
        check(reinstalls() === [7], 'Without a queue the reinstall must still run.');
    });

    $runTest('the reinstall after a backup rechecks permission and server state', function () {
        [$service, $server] = harness();
        User::$users[1] = new User(1, ['backup.create']);
        $service->finish(7, 1, 'repair');
        check(reinstalls() === [] && str_contains($service->lastNotice($server)['message'], 'permission to reinstall was removed'), 'A revoked permission must cancel the reinstall.');
        User::$users[1] = new User(1, [], true);
        $server->conflict = true;
        $service->finish(7, 1, 'egg');
        check(reinstalls() === [] && str_contains($service->lastNotice($server)['message'], 'server was busy'), 'A busy server must not be reinstalled.');
        $server->conflict = false;
        $service->finish(7, 1, 'egg');
        check(reinstalls() === [7], 'An admin egg reinstall must run with admin access.');
        User::$users[1] = new User(1, ['settings.reinstall'], false);
        $service->finish(7, 1, 'egg');
        check(reinstalls() === [7], 'An egg reinstall must require admin access, not only the subuser permission.');
        $service->finish(99, 1, 'repair');
        check(reinstalls() === [7], 'A deleted server must be ignored.');
    });

    echo "$count safe reinstall contract tests passed.\n";
}
