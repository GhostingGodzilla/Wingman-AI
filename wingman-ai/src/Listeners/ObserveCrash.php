<?php

namespace Pelican\AiHelpAssistant\Listeners;

use App\Events\ActivityLogged;
use App\Models\Server;
use Pelican\AiHelpAssistant\Services\IncidentService;
use Throwable;

/** Wings reports crashes as activity; queue an explanation for crashes while running. */
class ObserveCrash
{
    public function handle(ActivityLogged $event): void
    {
        try {
            if (!$event->is('server:crashed')) {
                return;
            }
            $serverId = $event->model->subjects()->where('subject_type', (new Server())->getMorphClass())->value('subject_id');
            $server = $serverId ? Server::query()->find($serverId) : null;
            if ($server instanceof Server) {
                app(IncidentService::class)->crashed($server, (int) $event->model->id, (float) $event->model->timestamp->getTimestamp());
            }
        } catch (Throwable $exception) {
            // Observation must never disrupt Wings' activity upload.
            logger()->warning('Wingman crash observation could not be queued.', ['exception_type' => $exception::class]);
        }
    }
}
