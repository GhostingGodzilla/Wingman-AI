<?php

namespace Pelican\AiHelpAssistant\Services;

use App\Enums\ContainerStatus;
use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Models\User;
use Pelican\AiHelpAssistant\Support\ReportNormalizer;
use Pelican\AiHelpAssistant\Support\RuleDiagnostics;
use Pelican\AiHelpAssistant\Support\SecretRedactor;
use Throwable;

class DiagnosisService
{
    public function __construct(private AssistantSettings $settings, private AiClient $client) {}

    public function diagnose(Server $server, User $user, array $capture): array
    {
        abort_unless($user->can(SubuserPermission::WebsocketConnect, $server), 403);
        $settings = $this->settings->get();
        abort_unless($settings['enabled'], 403);

        // Read variables with an explicit server binding, never by eager loading this relation.
        // Hidden and credential-named values are redaction candidates; only allowlisted,
        // viewable runtime selectors are included as separate context fields.
        $variables = $server->variables()->get();
        $secrets = [$settings['api_key']];
        $safeVariables = [];
        $canReadStartup = $user->can(SubuserPermission::StartupRead, $server);
        foreach ($variables as $variable) {
            $name = (string) $variable->env_variable;
            $value = (string) ($variable->server_value ?? $variable->default_value ?? '');
            // A small allowlist is more reliable than guessing which custom egg values are secret.
            if ($canReadStartup && $variable->user_viewable
                && preg_match('/^(SERVER_JARFILE|JAR_FILE|JAVA_VERSION|MINECRAFT_VERSION|MC_VERSION|SERVER_VERSION|NODE_VERSION|PYTHON_VERSION|STEAM_APP_ID|SRCDS_APPID)$/D', $name)
                && strlen($value) <= 200 && preg_match('/^[a-zA-Z0-9._+\/-]*$/D', $value)) {
                $safeVariables[$name] = $value;
            } elseif (SecretRedactor::isVariableSecret($name, $value, (bool) $variable->user_viewable)) {
                $secrets[] = $value;
            }
        }

        $context = [
            'egg' => SecretRedactor::redact((string) $server->egg->name, $secrets),
            'memory_limit_mb' => (int) $server->memory,
            'disk_limit_mb' => (int) $server->disk,
            'cpu_limit_percent' => (int) $server->cpu,
        ];
        if ($canReadStartup) {
            $context['startup'] = SecretRedactor::redact((string) $server->startup, $secrets);
            $context['docker_image'] = SecretRedactor::redact((string) $server->image, $secrets);
            $context['runtime_variables'] = $safeVariables;
        }

        // Redact BEFORE trimming, so truncation cannot cut a secret assignment in half.
        $logs = SecretRedactor::redact((string) ($capture['logs'] ?? ''), $secrets);
        $limit = (int) $settings['max_log_bytes'];
        if (strlen($logs) > $limit) {
            $logs = "[Earlier output omitted]\n" . mb_strcut($logs, -$limit, null, 'UTF-8');
        }
        $safeCapture = [
            'logs' => $logs,
            'outcome' => in_array($capture['outcome'] ?? '', ['running', 'failed', 'timeout', 'connection_error', 'observed'], true) ? $capture['outcome'] : 'observed',
            'observed_state' => in_array($capture['observed_state'] ?? '', array_column(ContainerStatus::cases(), 'value'), true) ? $capture['observed_state'] : 'unknown',
            'observations' => $this->sanitizeObservations($capture['observations'] ?? [], $secrets),
        ];

        $fallback = RuleDiagnostics::diagnose($safeCapture, $context);
        $fallback['notice'] = $settings['settings_error'] ?? 'Basic error checks were used. An administrator can connect an AI model in plugin settings for a deeper explanation.';
        if (!$settings['ai_enabled'] || $settings['base_url'] === '' || $settings['model'] === '') {
            return $fallback;
        }

        try {
            $raw = $this->client->analyze($settings, [
                'context' => $context,
                'capture' => $safeCapture,
                'basic_checks' => $fallback,
            ]);
            $report = ReportNormalizer::normalize($raw, [], $secrets);
            foreach ($report as $key => $value) {
                if (is_string($value) && $key !== 'confidence') {
                    $report[$key] = SecretRedactor::redact($value, $secrets);
                } elseif (is_array($value)) {
                    $report[$key] = array_map(fn (string $entry) => SecretRedactor::redact($entry, $secrets), $value);
                }
            }
            $report['source'] = 'ai';
            $report['notice'] = 'This explanation is based on the captured console output and permitted startup settings. Review suggested changes before applying them.';
            // One-click repairs come only from the deterministic checks, never from the model.
            if (isset($fallback['fix'])) {
                $report['fix'] = $fallback['fix'];
            }

            return $report;
        } catch (Throwable) {
            // Do not log request exceptions: they can contain authorization headers and console output.
            $fallback['notice'] = 'The AI service was unavailable or returned an invalid answer. Basic error checks are shown instead.';

            return $fallback;
        }
    }

    private function sanitizeObservations(mixed $observations, array $secrets): array
    {
        if (!is_array($observations)) {
            return [];
        }

        $safe = [];
        foreach (array_slice($observations, 0, 20, true) as $key => $value) {
            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $safe[substr(SecretRedactor::redact((string) $key, $secrets), 0, 80)] = is_string($value)
                    ? mb_strcut(SecretRedactor::redact($value, $secrets), 0, 1500, 'UTF-8')
                    : $value;
            }
        }

        return $safe;
    }
}
