<?php

declare(strict_types=1);

namespace Pelican\AiHelpAssistant\Support;

use InvalidArgumentException;

/**
 * Safe, line-based edits to a game config file. The AI only sees a masked copy and
 * names exact lines to change; hidden values and settings Pelican manages are refused.
 */
final class ConfigEdits
{
    public const MAX_EDITS = 20;

    public const MAX_APPEND = 10;

    private const SECRET_KEY = '/pass(?:word)?|token|secret|api[_-]?key|private|licen[cs]e|auth|credential|salt/i';

    /**
     * Mask the values of secret-looking settings and known secrets, one line at a time
     * (line count is kept, so masked lines can be matched to the real file).
     */
    public static function mask(string $content, array $knownSecrets = []): string
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $content));
        $knownSecrets = array_values(array_filter($knownSecrets, fn ($secret) => is_string($secret) && $secret !== '' && !str_contains($secret, "\n")));
        usort($knownSecrets, fn (string $left, string $right) => strlen($right) <=> strlen($left));
        foreach ($lines as $index => $line) {
            if ($knownSecrets !== []) {
                $line = $lines[$index] = str_replace($knownSecrets, SecretRedactor::MASK, $line);
            }
            // key = value | key: value | "key": "value" | key "value" | key value
            if (preg_match('/^(\s*"?([A-Za-z0-9_.\-]+)"?\s*(?:[=:]\s*|\s+))(.+?)(\s*[,;]?\s*)$/', $line, $match) === 1
                && preg_match(self::SECRET_KEY, $match[2]) === 1 && trim($match[3], " \"'") !== '') {
                $lines[$index] = $match[1] . SecretRedactor::MASK . $match[4];
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<array{find: string, replace: string}>  $edits
     * @param  list<string>  $append
     * @param  list<string>  $managedKeys  Setting names Pelican writes from Startup variables.
     */
    public static function apply(string $original, array $edits, array $append, array $managedKeys, array $knownSecrets = []): string
    {
        $original = str_replace("\r\n", "\n", $original);
        $lines = explode("\n", $original);
        $masked = explode("\n", self::mask($original, $knownSecrets));
        if (count($edits) > self::MAX_EDITS || count($append) > self::MAX_APPEND || ($edits === [] && $append === [])) {
            self::invalid('The suggestion has no changes, or too many.');
        }

        $changed = [];
        foreach ($edits as $edit) {
            if (!is_array($edit) || !is_string($edit['find'] ?? null) || !is_string($edit['replace'] ?? null)) {
                self::invalid('The suggestion was not in the expected format.');
            }
            $find = rtrim($edit['find'], "\r");
            $replace = self::line($edit['replace']);
            $matches = array_keys(array_filter($masked, fn (string $line) => rtrim($line, "\r") === $find));
            if (count($matches) !== 1) {
                self::invalid('A line the suggestion wants to change was not found exactly once.');
            }
            $index = $matches[0];
            if (isset($changed[$index])) {
                self::invalid('The suggestion changes the same line twice.');
            }
            if ($masked[$index] !== $lines[$index]) {
                self::invalid('The suggestion tries to change a hidden value such as a password. Change that one by hand.');
            }
            self::assertNotManaged($lines[$index], $managedKeys);
            self::assertNotManaged($replace, $managedKeys);
            $lines[$index] = $replace;
            $changed[$index] = true;
        }
        // Added lines go before the file's final newline, not after it.
        $trailing = end($lines) === '' ? array_pop($lines) : null;
        foreach ($append as $line) {
            $line = self::line(is_string($line) ? $line : '');
            self::assertNotManaged($line, $managedKeys);
            $lines[] = $line;
        }
        if ($trailing !== null) {
            $lines[] = $trailing;
        }

        return implode("\n", $lines);
    }

    /**
     * Setting names in an egg's config_files entry for one file, last path segment only.
     *
     * @return list<string>
     */
    public static function managedKeys(mixed $configFiles, string $path): array
    {
        $files = is_string($configFiles) ? json_decode($configFiles, true) : $configFiles;
        if (!is_array($files)) {
            return [];
        }
        $normalize = fn (string $file) => ltrim(preg_replace('#^\./#', '', str_replace('\\', '/', $file)) ?? $file, '/');
        foreach ($files as $file => $definition) {
            if (is_string($file) && $normalize($file) === $normalize($path) && is_array($definition['find'] ?? null)) {
                return array_values(array_unique(array_map(fn ($key) => (string) preg_replace('/^.*[.\[\]]/', '', (string) $key), array_keys($definition['find']))));
            }
        }

        return [];
    }

    private static function assertNotManaged(string $line, array $managedKeys): void
    {
        foreach ($managedKeys as $key) {
            if ($key !== '' && preg_match('/^\s*"?' . preg_quote($key, '/') . '"?\s*(?:[=:\s]|$)/i', $line) === 1) {
                self::invalid('"' . $key . '" is set by Pelican from the Startup page. Change it there instead.');
            }
        }
    }

    private static function line(string $line): string
    {
        if (strlen($line) > 500 || preg_match('/[\r\n\x00]/', $line) === 1 || str_contains($line, SecretRedactor::MASK)) {
            self::invalid('The suggestion contains a line Wingman cannot write.');
        }

        return $line;
    }

    private static function invalid(string $message): never
    {
        throw new InvalidArgumentException($message);
    }
}
