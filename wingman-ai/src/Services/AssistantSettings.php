<?php

namespace Pelican\AiHelpAssistant\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class AssistantSettings
{
    public function get(): array
    {
        $defaults = require dirname(__DIR__, 2) . '/config/wingman-ai.php';
        $path = $this->path();

        if (!File::exists($path)) {
            return $defaults;
        }

        try {
            $stored = json_decode(Crypt::decryptString(File::get($path)), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($stored)) {
                throw new RuntimeException('Invalid settings.');
            }

            return array_replace($defaults, array_intersect_key($stored, $defaults));
        } catch (Throwable) {
            // Fail closed if APP_KEY changed or the private settings file is damaged.
            return array_replace($defaults, [
                'ai_enabled' => false,
                'settings_error' => 'AI connection settings could not be read. Ask an administrator to save them again.',
            ]);
        }
    }

    public function formData(): array
    {
        $data = $this->get();
        $data['api_key'] = '';
        $data['clear_api_key'] = false;

        return $data;
    }

    public function save(array $data): void
    {
        $validated = Validator::make($data, [
            'enabled' => ['required', 'boolean'],
            'automatic_enabled' => ['required', 'boolean'],
            'auto_daily_limit' => ['required', 'integer', 'min:1', 'max:1000'],
            'auto_cooldown_seconds' => ['required', 'integer', 'min:60', 'max:3600'],
            'ai_enabled' => ['required', 'boolean'],
            'base_url' => ['nullable', 'string', 'max:1000'],
            'model' => ['nullable', 'string', 'max:200'],
            'api_key' => ['nullable', 'string', 'max:8192'],
            'clear_api_key' => ['nullable', 'boolean'],
            'watch_seconds' => ['required', 'integer', 'min:30', 'max:600'],
            'daily_limit' => ['required', 'integer', 'min:1', 'max:1000'],
            'max_response_tokens' => ['required', 'integer', 'min:500', 'max:16000'],
            'token_parameter' => ['required', 'in:max_tokens,max_completion_tokens'],
        ])->validate();

        $current = $this->get();
        $settings = array_replace($current, $validated);
        $settings['base_url'] = rtrim(trim((string) ($validated['base_url'] ?? '')), '/');
        $settings['model'] = trim((string) ($validated['model'] ?? ''));
        $settings['api_key'] = !empty($validated['clear_api_key'])
            ? ''
            : (trim((string) ($validated['api_key'] ?? '')) ?: $current['api_key']);
        unset($settings['clear_api_key'], $settings['settings_error']);

        if ($settings['base_url'] !== '') {
            self::validateBaseUrl($settings['base_url']);
        }

        if ($settings['ai_enabled'] && ($settings['base_url'] === '' || $settings['model'] === '')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'model' => 'Set an AI endpoint and model before enabling AI analysis.',
            ]);
        }

        foreach (['watch_seconds', 'daily_limit', 'max_response_tokens', 'auto_daily_limit', 'auto_cooldown_seconds'] as $field) {
            $settings[$field] = (int) $settings[$field];
        }
        foreach (['enabled', 'ai_enabled', 'automatic_enabled'] as $field) {
            $settings[$field] = (bool) $settings[$field];
        }

        $path = $this->path();
        File::ensureDirectoryExists(dirname($path), 0700);
        File::replace($path, Crypt::encryptString(json_encode($settings, JSON_THROW_ON_ERROR)), 0600);
    }

    public static function validateBaseUrl(string $url): void
    {
        $parts = parse_url($url);
        $valid = filter_var($url, FILTER_VALIDATE_URL)
            && is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && !empty($parts['host'])
            && !isset($parts['user'], $parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment']);

        // Local AI endpoints are intentional. Only panel administrators can set this URL.
        if (!$valid || (is_array($parts) && (isset($parts['user']) || isset($parts['pass'])))) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'base_url' => 'Use an HTTP or HTTPS API base URL without credentials, a query, or a fragment.',
            ]);
        }
    }

    protected function path(): string
    {
        return storage_path('app/private/wingman-ai/settings.enc');
    }
}
