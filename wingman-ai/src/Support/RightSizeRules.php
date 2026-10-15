<?php

declare(strict_types=1);

namespace Pelican\AiHelpAssistant\Support;

/**
 * Turns hourly usage buckets into plain suggestions about a server's limits.
 * A bucket: mem_max (bytes), cpu_max, cpu_sum, cpu_n, disk_first, disk_last (bytes), running, samples.
 */
final class RightSizeRules
{
    public const KEEP_HOURS = 168;

    /** Add one Wings utilization sample to the hourly buckets. */
    public static function record(array $buckets, int $time, string $state, array $utilization): array
    {
        $hour = gmdate('Y-m-d\TH', $time);
        $bucket = $buckets[$hour] ?? ['mem_max' => 0, 'cpu_max' => 0.0, 'cpu_sum' => 0.0, 'cpu_n' => 0, 'disk_first' => null, 'disk_last' => null, 'running' => 0, 'samples' => 0];
        $disk = (int) ($utilization['disk_bytes'] ?? 0);
        $bucket['samples']++;
        $bucket['disk_first'] ??= $disk;
        $bucket['disk_last'] = $disk;
        if ($state === 'running') {
            $cpu = (float) ($utilization['cpu_absolute'] ?? 0);
            $bucket['running']++;
            $bucket['mem_max'] = max($bucket['mem_max'], (int) ($utilization['memory_bytes'] ?? 0));
            $bucket['cpu_max'] = max($bucket['cpu_max'], $cpu);
            $bucket['cpu_sum'] += $cpu;
            $bucket['cpu_n']++;
        }
        $buckets[$hour] = $bucket;
        ksort($buckets);

        return array_slice($buckets, -self::KEEP_HOURS, null, true);
    }

    /**
     * @param  array{memory_mb: int, cpu_percent: int, disk_mb: int}  $limits  0 means unlimited.
     * @return array{running_hours: int, memory_peak_mb: int, cpu_peak: int, cpu_average: int, disk_mb: int, disk_growth_mb_per_day: ?int, suggestions: list<string>}
     */
    public static function analyze(array $buckets, array $limits): array
    {
        $running = array_filter($buckets, fn (array $bucket) => $bucket['running'] > 0);
        $hours = count($running);
        $memoryPeak = $hours > 0 ? (int) round(max(array_column($running, 'mem_max')) / 1048576) : 0;
        $cpuPeak = $hours > 0 ? (int) round(max(array_column($running, 'cpu_max'))) : 0;
        $cpuSamples = array_sum(array_column($running, 'cpu_n'));
        $cpuAverage = $cpuSamples > 0 ? (int) round(array_sum(array_column($running, 'cpu_sum')) / $cpuSamples) : 0;
        $last = $buckets === [] ? null : end($buckets);
        $first = $buckets === [] ? null : reset($buckets);
        $diskMb = $last !== null ? (int) round(($last['disk_last'] ?? 0) / 1048576) : 0;

        $growth = null;
        if ($first !== null && $last !== null && count($buckets) >= 24) {
            $days = max(1, (strtotime(array_key_last($buckets) . ':00:00Z') - strtotime(array_key_first($buckets) . ':00:00Z')) / 86400);
            $growth = (int) round((($last['disk_last'] ?? 0) - ($first['disk_first'] ?? 0)) / 1048576 / $days);
        }

        $suggestions = [];
        if ($hours < 24) {
            $suggestions[] = 'Wingman is still learning how this server is used (' . $hours . ' of 24 running hours recorded). Suggestions appear after a day of use.';
        } else {
            $days = (int) floor($hours / 24);
            $memory = $limits['memory_mb'];
            if ($memory > 0 && $memoryPeak >= 0.9 * $memory) {
                $suggestions[] = 'Memory reached ' . self::size($memoryPeak) . ' of the ' . self::size($memory) . ' limit. Raise the limit to about ' . self::size(self::roundUp($memoryPeak * 1.25)) . ' so it does not run out.';
            } elseif ($memory > 0 && $hours >= 72 && $memoryPeak <= 0.5 * $memory) {
                $suggestions[] = 'In ' . $days . ' days, memory never went above ' . self::size($memoryPeak) . ' of the ' . self::size($memory) . ' limit. About ' . self::size(max(512, self::roundUp($memoryPeak * 1.5))) . ' would be enough, which frees memory for other servers.';
            } elseif ($memory === 0 && $memoryPeak > 0) {
                $suggestions[] = 'This server has no memory limit. Its highest use was ' . self::size($memoryPeak) . '; a limit of about ' . self::size(max(512, self::roundUp($memoryPeak * 1.5))) . ' would protect your other servers if it ever runs away.';
            }
            $cpu = $limits['cpu_percent'];
            $cappedHours = count(array_filter($running, fn (array $bucket) => $cpu > 0 && $bucket['cpu_max'] >= 0.95 * $cpu));
            if ($cpu > 0 && $cappedHours >= 3) {
                $suggestions[] = 'CPU hit its ' . $cpu . '% limit in ' . $cappedHours . ' of the last ' . $hours . ' running hours. If players notice lag then, raise the CPU limit.';
            }
            $disk = $limits['disk_mb'];
            if ($disk > 0 && $diskMb >= 0.9 * $disk) {
                $suggestions[] = 'The disk is ' . (int) round($diskMb / $disk * 100) . '% full (' . self::size($diskMb) . ' of ' . self::size($disk) . '). Delete old files or raise the limit soon.';
            } elseif ($disk > 0 && $growth !== null && $growth > 0 && ($disk - $diskMb) / $growth < 14) {
                $daysLeft = max(1, (int) floor(($disk - $diskMb) / $growth));
                $suggestions[] = 'The disk grows about ' . self::size($growth) . ' a day; at that rate it is full in about ' . $daysLeft . ' ' . ($daysLeft === 1 ? 'day' : 'days') . '.';
            }
            if ($suggestions === []) {
                $suggestions[] = 'The limits fit how this server is used. Nothing to change.';
            }
        }

        return [
            'running_hours' => $hours,
            'memory_peak_mb' => $memoryPeak,
            'cpu_peak' => $cpuPeak,
            'cpu_average' => $cpuAverage,
            'disk_mb' => $diskMb,
            'disk_growth_mb_per_day' => $growth,
            'suggestions' => $suggestions,
        ];
    }

    private static function roundUp(float $megabytes): int
    {
        return (int) (ceil($megabytes / 512) * 512);
    }

    private static function size(int $megabytes): string
    {
        return $megabytes >= 1024 ? rtrim(rtrim(number_format($megabytes / 1024, 1), '0'), '.') . ' GB' : $megabytes . ' MB';
    }
}
