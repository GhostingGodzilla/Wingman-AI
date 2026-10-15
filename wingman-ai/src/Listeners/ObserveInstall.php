<?php

namespace Pelican\AiHelpAssistant\Listeners;

use App\Events\Server\Installed;
use Pelican\AiHelpAssistant\Services\IncidentService;
use Throwable;

/** Queue an install-log check after every install or reinstall. */
class ObserveInstall
{
    public function handle(Installed $event): void
    {
        try {
            app(IncidentService::class)->installed($event->server, $event->successful);
        } catch (Throwable $exception) {
            logger()->warning('Wingman install check could not be queued.', ['exception_type' => $exception::class]);
        }
    }
}
