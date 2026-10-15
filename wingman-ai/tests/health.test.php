<?php

/**
 * Dependency-free contract tests for backup doctor, the 24-hour summary, and usage
 * tracking, using small Laravel/Pelican doubles. Run: php tests/health.test.php.
 */

namespace App\Enums {
    enum SubuserPermission: string
    {
        case BackupRead = 'backup.read';
        case BackupCreate = 'backup.create';
        case ScheduleCreate = 'schedule.create';
        case ActivityRead = 'activity.read';
    }
}

namespace Illuminate\Support {
    class Carbon
    {
        public static ?int $now = null;
        public function __construct(public \DateTimeImmutable $time) {}
        public static function now(): self { return new self((new \DateTimeImmutable('@' . (self::$now ?? time())))); }
        public static function parse(string $value): self { return new self(new \DateTimeImmutable($value)); }
        public function subDay(): self { return new self($this->time->modify('-1 day')); }
        public function subDays(int $days): self { return new self($this->time->modify("-$days days")); }
        public function lt(self $other): bool { return $this->time < $other->time; }
        public function diffInDays(self $other): float { return abs($other->time->getTimestamp() - $this->time->getTimestamp()) / 86400; }
        public function toIso8601String(): string { return $this->time->format('c'); }
        public function getTimestamp(): int { return $this->time->getTimestamp(); }
    }
}

namespace App\Models {
    class Node { public bool $maintenance = false; public function isUnderMaintenance(): bool { return $this->maintenance; } }
    class FakeBuilder
    {
        private array $filters = [];
        public function __construct(private array $rows, private ?\Closure $onExists = null) {}
        public function where(string $column, mixed $operator, mixed $value = null): self
        {
            [$operator, $value] = func_num_args() === 2 ? ['=', $operator] : [$operator, $value];
            $this->filters[] = function (array $row) use ($column, $operator, $value): bool {
                $cell = $row[$column] ?? null;
                if ($cell instanceof \Illuminate\Support\Carbon) $cell = $cell->getTimestamp();
                if ($value instanceof \Illuminate\Support\Carbon) $value = $value->getTimestamp();
                return match ($operator) { '>=' => $cell !== null && $cell >= $value, default => $cell === $value };
            };
            return $this;
        }
        public function whereNotNull(string $column): self { $this->filters[] = fn (array $row) => ($row[$column] ?? null) !== null; return $this; }
        public function whereHas(string $relation, \Closure $callback): self { $this->filters[] = fn (array $row) => in_array('backup', $row['actions'] ?? [], true); return $this; }
        private function matching(): array { return array_values(array_filter($this->rows, fn (array $row) => array_reduce($this->filters, fn (bool $ok, \Closure $filter) => $ok && $filter($row), true))); }
        public function count(): int { return count($this->matching()); }
        public function exists(): bool { return $this->matching() !== []; }
        public function max(string $column): mixed
        {
            $values = array_map(fn (array $row) => $row[$column], $this->matching());
            if ($values === []) return null;
            usort($values, fn ($a, $b) => $a->getTimestamp() <=> $b->getTimestamp());
            return end($values)->toIso8601String();
        }
    }
    class Server
    {
        public static array $all = [];
        public ?string $status = null;
        public int $backup_limit = 2;
        public int $memory = 2048;
        public int $cpu = 200;
        public int $disk = 10000;
        public array $backupRows = [];
        public array $scheduleRows = [];
        public Node $node;
        public function __construct(public int $id = 7) { $this->node = new Node(); }
        public function getMorphClass(): string { return 'server'; }
        public function backups(): FakeBuilder { return new FakeBuilder($this->backupRows); }
        public function schedules(): FakeBuilder { return new FakeBuilder($this->scheduleRows); }
        public static function query(): object
        {
            return new class {
                public function whereNull(string $column): self { return $this; }
                public function orderBy(string $column): self { return $this; }
                public function limit(int $count): self { return $this; }
                public function get(): array { return array_values(array_filter(Server::$all, fn (Server $server) => $server->status === null)); }
            };
        }
    }
    class User
    {
        public ?string $timezone = 'America/New_York';
        public function __construct(public array $permissions = ['backup.read', 'backup.create', 'schedule.create', 'activity.read'], public bool $admin = false, public int $id = 1) {}
        public function can(\App\Enums\SubuserPermission|string $permission, Server $server): bool
        {
            return $permission === 'update' ? $this->admin : in_array($permission->value, $this->permissions, true);
        }
    }
    class Schedule
    {
        public static array $saved = [];
        public ?int $id = null;
        public ?string $next_run_at = null;
        public array $values = [];
        public function forceFill(array $values): self { $this->values = $values; return $this; }
        public function getNextRunDate(): string { return '2026-10-16 04:' . str_pad($this->values['cron_minute'], 2, '0', STR_PAD_LEFT) . ':00'; }
        public function saveOrFail(): bool { $this->id = count(self::$saved) + 1; self::$saved[] = $this; return true; }
    }
    class Task
    {
        public static array $saved = [];
        public array $values = [];
        public function forceFill(array $values): self { $this->values = $values; return $this; }
        public function saveOrFail(): bool { self::$saved[] = $this->values; return true; }
    }
    class ActivityLog
    {
        public static array $rows = [];
        public static function query(): object
        {
            return new class {
                private array $events = [];
                private int $since = 0;
                private ?int $server = null;
                public function where(string $column, string $operator, \Illuminate\Support\Carbon $value): self { $this->since = $value->getTimestamp(); return $this; }
                public function whereIn(string $column, array $values): self { $this->events = $values; return $this; }
                public function whereHas(string $relation, \Closure $callback): self
                {
                    $query = new class { public array $where = []; public function where(string $column, mixed $value): self { $this->where[$column] = $value; return $this; } };
                    $callback($query);
                    $this->server = $query->where['subject_type'] === 'server' ? $query->where['subject_id'] : -1;
                    return $this;
                }
                public function selectRaw(string $sql): self { return $this; }
                public function groupBy(string $column): self { return $this; }
                public function pluck(string $value, string $key): object
                {
                    $totals = [];
                    foreach (ActivityLog::$rows as [$event, $time, $server]) {
                        if ($server === $this->server && $time >= $this->since && in_array($event, $this->events, true)) $totals[$event] = ($totals[$event] ?? 0) + 1;
                    }
                    return new class(array_map('strval', $totals)) {
                        public function __construct(private array $items) {}
                        public function map(callable $callback): self { return new self(array_map($callback, $this->items)); }
                        public function all(): array { return $this->items; }
                    };
                }
            };
        }
    }
    class Backup { public function __construct(public string $name = 'Wingman: manual backup') {} }
}

namespace App\Exceptions\Service\Backup { class TooManyBackupsException extends \RuntimeException {} }
namespace Symfony\Component\HttpKernel\Exception { class TooManyRequestsHttpException extends \RuntimeException {} }

namespace App\Services\Backups {
    class InitiateBackupService
    {
        public array $calls = [];
        public ?\Throwable $failure = null;
        public function handle(\App\Models\Server $server, ?string $name = null, bool $override = false): \App\Models\Backup
        {
            $this->calls[] = [$server->id, $name, $override];
            if ($this->failure !== null) throw $this->failure;
            return new \App\Models\Backup((string) $name);
        }
    }
}

namespace App\Repositories\Daemon {
    class DaemonServerRepository
    {
        public array $details = [];
        private int $server = 0;
        public function setServer(\App\Models\Server $server): self { $this->server = $server->id; return $this; }
        public function getDetails(int $timeout = 1): array
        {
            $details = $this->details[$this->server] ?? new \RuntimeException('Wings offline');
            if ($details instanceof \Throwable) throw $details;
            return $details;
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

namespace Illuminate\Support\Facades {
    class Cache
    {
        public static array $data = [];
        public static function get(string $key, mixed $default = null): mixed { return self::$data[$key] ?? $default; }
        public static function put(string $key, mixed $value, mixed $ttl): void { self::$data[$key] = $value; }
    }
    class DB { public static function transaction(callable $callback): mixed { return $callback(); } }
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
        public array $values = ['enabled' => true, 'usage_tracking_enabled' => true];
        public function get(): array { return $this->values; }
    }
}

namespace {
    use App\Exceptions\Service\Backup\TooManyBackupsException;
    use App\Facades\Activity;
    use App\Models\ActivityLog;
    use App\Models\Schedule;
    use App\Models\Server;
    use App\Models\Task;
    use App\Models\User;
    use App\Repositories\Daemon\DaemonServerRepository;
    use App\Services\Backups\InitiateBackupService;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Facades\Cache;
    use Pelican\AiHelpAssistant\Services\AssistantSettings;
    use Pelican\AiHelpAssistant\Services\BackupDoctorService;
    use Pelican\AiHelpAssistant\Services\DailySummaryService;
    use Pelican\AiHelpAssistant\Services\UsageHistoryService;

    function app(string $class): object { return $GLOBALS['bindings'][$class]; }
    function now(): object { return new class { public function addDays(int $days): int { return $days; } }; }

    foreach (['Support/RightSizeRules', 'Services/BackupDoctorService', 'Services/DailySummaryService', 'Services/UsageHistoryService'] as $file) {
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
        Carbon::$now = 1792036800; // 2026-10-15 04:00:00 UTC
        Cache::$data = ['health:checks:schedule:latestHeartbeatAt' => Carbon::$now - 30];
        Activity::$events = [];
        Schedule::$saved = [];
        Task::$saved = [];
        ActivityLog::$rows = [];
        $server = new Server();
        Server::$all = [7 => $server];
        $GLOBALS['bindings'] = [InitiateBackupService::class => new InitiateBackupService(), DaemonServerRepository::class => new DaemonServerRepository(),
            AssistantSettings::class => new AssistantSettings(), UsageHistoryService::class => new UsageHistoryService()];

        return [new BackupDoctorService(), $server, new User()];
    }
    function at(string $time): Carbon { return Carbon::parse($time); }

    $count = 0;
    $runTest = function (string $name, callable $test) use (&$count): void {
        $test();
        $count++;
        echo "PASS $name\n";
    };

    $runTest('backup status explains every backup problem, adjusted for admins', function () {
        [$doctor, $server, $user] = harness();
        check($doctor->status($server, new User([])) === null, 'Backup status needs backup read access.');
        $server->backup_limit = 0;
        $status = $doctor->status($server, $user);
        check(str_contains($status['issues'][0], 'backup limit is 0. Ask your panel admin') && !$status['can_backup'] && !$status['can_schedule'], 'A zero limit must be explained without offering actions.');
        check(str_contains($doctor->status($server, new User(admin: true))['issues'][0], 'Admin → Servers'), 'Admins must be told where to raise the limit.');
        $server->backup_limit = 2;
        $status = $doctor->status($server, $user);
        check($status['issues'] === ['This server has no backups yet.', 'No automatic backups are scheduled.'] && $status['can_backup'] && $status['can_schedule'], 'Missing backups and schedule must be reported.');
        $server->backupRows = [['is_successful' => true, 'completed_at' => at('2026-10-01 04:00:00')], ['is_successful' => false, 'completed_at' => at('2026-10-14 04:00:00')]];
        $status = $doctor->status($server, $user);
        check($status['count'] === 1 && str_contains($status['issues'][0], 'The newest backup is 14 days old.'), 'Only successful backups count, and old ones are flagged.');
        $server->scheduleRows = [['is_active' => true, 'actions' => ['backup']]];
        Cache::$data['health:checks:schedule:latestHeartbeatAt'] = Carbon::$now - 3600;
        $status = $doctor->status($server, $user);
        check($status['scheduled'] && !$status['can_schedule'] && (bool) array_filter($status['issues'], fn ($issue) => str_contains($issue, 'task timer (cron)')), 'A schedule that cannot run must be flagged.');
    });

    $runTest('nightly backups create one Pelican schedule with a backup task', function () {
        [$doctor, $server, $user] = harness();
        rejects(fn () => $doctor->scheduleNightly($server, new User(['backup.read', 'backup.create'])), 'permission to create schedules');
        $message = $doctor->scheduleNightly($server, $user);
        $schedule = Schedule::$saved[0];
        check($schedule->values['server_id'] === 7 && $schedule->values['name'] === 'Wingman: nightly backup' && $schedule->values['cron_hour'] === '4' && $schedule->values['cron_minute'] === '49', 'The schedule must run at 4 AM, spread by server.');
        check($schedule->values['timezone'] === 'America/New_York' && $schedule->next_run_at === '2026-10-16 04:49:00' && $schedule->values['is_active'] === true, 'The schedule must use the user\'s timezone and a next run time.');
        check(Task::$saved === [['schedule_id' => 1, 'sequence_id' => 1, 'action' => 'backup', 'payload' => '', 'time_offset' => 0, 'continue_on_failure' => false]], 'Exactly one backup task must be created.');
        check(Activity::$events === ['server:schedule.create'] && str_contains($message, '4:49 (America/New_York)') && str_contains($message, 'oldest unlocked backup is replaced'), 'The change must be audited and explained.');
        $server->scheduleRows = [['is_active' => true, 'actions' => ['backup']]];
        rejects(fn () => $doctor->scheduleNightly($server, $user), 'already has an automatic backup schedule');
        $server->scheduleRows = [];
        $server->backup_limit = 0;
        rejects(fn () => $doctor->scheduleNightly($server, $user), 'backup limit is 0');
        check(count(Schedule::$saved) === 1, 'Refusals must not create schedules.');
    });

    $runTest('backing up now uses the panel backup service and explains failures', function () {
        [$doctor, $server, $user] = harness();
        check(str_contains($doctor->backupNow($server, $user), 'Backup started') && app(InitiateBackupService::class)->calls === [[7, 'Wingman: manual backup', false]], 'A manual backup must never delete older backups.');
        check(Activity::$events === ['server:backup.start'], 'The backup must be audited.');
        app(InitiateBackupService::class)->failure = new TooManyBackupsException('Cannot create a new backup, this server has reached its limit of 2 backups.');
        rejects(fn () => $doctor->backupNow($server, $user), 'limit of 2 backups. Delete an old backup');
        app(InitiateBackupService::class)->failure = new RuntimeException('adapter internals');
        rejects(fn () => $doctor->backupNow($server, $user), 'backup location');
        rejects(fn () => $doctor->backupNow($server, new User(['backup.read'])), 'permission to create backups');
    });

    $runTest('the 24-hour summary counts this server\'s recent events only', function () {
        [, $server, $user] = harness();
        $summary = new DailySummaryService();
        check($summary->summary($server, new User([])) === null, 'The summary needs activity access.');
        check($summary->summary($server, $user) === ['lines' => [], 'quiet' => true], 'A quiet day must say so.');
        $now = Carbon::$now;
        ActivityLog::$rows = [['server:crashed', $now - 100, 7], ['server:crashed', $now - 200, 7], ['server:power.start', $now - 300, 7], ['server:power.restart', $now - 400, 7],
            ['server:power.kill', $now - 500, 7], ['server:settings.reinstall', $now - 600, 7], ['server:crashed', $now - 90000, 7], ['server:crashed', $now - 100, 8], ['server:file.write', $now - 100, 7]];
        $server->backupRows = [['is_successful' => true, 'completed_at' => at('2026-10-14 12:00:00')], ['is_successful' => false, 'completed_at' => at('2026-10-14 13:00:00')], ['is_successful' => true, 'completed_at' => at('2026-10-10 12:00:00')]];
        $day = $summary->summary($server, $user);
        check($day['lines'] === ['2 crashes', '2 starts or restarts', '1 stop', '1 reinstall', '1 backup made', '1 backup failed'] && !$day['quiet'], 'Unexpected 24-hour summary: ' . json_encode($day['lines']));
    });

    $runTest('usage sampling records reachable, installed servers and feeds suggestions', function () {
        [, $server] = harness();
        $usage = app(UsageHistoryService::class);
        $installing = new Server(8);
        $installing->status = 'installing';
        $maintenance = new Server(9);
        $maintenance->node->maintenance = true;
        $offline = new Server(10);
        Server::$all = [7 => $server, 8 => $installing, 9 => $maintenance, 10 => $offline];
        app(DaemonServerRepository::class)->details = [7 => ['state' => 'running', 'utilization' => ['memory_bytes' => 1048576 * 900, 'cpu_absolute' => 50, 'disk_bytes' => 1048576 * 3000]], 8 => ['state' => 'running'], 9 => ['state' => 'running']];
        $usage->sample();
        check(array_keys(array_filter(Cache::$data, fn ($key) => str_starts_with($key, 'wingman:usage:'), ARRAY_FILTER_USE_KEY)) === ['wingman:usage:7'], 'Only reachable, installed servers outside maintenance may be sampled.');
        check($usage->summary($server)['running_hours'] === 1 && $usage->summary($server)['memory_peak_mb'] === 900, 'The sample must feed the summary.');
        check($usage->diskChangeLastDay($server) === null, 'One hour is not enough to measure disk change.');
        $hour = gmdate('Y-m-d\TH', time() - 7200);
        Cache::$data['wingman:usage:7'][$hour] = ['mem_max' => 0, 'cpu_max' => 0.0, 'cpu_sum' => 0.0, 'cpu_n' => 0, 'disk_first' => 1048576 * 2900, 'disk_last' => 1048576 * 2950, 'running' => 0, 'samples' => 1];
        ksort(Cache::$data['wingman:usage:7']);
        check($usage->diskChangeLastDay($server) === 100, 'Disk change must run from the first to the last sample of the day.');
        app(AssistantSettings::class)->values['usage_tracking_enabled'] = false;
        Cache::$data = [];
        $usage->sample();
        check(Cache::$data === [], 'Disabled usage tracking must record nothing.');
    });

    echo "$count health contract tests passed.\n";
}
