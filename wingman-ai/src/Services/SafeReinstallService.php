<?php

namespace Pelican\AiHelpAssistant\Services;

use App\Enums\SubuserPermission;
use App\Exceptions\Service\Backup\TooManyBackupsException;
use App\Facades\Activity;
use App\Models\Backup;
use App\Models\Server;
use App\Models\User;
use App\Services\Backups\InitiateBackupService;
use App\Services\Servers\ReinstallServerService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Pelican\AiHelpAssistant\Jobs\ReinstallAfterBackup;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Reinstalls a server for a Wingman fix, optionally after a fresh panel backup. A
 * backed-up reinstall waits for Pelican's backup-completed event, and runs only if
 * the backup succeeded and the person who asked may still reinstall the server.
 */
class SafeReinstallService
{
    private const PENDING_SECONDS = 10800;

    /** @return array{possible: bool, reason: ?string} */
    public function backupStatus(Server $server, User $user): array
    {
        if (!$user->can(SubuserPermission::BackupCreate, $server)) {
            return ['possible' => false, 'reason' => 'You do not have permission to create backups for this server.'];
        }
        $limit = (int) $server->backup_limit;
        if ($limit <= 0) {
            return ['possible' => false, 'reason' => 'This server allows 0 backups. An admin can raise its backup limit in Admin → Servers.'];
        }
        if ($server->backups()->nonFailed()->count() >= $limit) {
            return ['possible' => false, 'reason' => 'This server already has its maximum of ' . $limit . ' ' . ($limit === 1 ? 'backup' : 'backups') . '. Delete one on the Backups page first.'];
        }

        return ['possible' => true, 'reason' => null];
    }

    /**
     * @param  string  $ability  'repair' needs the subuser reinstall permission; 'egg' needs admin server access.
     */
    public function reinstall(Server $server, User $user, bool $backupFirst, string $ability): string
    {
        if (!$backupFirst) {
            $this->run($server, $user);

            return 'Reinstall started. Start the server from the console after the installation finishes.';
        }
        $status = $this->backupStatus($server, $user);
        $this->assert($status['possible'], 'No backup could be made: ' . $status['reason'] . ' Turn off "Back up first" to reinstall without a backup.');
        $this->assert($this->pending($server) === null, 'A backup and reinstall is already in progress for this server.');
        try {
            $backup = app(InitiateBackupService::class)->handle($server, 'Wingman: before reinstall');
        } catch (TooManyBackupsException|TooManyRequestsHttpException $exception) {
            $this->fail('The backup could not be started: ' . $exception->getMessage());
        } catch (Throwable) {
            $this->fail('The backup could not be started. Check that the node has a backup location, then try again.');
        }

        Activity::event('server:backup.start')->subject($server)->actor($user)->property(['name' => $backup->name, 'source' => 'wingman-ai'])->log();
        Cache::put($this->pendingKey($backup->uuid), ['server' => $server->id, 'user' => $user->id, 'ability' => $ability], now()->addSeconds(self::PENDING_SECONDS));
        Cache::put($this->serverKey($server->id), ['backup' => $backup->uuid, 'since' => gmdate('c')], now()->addSeconds(self::PENDING_SECONDS));
        Cache::forget($this->noticeKey($server->id));

        return 'Backing up first. The reinstall starts by itself when the backup finishes.';
    }

    /** Pelican recorded a finished backup; continue a reinstall that was waiting for it. */
    public function backupFinished(Backup $backup): void
    {
        $pending = Cache::pull($this->pendingKey($backup->uuid));
        if (!is_array($pending)) {
            return;
        }
        Cache::forget($this->serverKey($pending['server']));
        if (!$backup->is_successful || $backup->server_id !== $pending['server']) {
            $this->notice($pending['server'], 'The backup failed, so Wingman did not reinstall the server.');

            return;
        }
        try {
            // Reinstall after Wings' backup callback has finished, not inside it.
            Bus::dispatch(new ReinstallAfterBackup($pending['server'], $pending['user'], $pending['ability']));
        } catch (Throwable) {
            $this->finish($pending['server'], $pending['user'], $pending['ability']);
        }
    }

    /** Reinstall after a successful backup, checking again that it is still allowed. */
    public function finish(int $serverId, int $userId, string $ability): void
    {
        $server = Server::query()->find($serverId);
        if (!$server instanceof Server) {
            return;
        }
        $user = User::query()->find($userId);
        $allowed = $user instanceof User && ($ability === 'egg' ? $user->can('update', $server) : $user->can(SubuserPermission::SettingsReinstall, $server));
        if (!$allowed) {
            $this->notice($server->id, 'The backup finished, but the reinstall was cancelled because the permission to reinstall was removed.');

            return;
        }
        try {
            $server->validateCurrentState();
        } catch (Throwable) {
            $this->notice($server->id, 'The backup finished, but the server was busy (installing, restoring, transferring, or suspended), so Wingman did not reinstall it.');

            return;
        }
        $this->run($server, $user);
        $this->notice($server->id, 'The backup finished and the reinstall started.');
    }

    /** @return array{backup: string, since: string}|null */
    public function pending(Server $server): ?array
    {
        $pending = Cache::get($this->serverKey($server->id));

        return is_array($pending) ? $pending : null;
    }

    /** @return array{message: string, at: string}|null */
    public function lastNotice(Server $server): ?array
    {
        $notice = Cache::get($this->noticeKey($server->id));

        return is_array($notice) ? $notice : null;
    }

    private function run(Server $server, User $user): void
    {
        app(ReinstallServerService::class)->handle($server);
        Activity::event('server:settings.reinstall')->subject($server)->actor($user)->property('source', 'wingman-ai')->log();
    }

    private function notice(int $serverId, string $message): void
    {
        Cache::put($this->noticeKey($serverId), ['message' => $message, 'at' => gmdate('c')], now()->addDay());
    }

    private function pendingKey(string $backupUuid): string
    {
        return 'wingman-ai:reinstall-after:' . $backupUuid;
    }

    private function serverKey(int $serverId): string
    {
        return 'wingman-ai:pending-reinstall:' . $serverId;
    }

    private function noticeKey(int $serverId): string
    {
        return 'wingman-ai:reinstall-notice:' . $serverId;
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            $this->fail($message);
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['reinstall' => $message]);
    }
}
