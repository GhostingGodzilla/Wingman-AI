<?php

namespace Pelican\AiHelpAssistant\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Pelican\AiHelpAssistant\Services\SafeReinstallService;
use Throwable;

class ReinstallAfterBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $serverId, public int $userId, public string $ability) {}

    public function handle(SafeReinstallService $reinstalls): void
    {
        try {
            $reinstalls->finish($this->serverId, $this->userId, $this->ability);
        } catch (Throwable $exception) {
            logger()->warning('Wingman AI could not reinstall after a backup.', ['exception_type' => $exception::class]);
        }
    }
}
