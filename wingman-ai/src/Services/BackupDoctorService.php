<?php

namespace Pelican\AiHelpAssistant\Services;

use App\Enums\SubuserPermission;
use App\Exceptions\Service\Backup\TooManyBackupsException;
use App\Facades\Activity;
use App\Models\Schedule;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Services\Backups\InitiateBackupService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/** Checks that a server is actually being backed up, and sets up nightly backups. */
class BackupDoctorService
{
    public const SCHEDULE_NAME = 'Wingman: nightly backup';

    /** Spatie Health records when Pelican's scheduler last ran (via the system cron). */
    private const HEARTBEAT_KEY = 'health:checks:schedule:latestHeartbeatAt';

    /** @return array<string, mixed>|null */
    public function status(Server $server, User $user): ?array
    {
        if (!$user->can(SubuserPermission::BackupRead, $server)) {
            return null;
        }
        $limit = (int) $server->backup_limit;
        $count = $server->backups()->where('is_successful', true)->whereNotNull('completed_at')->count();
        $last = $server->backups()->where('is_successful', true)->whereNotNull('completed_at')->max('completed_at');
        $lastAt = $last ? Carbon::parse($last) : null;
        $scheduled = $this->hasBackupSchedule($server);
        $heartbeat = Cache::get(self::HEARTBEAT_KEY);
        $schedulerRunning = is_numeric($heartbeat) && Carbon::now()->getTimestamp() - (int) $heartbeat < 600;
        $admin = $user->can('update', $server);

        $issues = [];
        if ($limit <= 0) {
            $issues[] = 'This server cannot keep any backups: its backup limit is 0. ' . ($admin ? 'Raise it in Admin → Servers → this server.' : 'Ask your panel admin to raise it.');
        } elseif ($count === 0) {
            $issues[] = 'This server has no backups yet.';
        } elseif ($lastAt !== null && $lastAt->lt(Carbon::now()->subDays(7))) {
            $issues[] = 'The newest backup is ' . (int) $lastAt->diffInDays(Carbon::now()) . ' days old.';
        }
        if ($limit > 0 && !$scheduled) {
            $issues[] = 'No automatic backups are scheduled.';
        }
        if ($scheduled && !$schedulerRunning) {
            $issues[] = 'Scheduled backups are not running, because the panel\'s task timer (cron) has not run in the last 10 minutes. ' . ($admin ? 'Check the www-data crontab entry for "artisan schedule:run".' : 'Tell your panel admin.');
        }

        return [
            'limit' => $limit,
            'count' => $count,
            'last' => $lastAt?->toIso8601String(),
            'scheduled' => $scheduled,
            'issues' => $issues,
            'can_backup' => $limit > 0 && $user->can(SubuserPermission::BackupCreate, $server),
            'can_schedule' => $limit > 0 && !$scheduled && $user->can(SubuserPermission::ScheduleCreate, $server) && $user->can(SubuserPermission::BackupCreate, $server),
        ];
    }

    public function backupNow(Server $server, User $user): string
    {
        $this->assert($user->can(SubuserPermission::BackupCreate, $server), 'You do not have permission to create backups for this server.');
        $this->assert((int) $server->backup_limit > 0, 'This server cannot keep any backups: its backup limit is 0.');
        try {
            $backup = app(InitiateBackupService::class)->handle($server, 'Wingman: manual backup');
        } catch (TooManyBackupsException|TooManyRequestsHttpException $exception) {
            $this->fail($exception->getMessage() . ' Delete an old backup on the Backups page first.');
        } catch (Throwable) {
            $this->fail('The backup could not be started. Check that the node has a backup location, then try again.');
        }
        Activity::event('server:backup.start')->subject($server)->actor($user)->property(['name' => $backup->name, 'source' => 'wingman-ai'])->log();

        return 'Backup started. It appears on the Backups page when it finishes.';
    }

    public function scheduleNightly(Server $server, User $user): string
    {
        $this->assert($user->can(SubuserPermission::ScheduleCreate, $server) && $user->can(SubuserPermission::BackupCreate, $server), 'You need permission to create schedules and backups for this server.');
        $this->assert((int) $server->backup_limit > 0, 'This server cannot keep any backups: its backup limit is 0.');
        $this->assert(!$this->hasBackupSchedule($server), 'This server already has an automatic backup schedule.');

        // Spread servers over the hour so their nightly backups do not all start at once.
        $minute = (string) (($server->id * 7) % 60);
        $timezone = $user->timezone ?? 'UTC';
        DB::transaction(function () use ($server, $minute, $timezone) {
            $schedule = new Schedule();
            $schedule->forceFill([
                'server_id' => $server->id,
                'name' => self::SCHEDULE_NAME,
                'cron_minute' => $minute,
                'cron_hour' => '4',
                'cron_day_of_month' => '*',
                'cron_month' => '*',
                'cron_day_of_week' => '*',
                'timezone' => $timezone,
                'is_active' => true,
                'is_processing' => false,
                'only_when_online' => false,
            ]);
            $schedule->next_run_at = $schedule->getNextRunDate();
            $schedule->saveOrFail();
            (new Task())->forceFill([
                'schedule_id' => $schedule->id,
                'sequence_id' => 1,
                'action' => 'backup',
                'payload' => '',
                'time_offset' => 0,
                'continue_on_failure' => false,
            ])->saveOrFail();
        });
        Activity::event('server:schedule.create')->subject($server)->actor($user)->property(['name' => self::SCHEDULE_NAME, 'source' => 'wingman-ai'])->log();

        return sprintf('Nightly backups are on: every day at 4:%02d (%s). When the limit of %d %s is reached, the oldest unlocked backup is replaced. You can change or remove this on the Schedules page.', (int) $minute, $timezone, (int) $server->backup_limit, (int) $server->backup_limit === 1 ? 'backup' : 'backups');
    }

    private function hasBackupSchedule(Server $server): bool
    {
        return $server->schedules()->where('is_active', true)->whereHas('tasks', fn ($query) => $query->where('action', 'backup'))->exists();
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            $this->fail($message);
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['backup' => $message]);
    }
}
