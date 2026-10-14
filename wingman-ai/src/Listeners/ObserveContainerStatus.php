<?php

namespace Pelican\AiHelpAssistant\Listeners;

use App\Http\Controllers\Api\Remote\Servers\ServerContainersController;
use App\Models\Server;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Pelican\AiHelpAssistant\Services\AutomaticStartupService;
use Throwable;

/** Runs after Pelican has authenticated and accepted a Wings state callback. */
class ObserveContainerStatus
{
    public function handle(RequestHandled $event): void
    {
        try {
            $request = $event->request;
            $route = $request->route();
            if (!$request->isMethod('POST') || !$route
                || $route->getActionName() !== ServerContainersController::class . '@status'
                || $event->response->getStatusCode() < 200 || $event->response->getStatusCode() >= 300) {
                return;
            }
            $server = $request->route('server');
            if (!$server instanceof Server || !$server->node->is($request->attributes->get('node'))) {
                return;
            }
            $previous = $request->json('data.previous_state');
            $current = $request->json('data.new_state');
            if (!is_string($previous) || !is_string($current)) {
                return;
            }

            // record only writes bounded state and queues a delayed check. No daemon
            // connection, log fetch, model request, or power action occurs here.
            app(AutomaticStartupService::class)->record($server, $previous, $current);
        } catch (Throwable $exception) {
            // The observer must never disrupt an ordinary server state update.
            logger()->warning('Wingman automatic observation could not be queued.', ['exception_type' => $exception::class]);
        }
    }
}
