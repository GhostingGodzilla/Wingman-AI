<?php

namespace Pelican\AiHelpAssistant\Listeners;

use App\Events\Server\BackupCompleted;
use Pelican\AiHelpAssistant\Services\SafeReinstallService;
use Throwable;

/** Continue a Wingman reinstall that was waiting for its safety backup. */
class ObserveBackup
{
    public function handle(BackupCompleted $event): void
    {
        try {
            app(SafeReinstallService::class)->backupFinished($event->backup);
        } catch (Throwable $exception) {
            logger()->warning('Wingman could not continue a reinstall after a backup.', ['exception_type' => $exception::class]);
        }
    }
}
