<?php

namespace Pelican\AiHelpAssistant\Providers;

use App\Enums\ConsoleWidgetPosition;
use App\Events\ActivityLogged;
use App\Events\Server\BackupCompleted;
use App\Events\Server\Installed;
use App\Filament\Server\Pages\Console;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Support\Facades\Event;
use Pelican\AiHelpAssistant\Filament\Server\Widgets\AiHelpAssistant;
use Pelican\AiHelpAssistant\Jobs\SampleUsage;
use Pelican\AiHelpAssistant\Listeners\ObserveBackup;
use Pelican\AiHelpAssistant\Listeners\ObserveContainerStatus;
use Pelican\AiHelpAssistant\Listeners\ObserveCrash;
use Pelican\AiHelpAssistant\Listeners\ObserveInstall;
use Pelican\AiHelpAssistant\Listeners\ObservePowerRequest;

class AiHelpAssistantProvider extends ServiceProvider
{
    public function register(): void
    {
        Console::registerCustomWidgets(ConsoleWidgetPosition::AboveConsole, [AiHelpAssistant::class]);
    }

    public function boot(): void
    {
        Event::listen(RequestHandled::class, ObserveContainerStatus::class);
        Event::listen(RequestSending::class, ObservePowerRequest::class);
        Event::listen(ActivityLogged::class, ObserveCrash::class);
        Event::listen(Installed::class, ObserveInstall::class);
        Event::listen(BackupCompleted::class, ObserveBackup::class);

        // Pelican's scheduler runs from the system cron; usage sampling joins it.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(new SampleUsage())->everyTenMinutes()->name('wingman-ai:sample-usage')->withoutOverlapping(20);
        });
    }
}
