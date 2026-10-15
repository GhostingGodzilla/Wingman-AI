<?php

declare(strict_types=1);

namespace Pelican\AiHelpAssistant\Support;

final class SecretRedactor
{
    public const MASK = '[REDACTED]';

    /**
     * Whether an egg variable's value should be masked as a known secret. Masking a tiny
     * or ordinary value (like "1", "win64", a port, or a path) would garble every log line
     * that contains it, so hidden settings are masked only when they look like credentials.
     */
    public static function isVariableSecret(string $name, string $value, bool $viewable): bool
    {
        $value = trim($value);
        if (strlen($value) < 4) {
            return false;
        }
        if (preg_match('/pass(?:word)?|token|secret|key|licen[cs]e|auth|credential|private/i', $name) === 1) {
            return true;
        }

        return !$viewable && strlen($value) >= 8 && !str_starts_with($value, '/')
            && preg_match('/^[\d.:,\/\s_-]+$/', $value) !== 1;
    }

    public static function redact(string $text, array $knownSecrets = []): string
    {
        // Remove terminal styling before matching secrets split by ANSI codes.
        $text = preg_replace('/\x1B\][^\x07\x1B]*(?:\x07|\x1B\\\\)/s', '', $text) ?? $text;
        $text = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $text) ?? $text;
        $text = preg_replace('/\x1B[PX^_].*?\x1B\\\\/s', '', $text) ?? $text;
        $text = preg_replace('/\x1B[@-_]/', '', $text) ?? $text;
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? $text;
        if (function_exists('iconv')) {
            $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if ($clean !== false) {
                $text = $clean;
            }
        }
        $text = preg_replace('/[\x{0080}-\x{009F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text) ?? $text;

        $secrets = array_values(array_unique(array_filter(
            $knownSecrets,
            static fn ($secret): bool => is_string($secret) && $secret !== '',
        )));
        usort($secrets, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
        if ($secrets !== []) {
            $text = str_replace($secrets, self::MASK, $text);
        }

        // A truncated private key is still sensitive: consume it through EOF.
        $text = preg_replace(
            '/-----BEGIN [A-Z ]*(?:PRIVATE|SECRET) KEY-----.*?(?:-----END [A-Z ]*(?:PRIVATE|SECRET) KEY-----|\z)/s',
            self::MASK,
            $text,
        ) ?? $text;
        $text = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 ' . self::MASK, $text) ?? $text;
        $text = preg_replace('/(\b[a-z][a-z0-9+.-]*:\/\/)[^\s\/@]+@/i', '$1' . self::MASK . '@', $text) ?? $text;

        $secretName = '[a-z0-9_.-]*(?:password|passwd|pwd|token|secret|api[_ -]?key|access[_ -]?key|license(?:[_ -]?key)?|authorization|auth[_ -]?(?:key|token))[a-z0-9_.-]*';
        $text = preg_replace_callback(
            '/([?&])([^=&\s#]+)=([^&#\s]*)/',
            static function (array $match) use ($secretName): string {
                return preg_match('/\A' . $secretName . '\z/i', urldecode($match[2])) === 1
                    ? $match[1] . $match[2] . '=' . self::MASK
                    : $match[0];
            },
            $text,
        ) ?? $text;
        // JSON, YAML, environment variables, and simple key=value log entries.
        $value = '"(?:\\\\.|[^"\\\\\r\n])*(?:"|(?=\r?$))|\'(?:\\\\.|[^\'\\\\\r\n])*(?:\'|(?=\r?$))|[^\s,;}&]+';
        $text = preg_replace(
            '/(["\']?' . $secretName . '["\']?\s*[:=]\s*)(' . $value . ')/im',
            '$1' . self::MASK,
            $text,
        ) ?? $text;
        $text = preg_replace(
            '/(--?' . $secretName . '(?:=|\s+))(' . $value . ')/im',
            '$1' . self::MASK,
            $text,
        ) ?? $text;
        $text = preg_replace('/((?:proxy-)?authorization\s*[:=]\s*)[^\r\n]+/i', '$1' . self::MASK, $text) ?? $text;
        // Common standalone credentials, in case a stack trace prints only a key.
        $text = preg_replace(
            '/\b(?:sk-(?:(?:proj|svcacct)-)?[A-Za-z0-9_-]{16,}|gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|AKIA[0-9A-Z]{16})\b/',
            self::MASK,
            $text,
        ) ?? $text;

        return $text;
    }
}
