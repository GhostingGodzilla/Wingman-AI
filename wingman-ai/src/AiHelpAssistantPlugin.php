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
