<?php

namespace Pelican\AiHelpAssistant\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Pelican\AiHelpAssistant\Services\UsageHistoryService;
use Throwable;

class SampleUsage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(UsageHistoryService $usage): void
    {
        try {
            $usage->sample();
        } catch (Throwable $exception) {
            logger()->warning('Wingman AI could not record server usage.', ['exception_type' => $exception::class]);
        }
    }
}
