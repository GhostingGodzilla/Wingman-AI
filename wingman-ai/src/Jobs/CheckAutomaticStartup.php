<?php

namespace Pelican\AiHelpAssistant\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Pelican\AiHelpAssistant\Services\AutomaticStartupService;
use Throwable;

class CheckAutomaticStartup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 80;

    public bool $failOnTimeout = true;

    // Only identifiers enter the queue; console data and credentials never do.
    public function __construct(public int $serverId, public string $generation) {}

    public function handle(AutomaticStartupService $monitor): void
    {
        try {
            $monitor->check($this->serverId, $this->generation);
        } catch (Throwable) {
            $monitor->unavailable($this->serverId, $this->generation);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(AutomaticStartupService::class)->unavailable($this->serverId, $this->generation);
    }
}
