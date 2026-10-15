<?php

declare(strict_types=1);

namespace Pelican\AiHelpAssistant\Support;

use InvalidArgumentException;
use JsonException;

/**
 * Validates an AI-proposed egg change against the current egg. Model output is
 * untrusted: only known fields with bounded, well-formed values survive, and each
 * change must differ from the current value. Applying still requires an admin.
 */
final class EggProposalNormalizer
{
    public const MAX_SCRIPT_BYTES = 65535;

    private const IMAGE = '/^[a-z0-9][a-z0-9._\/:@-]{0,254}$/iD';

    /**
     * @param  array{startup: string, docker_images: array<string, string>, variables: array<string, string>, script_install: string, script_container: string}  $current
     * @return array{summary: string, reason: string, changes: array<string, mixed>, reinstall: bool}
     */
    public static function normalize(string|array $raw, array $current, array $knownSecrets = []): array
    {
        if (is_string($raw)) {
            $raw = trim($raw);
            if (preg_match('/\A```(?:json)?\s*\R?(.*?)\R?```\z/is', $raw, $matches) === 1) {
                $raw = trim($matches[1]);
            }
            try {
                $raw = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                self::invalid();
            }
        }
        if (!is_array($raw) || $raw === [] || array_is_list($raw)) {
            self::invalid();
        }

        $summary = self::text($raw['summary'] ?? null, 500, $knownSecrets);
        if ($summary === '') {
            self::invalid();
        }
        $reason = self::text($raw['reason'] ?? '', 2000, $knownSecrets);

        $proposed = $raw['changes'] ?? [];
        if (!is_array($proposed) || ($proposed !== [] && array_is_list($proposed))) {
            self::invalid();
        }

        $changes = [];
        if (array_key_exists('startup', $proposed)) {
            $startup = self::line($proposed['startup'], 2000);
            if ($startup === '') {
                self::invalid();
            }
            if ($startup !== $current['startup']) {
                $changes['startup'] = $startup;
            }
        }

        if (array_key_exists('docker_images', $proposed)) {
            $images = $proposed['docker_images'];
            if (!is_array($images) || $images === [] || array_is_list($images) || count($images) > 10) {
                self::invalid();
            }
            $clean = [];
            foreach ($images as $label => $image) {
                $label = self::line($label, 100);
                if ($label === '' || !is_string($image) || preg_match(self::IMAGE, $image) !== 1) {
                    self::invalid();
                }
                $clean[$label] = $image;
            }
            if ($clean !== $current['docker_images']) {
                $changes['docker_images'] = $clean;
            }
        }

        if (array_key_exists('variables', $proposed)) {
            $variables = $proposed['variables'];
            if (!is_array($variables) || ($variables !== [] && array_is_list($variables))) {
                self::invalid();
            }
            foreach ($variables as $name => $value) {
                if (!is_string($name) || !array_key_exists($name, $current['variables'])) {
                    self::invalid();
                }
                if (is_bool($value)) {
                    $value = $value ? '1' : '0';
                } elseif (is_int($value) || is_float($value)) {
                    $value = (string) $value;
                }
                if (!is_string($value) || strlen($value) > 1000 || preg_match('/[\r\n\x00]/', $value) === 1) {
                    self::invalid();
                }
                if ($value !== $current['variables'][$name]) {
                    $changes['variables'][$name] = $value;
                }
            }
        }

        if (array_key_exists('script_install', $proposed)) {
            $script = $proposed['script_install'];
            if (!is_string($script) || trim($script) === '' || strlen($script) > self::MAX_SCRIPT_BYTES || str_contains($script, "\0")) {
                self::invalid();
            }
            $script = str_replace("\r\n", "\n", $script);
            if ($script !== str_replace("\r\n", "\n", $current['script_install'])) {
                $changes['script_install'] = $script;
            }
        }

        if (array_key_exists('script_container', $proposed)) {
            $container = $proposed['script_container'];
            if (!is_string($container) || preg_match(self::IMAGE, $container) !== 1) {
                self::invalid();
            }
            if ($container !== $current['script_container']) {
                $changes['script_container'] = $container;
            }
        }

        // A masked value copied into a change would break the egg and hide what it was.
        if (str_contains(json_encode($changes) ?: '', SecretRedactor::MASK)) {
            self::invalid();
        }

        return [
            'summary' => $summary,
            'reason' => $reason,
            'changes' => $changes,
            'reinstall' => ($raw['reinstall'] ?? false) === true && $changes !== [],
        ];
    }

    private static function line(mixed $value, int $limit): string
    {
        if (!is_string($value) || strlen($value) > $limit || preg_match('/[\r\n\x00]/', $value) === 1) {
            self::invalid();
        }

        return trim($value);
    }

    private static function text(mixed $value, int $limit, array $knownSecrets): string
    {
        if (!is_string($value)) {
            self::invalid();
        }
        $value = trim(SecretRedactor::redact($value, $knownSecrets));

        return function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
    }

    private static function invalid(): never
    {
        throw new InvalidArgumentException('The AI response was not a valid egg suggestion.');
    }
}
