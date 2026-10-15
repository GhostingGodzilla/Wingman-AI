<?php

declare(strict_types=1);

namespace Pelican\AiHelpAssistant\Support;

/**
 * Line comparison and risk hints for reviewing an AI-proposed install script.
 * The hints help a reviewer; they are not a security boundary.
 */
final class ScriptDiff
{
    /** Above this many cells, the changed middle is shown as a block replacement. */
    private const MAX_LCS_CELLS = 250000;

    /**
     * @return list<array{0: string, 1: string}> Pairs of ' ' (same), '-' (removed), or '+' (added) and the line.
     */
    public static function lines(string $old, string $new): array
    {
        $a = self::split($old);
        $b = self::split($new);
        $start = 0;
        while ($start < count($a) && $start < count($b) && $a[$start] === $b[$start]) {
            $start++;
        }
        [$endA, $endB] = [count($a), count($b)];
        while ($endA > $start && $endB > $start && $a[$endA - 1] === $b[$endB - 1]) {
            $endA--;
            $endB--;
        }

        $middleA = array_slice($a, $start, $endA - $start);
        $middleB = array_slice($b, $start, $endB - $start);
        $middle = count($middleA) * count($middleB) <= self::MAX_LCS_CELLS
            ? self::lcs($middleA, $middleB)
            : [...array_map(fn (string $line) => ['-', $line], $middleA), ...array_map(fn (string $line) => ['+', $line], $middleB)];

        return [
            ...array_map(fn (string $line) => [' ', $line], array_slice($a, 0, $start)),
            ...$middle,
            ...array_map(fn (string $line) => [' ', $line], array_slice($a, $endA)),
        ];
    }

    /**
     * Keep changed lines with some context; longer unchanged runs become one '…' marker.
     *
     * @param  list<array{0: string, 1: string}>  $lines
     * @return list<array{0: string, 1: string}>
     */
    public static function hunks(array $lines, int $context = 3): array
    {
        $near = [];
        foreach ($lines as $index => [$type]) {
            if ($type !== ' ') {
                for ($i = max(0, $index - $context); $i <= $index + $context; $i++) {
                    $near[$i] = true;
                }
            }
        }
        $out = [];
        $skipped = 0;
        foreach ($lines as $index => $line) {
            if (isset($near[$index])) {
                if ($skipped > 0) {
                    $out[] = ['…', $skipped . ' unchanged ' . ($skipped === 1 ? 'line' : 'lines')];
                    $skipped = 0;
                }
                $out[] = $line;
            } else {
                $skipped++;
            }
        }
        if ($skipped > 0) {
            $out[] = ['…', $skipped . ' unchanged ' . ($skipped === 1 ? 'line' : 'lines')];
        }

        return $out;
    }

    /**
     * Plain-language warnings about risky things the new script adds.
     *
     * @return list<string>
     */
    public static function warnings(string $old, string $new): array
    {
        $added = [];
        foreach (self::lines($old, $new) as [$type, $line]) {
            if ($type === '+') {
                $added[] = $line;
            }
        }
        $addedText = implode("\n", $added);
        $warnings = [];

        $oldHosts = self::hosts($old);
        foreach (self::hosts($addedText) as $host) {
            if (!in_array($host, $oldHosts, true)) {
                $warnings[] = 'Downloads from a new address: ' . $host;
            }
        }

        foreach ([
            '/\b(?:curl|wget)\b[^\n|]*\|\s*(?:sudo\s+)?(?:ba|da|z)?sh\b/i' => 'Runs a downloaded script directly by piping it into a shell.',
            '/\bchmod\s+(?:-R\s+)?0?777\b/' => 'Makes files writable by everyone (chmod 777).',
            '/\bbase64\s+(?:-d|--decode)\b/' => 'Decodes hidden base64 content.',
            '/(?:^|[\s;&|])eval\s/' => 'Uses eval to run generated commands.',
            '/\/dev\/(?:tcp|udp)\/|\bnc\s+-\w*e\b|\bncat\b|\bsocat\b/' => 'Opens a raw network connection.',
        ] as $pattern => $warning) {
            if (preg_match($pattern, $addedText) === 1 && preg_match($pattern, $old) !== 1) {
                $warnings[] = $warning;
            }
        }

        foreach ($added as $line) {
            if (preg_match('/\brm\s+(?:-\w+\s+)*-\w*r\w*\s+(.+)$/i', $line, $match) !== 1) {
                continue;
            }
            foreach (preg_split('/\s+/', trim($match[1])) ?: [] as $target) {
                $target = trim($target, '"\'');
                if ($target === '/' || $target === '/*' || str_starts_with($target, '~') || str_starts_with($target, '$HOME')
                    || (str_starts_with($target, '/') && !preg_match('#^/(?:mnt/server|tmp)(?:/|$)#', $target))) {
                    $warnings[] = 'Deletes files outside the server folder: ' . $target;
                    break;
                }
            }
        }

        return array_values(array_unique($warnings));
    }

    /** @return list<string> */
    private static function hosts(string $text): array
    {
        preg_match_all('~\b(?:https?|ftp)://([a-z0-9.-]+)~i', $text, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }

    /** @return list<string> */
    private static function split(string $text): array
    {
        $text = str_replace("\r\n", "\n", $text);

        return $text === '' ? [] : explode("\n", rtrim($text, "\n"));
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{0: string, 1: string}>
     */
    private static function lcs(array $a, array $b): array
    {
        [$n, $m] = [count($a), count($b)];
        $lengths = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lengths[$i][$j] = $a[$i] === $b[$j]
                    ? $lengths[$i + 1][$j + 1] + 1
                    : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }
        $out = [];
        [$i, $j] = [0, 0];
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out[] = [' ', $a[$i]];
                $i++;
                $j++;
            } elseif ($lengths[$i + 1][$j] >= $lengths[$i][$j + 1]) {
                $out[] = ['-', $a[$i++]];
            } else {
                $out[] = ['+', $b[$j++]];
            }
        }
        while ($i < $n) {
            $out[] = ['-', $a[$i++]];
        }
        while ($j < $m) {
            $out[] = ['+', $b[$j++]];
        }

        return $out;
    }
}
