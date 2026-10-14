<?php

declare(strict_types=1);

namespace Pelican\AiHelpAssistant\Support;

use InvalidArgumentException;
use JsonException;

final class ReportNormalizer
{
    public static function normalize(string|array $raw, array $fallback = [], array $knownSecrets = []): array
    {
        if (is_string($raw)) {
            $raw = trim($raw);
            if (preg_match('/\A```(?:json)?\s*\R?(.*?)\R?```\z/is', $raw, $matches) === 1) {
                $raw = trim($matches[1]);
            }
            try {
                $raw = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new InvalidArgumentException('The AI response was not a valid diagnosis report.');
            }
        }

        if (!is_array($raw) || $raw === [] || array_is_list($raw)) {
            throw new InvalidArgumentException('The AI response was not a valid diagnosis report.');
        }

        $result = [];
        foreach (['summary' => 500, 'likely_cause' => 1200] as $field => $length) {
            $value = array_key_exists($field, $raw) ? $raw[$field] : ($fallback[$field] ?? null);
            if (!is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException('The AI response was not a valid diagnosis report.');
            }
            $result[$field] = self::text($value, $length, $knownSecrets);
            if ($result[$field] === '') {
                throw new InvalidArgumentException('The AI response was not a valid diagnosis report.');
            }
        }

        $confidence = $raw['confidence'] ?? $fallback['confidence'] ?? 'low';
        $result['confidence'] = is_string($confidence) && in_array(strtolower(trim($confidence)), ['high', 'medium', 'low'], true)
            ? strtolower(trim($confidence))
            : 'low';

        foreach (['evidence' => [8, 600], 'steps' => [8, 1200], 'needs_more_information' => [6, 600]] as $field => [$count, $length]) {
            $values = array_key_exists($field, $raw) ? $raw[$field] : ($fallback[$field] ?? []);
            if (!is_array($values) || !array_is_list($values)) {
                throw new InvalidArgumentException('The AI response was not a valid diagnosis report.');
            }
            $result[$field] = [];
            foreach ($values as $value) {
                if (!is_string($value)) {
                    throw new InvalidArgumentException('The AI response was not a valid diagnosis report.');
                }
                $value = self::text($value, $length, $knownSecrets);
                if ($value !== '' && !in_array($value, $result[$field], true) && count($result[$field]) < $count) {
                    $result[$field][] = $value;
                }
            }
        }

        // The caller determines provenance. Ignore model-supplied extra fields.
        return $result;
    }

    private static function text(string $value, int $limit, array $knownSecrets = []): string
    {
        $value = trim(SecretRedactor::redact($value, $knownSecrets));
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $limit, 'UTF-8');
        }
        // Keep the fallback UTF-8 safe even when mbstring is unavailable.
        if (preg_match_all('/./us', $value, $characters) !== false) {
            return implode('', array_slice($characters[0], 0, $limit));
        }

        return substr($value, 0, $limit);
    }
}
