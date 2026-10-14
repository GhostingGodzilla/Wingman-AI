<?php

namespace Pelican\AiHelpAssistant\Providers;

use App\Enums\ConsoleWidgetPosition;
use App\Filament\Server\Pages\Console;
use Illuminate\Support\ServiceProvider;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Support\Facades\Event;
use Pelican\AiHelpAssistant\Filament\Server\Widgets\AiHelpAssistant;
use Pelican\AiHelpAssistant\Listeners\ObserveContainerStatus;
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
    }
}
