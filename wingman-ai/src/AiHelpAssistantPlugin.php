<?php

namespace Pelican\AiHelpAssistant;

use App\Contracts\Plugins\HasPluginSettings;
use App\Models\Plugin as PanelPlugin;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Pelican\AiHelpAssistant\Services\AssistantSettings;
use Pelican\AiHelpAssistant\Filament\Server\Widgets\AiHelpAssistant;
use Throwable;

class AiHelpAssistantPlugin implements Plugin, HasPluginSettings
{
    public function getId(): string
    {
        return 'wingman-ai';
    }

    public function register(Panel $panel): void
    {
        $panel->widgets([AiHelpAssistant::class]);
    }

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        return app(AssistantSettings::class)->formData();
    }

    public function getSettingsForm(): array
    {
        return [
            Toggle::make('enabled')->label('Enable Wingman AI')->inline(false),
            Section::make('Automatic startup protection')
                ->description('Watch each startup in the background, including scheduled starts while the console is closed. A working Pelican queue worker is required.')
                ->schema([
                    Toggle::make('automatic_enabled')->label('Automatically diagnose startup failures')->inline(false)
                        ->helperText('Successful starts stay quiet. Failed or timed-out starts produce a report; configured AI analysis may send redacted output to your provider.'),
                    TextInput::make('auto_daily_limit')->label('Automatic reports per server per day')->numeric()->minValue(1)->maxValue(1000)->required(),
                    TextInput::make('auto_cooldown_seconds')->label('Minimum seconds between automatic reports')->numeric()->minValue(60)->maxValue(3600)->required()
                        ->helperText('Limits repeated reports during a crash loop.'),
                    Toggle::make('crash_watch_enabled')->label('Explain crashes while a server is running')->inline(false)
                        ->helperText('Uses the crash reports Wings sends. Crashes during a watched start are covered by the startup report.'),
                    Toggle::make('install_check_enabled')->label('Check the install log after every install or reinstall')->inline(false)
                        ->helperText('Catches installs that report success although a download failed.'),
                ]),
            Section::make('Safety')
                ->schema([
                    Toggle::make('backup_before_reinstall')->label('Back up a server before a Wingman reinstall')->inline(false)
                        ->helperText('The default for the "Back up first" switch. Needs a backup limit of at least 1 on the server.'),
                ]),
            Section::make('Ask Wingman')->description('Lets people with console access ask the AI questions about their server. Needs an AI connection.')
                ->schema([
                    Toggle::make('ask_enabled')->label('Show the Ask Wingman box')->inline(false),
                    TextInput::make('ask_daily_limit')->label('Questions per user per day')->numeric()->minValue(1)->maxValue(1000)->required(),
                ]),
            Section::make('AI connection')->description('Choose an AI service or a model running on your own machine. Basic error checks work without an AI connection.')
                ->schema([
                    Toggle::make('ai_enabled')->label('Use AI analysis')->inline(false)->live(),
                    TextInput::make('base_url')->label('API base URL')->placeholder('https://api.openai.com/v1')
                        ->helperText('Use a trusted service with a Chat Completions API, including /v1 if required. Local example: http://127.0.0.1:11434/v1.')
                        ->maxLength(1000)->required(fn (Get $get) => (bool) $get('ai_enabled')),
                    TextInput::make('model')->label('Model')->maxLength(200)
                        ->helperText('Enter the model name offered by your AI service.')
                        ->required(fn (Get $get) => (bool) $get('ai_enabled')),
                    TextInput::make('api_key')->label('API key')->password()->maxLength(8192)->autocomplete('off')
                        ->helperText('Leave blank to keep the saved key. A local AI service may not require one.'),
                    Toggle::make('clear_api_key')->label('Remove the saved API key')->inline(false),
                    Select::make('token_parameter')->label('Output token setting')->required()
                        ->options(['max_completion_tokens' => 'max_completion_tokens (OpenAI and compatible services)', 'max_tokens' => 'max_tokens (Ollama and other local services)']),
                ]),
            Section::make('Diagnosis limits')->schema([
                TextInput::make('watch_seconds')->label('Startup watch duration (seconds)')->numeric()->minValue(30)->maxValue(600)->required(),
                TextInput::make('daily_limit')->label('Diagnoses per user per day')->numeric()->minValue(1)->maxValue(1000)->required(),
                TextInput::make('max_response_tokens')->label('Maximum AI output tokens')->numeric()->minValue(500)->maxValue(16000)->required(),
            ]),
        ];
    }

    public function saveSettings(array $data): void
    {
        $plugin = PanelPlugin::find($this->getId());
        abort_unless($plugin && user()?->can('update', $plugin), 403);

        try {
            app(AssistantSettings::class)->save($data);
            Notification::make()->title('Wingman AI settings saved')->success()->send();
        } catch (\Illuminate\Validation\ValidationException $exception) {
            Notification::make()->title('Settings were not saved')
                ->body(collect($exception->errors())->flatten()->first())
                ->danger()->send();
        } catch (Throwable) {
            Notification::make()->title('Settings were not saved')
                ->body('Check that the panel can write its private storage directory, then try again.')
                ->danger()->send();
        }
    }
}
