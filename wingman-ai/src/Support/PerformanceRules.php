<?php

declare(strict_types=1);

namespace Pelican\AiHelpAssistant\Support;

/**
 * Explains lag from resource samples, the server's limits, and lag warnings in the
 * console. A single short check can miss intermittent spikes; the report says so.
 */
final class PerformanceRules
{
    /**
     * @param  list<array<string, mixed>>  $samples  Wings utilization snapshots taken a moment apart.
     * @param  array{memory_mb: int, cpu_percent: int, disk_mb: int, startup: string}  $limits  Pelican limits; 0 means unlimited.
     */
    public static function analyze(string $state, array $samples, array $limits, string $logs): array
    {
        if (!in_array($state, ['running', 'starting'], true) || $samples === []) {
            return self::report('The server is not running, so there is nothing to measure.', 'Performance can only be checked while the server is running.', 'high', ['Current state: ' . ($state !== '' ? $state : 'unknown')], ['Start the server, let it run for a minute, and check again while the lag is happening.'], []);
        }

        $cpu = array_sum(array_map(fn (array $sample) => (float) ($sample['cpu_absolute'] ?? 0), $samples)) / count($samples);
        $memoryMb = max(array_map(fn (array $sample) => (int) ($sample['memory_bytes'] ?? 0), $samples)) / 1048576;
        $diskMb = (int) (end($samples)['disk_bytes'] ?? 0) / 1048576;
        $evidence = [
            sprintf('CPU: %.0f%% %s', $cpu, $limits['cpu_percent'] > 0 ? 'of a ' . $limits['cpu_percent'] . '% limit (100% = one CPU core)' : '(no limit; 100% = one CPU core)'),
            sprintf('Memory: %s %s', self::mb($memoryMb), $limits['memory_mb'] > 0 ? 'of ' . self::mb($limits['memory_mb']) : '(no limit)'),
            sprintf('Disk: %s %s', self::mb($diskMb), $limits['disk_mb'] > 0 ? 'of ' . self::mb($limits['disk_mb']) : '(no limit)'),
        ];

        $findings = [];
        if ($limits['memory_mb'] > 0 && $memoryMb >= 0.9 * $limits['memory_mb']) {
            $findings[] = ['high', 'Memory is nearly full.', 'The server is using ' . self::mb($memoryMb) . ' of its ' . self::mb($limits['memory_mb']) . ' limit. When memory runs out, games slow down from constant cleanup or crash.', ['Raise the server\'s memory limit (Admin → Servers → this server → Build), or reduce what uses memory: view distance, loaded worlds, or heavy mods.']];
        }
        if (preg_match('/-Xmx(\d+)([mMgG])/', $limits['startup'], $heap) === 1 && $limits['memory_mb'] > 0) {
            $heapMb = (int) $heap[1] * (strtolower($heap[2]) === 'g' ? 1024 : 1);
            if ($heapMb >= 0.95 * $limits['memory_mb']) {
                $findings[] = ['medium', 'Java is allowed to use (almost) all of the memory limit.', 'The startup command lets Java use ' . self::mb($heapMb) . ' of a ' . self::mb($limits['memory_mb']) . ' limit, leaving no room for Java\'s own overhead.', ['Set Java\'s maximum memory (-Xmx) to about 80-90% of the server\'s memory limit, or raise the limit.']];
            }
        }
        if ($limits['cpu_percent'] > 0 && $cpu >= 0.9 * $limits['cpu_percent']) {
            $findings[] = ['high', 'The server is using all of its CPU limit.', sprintf('It averaged %.0f%% CPU against a %d%% limit, so the game has to wait for CPU time.', $cpu, $limits['cpu_percent']), ['Raise the server\'s CPU limit, or reduce load: fewer loaded areas, entities, or heavy plugins and mods.']];
        }
        if ($limits['disk_mb'] > 0 && $diskMb >= 0.9 * $limits['disk_mb']) {
            $findings[] = ['medium', 'The disk is nearly full.', 'The server is using ' . self::mb($diskMb) . ' of ' . self::mb($limits['disk_mb']) . '. A full disk stops saves and can corrupt worlds.', ['Delete old logs, backups, or unused worlds from the Files page, or raise the disk limit.']];
        }

        $behind = [];
        preg_match_all("/Can't keep up! Is the server overloaded\\? Running (\\d+)ms or (\\d+) ticks behind/", $logs, $matches);
        foreach ($matches[1] as $milliseconds) {
            $behind[] = (int) $milliseconds;
        }
        if ($behind !== []) {
            $evidence[] = count($behind) . ' "Can\'t keep up" ' . (count($behind) === 1 ? 'warning' : 'warnings') . ' in recent output; worst: ' . number_format(max($behind)) . ' ms behind';
            $findings[] = ['high', 'The game reports it cannot keep up.', 'The game itself says it is falling behind. If the limits above are not being hit, the cause is usually something in the world: too many entities, big farms, a chunk-heavy area, or a heavy mod or plugin.', ['Lower the view and simulation distance.', 'Use a profiler such as Spark (/spark profiler) to find what takes the most time.', 'Reduce large farms or entity counts.']];
        }
        foreach ([
            '/The server has stopped responding!|\[Watchdog Thread\]/' => ['high', 'The server froze long enough for its watchdog to warn.', 'The main game thread stopped responding for a long time, which players see as heavy lag or timeouts.', ['Check the lines after the watchdog warning to see which plugin or mod was running.', 'Update or remove that plugin or mod.']],
            '/GC overhead limit exceeded|OutOfMemoryError/' => ['high', 'Java ran out of memory.', 'Java spent most of its time trying to free memory, which causes heavy lag before a crash.', ['Raise the memory limit and Java\'s -Xmx, or reduce memory use.']],
            '/(?<!Is the )server (?:is )?(?:overloaded|lagging)|tick took \d+ ?ms|hitch(?:ing)? detected/i' => ['medium', 'The game logged lag warnings.', 'The console shows the game itself reporting slow updates.', ['Check what was happening at the times of those warnings (players joining, saves, large builds).']],
        ] as $pattern => $finding) {
            if (preg_match($pattern, $logs, $match) === 1) {
                $evidence[] = 'Console: ' . mb_substr(trim($match[0]), 0, 200);
                $findings[] = $finding;
            }
        }

        if ($findings === []) {
            return self::report(
                'No resource limit is being hit and the console shows no lag warnings.',
                'At the moment of this check the server has spare CPU, memory, and disk. Lag that comes and goes may not show up in a short check, and lag can also come from players\' connections or the node itself.',
                'medium',
                $evidence,
                ['Run this check again while the lag is happening.', 'Ask whether every player lags or only some; if only some, it is likely their connection.', 'Ask the node admin whether other servers on the same machine are busy.'],
                ['A check taken during the lag.'],
            );
        }

        usort($findings, fn (array $left, array $right) => self::rank($right[0]) <=> self::rank($left[0]));
        $steps = array_merge(...array_map(fn (array $finding) => $finding[3], $findings));

        $more = count($findings) - 1;

        return self::report(
            $more > 0 ? rtrim($findings[0][1], '.') . ' (and ' . $more . ' more ' . ($more === 1 ? 'problem' : 'problems') . ').' : $findings[0][1],
            implode(' ', array_map(fn (array $finding) => $finding[1] . ' ' . $finding[2], $findings)),
            $findings[0][0],
            $evidence,
            array_values(array_unique($steps)),
            [],
        );
    }

    private static function rank(string $confidence): int
    {
        return ['low' => 0, 'medium' => 1, 'high' => 2][$confidence];
    }

    private static function mb(float|int $megabytes): string
    {
        return $megabytes >= 1024 ? sprintf('%.1f GB', $megabytes / 1024) : sprintf('%d MB', (int) round($megabytes));
    }

    private static function report(string $summary, string $cause, string $confidence, array $evidence, array $steps, array $needs): array
    {
        return [
            'summary' => $summary,
            'likely_cause' => $cause,
            'confidence' => $confidence,
            'evidence' => $evidence,
            'steps' => $steps,
            'needs_more_information' => $needs,
            'source' => 'rules',
        ];
    }
}
