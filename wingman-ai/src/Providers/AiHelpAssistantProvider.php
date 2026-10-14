<?php

namespace Pelican\AiHelpAssistant\Providers;

use App\Enums\ConsoleWidgetPosition;
use App\Filament\Server\Pages\Console;
use Illuminate\Support\ServiceProvider;
use Pelican\AiHelpAssistant\Filament\Server\Widgets\AiHelpAssistant;

class AiHelpAssistantProvider extends ServiceProvider
{
    public function register(): void
    {
        Console::registerCustomWidgets(ConsoleWidgetPosition::AboveConsole, [AiHelpAssistant::class]);
    }
}
