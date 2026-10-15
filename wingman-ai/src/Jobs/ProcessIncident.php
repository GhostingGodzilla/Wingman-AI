<?php

namespace Pelican\AiHelpAssistant\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Pelican\AiHelpAssistant\Services\IncidentService;
use Throwable;

class ProcessIncident implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 80;

    public bool $failOnTimeout = true;

    // Only identifiers enter the queue; console data and credentials never do.
    public function __construct(public int $serverId, public string $kind, public ?int $activityId = null, public ?bool $successful = null) {}

    public function handle(IncidentService $incidents): void
    {
        try {
            $incidents->process($this->serverId, $this->kind, $this->activityId, $this->successful);
        } catch (Throwable $exception) {
            logger()->warning('Wingman AI could not explain a server incident.', ['kind' => $this->kind, 'exception_type' => $exception::class]);
        }
    }
}
