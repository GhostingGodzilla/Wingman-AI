<?php

namespace Pelican\AiHelpAssistant\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use Illuminate\Support\Facades\Cache;
use Pelican\AiHelpAssistant\Support\RightSizeRules;
use Throwable;

/** Records hourly CPU, memory, and disk use per server so Wingman can suggest better limits. */
class UsageHistoryService
{
    private const MAX_SERVERS_PER_RUN = 200;

    /** Runs from Pelican's scheduler every ten minutes; reads usage through the panel's Wings connection. */
    public function sample(): void
    {
        $settings = app(AssistantSettings::class)->get();
        if (!($settings['enabled'] ?? true) || !($settings['usage_tracking_enabled'] ?? true)) {
            return;
        }
        // Installed, unsuspended servers only (Pelican clears status when a server is ready).
        foreach (Server::query()->whereNull('status')->orderBy('id')->limit(self::MAX_SERVERS_PER_RUN)->get() as $server) {
            try {
                if ($server->node->isUnderMaintenance()) {
                    continue;
                }
                $details = app(DaemonServerRepository::class)->setServer($server)->getDetails(3);
            } catch (Throwable) {
                continue; // One unreachable node must not stop the others.
            }
            $state = (string) ($details['state'] ?? '');
            if ($state === '') {
                continue;
            }
            $buckets = Cache::get($this->key($server->id), []);
            $buckets = RightSizeRules::record(is_array($buckets) ? $buckets : [], time(), $state, is_array($details['utilization'] ?? null) ? $details['utilization'] : []);
            Cache::put($this->key($server->id), $buckets, now()->addDays(8));
        }
    }

    /** @return array<string, mixed> */
    public function summary(Server $server): array
    {
        $buckets = Cache::get($this->key($server->id), []);

        return RightSizeRules::analyze(is_array($buckets) ? $buckets : [], ['memory_mb' => (int) $server->memory, 'cpu_percent' => (int) $server->cpu, 'disk_mb' => (int) $server->disk]);
    }

    /** Disk change over the last 24 hours in MB, or null without enough samples. */
    public function diskChangeLastDay(Server $server): ?int
    {
        $buckets = Cache::get($this->key($server->id), []);
        if (!is_array($buckets)) {
            return null;
        }
        $since = gmdate('Y-m-d\TH', time() - 86400);
        $recent = array_filter($buckets, fn (mixed $bucket, string $hour) => $hour >= $since && is_array($bucket), ARRAY_FILTER_USE_BOTH);
        if (count($recent) < 2) {
            return null;
        }

        return (int) round(((int) end($recent)['disk_last'] - (int) reset($recent)['disk_first']) / 1048576);
    }

    private function key(int $serverId): string
    {
        return 'wingman:usage:' . $serverId;
    }
}
