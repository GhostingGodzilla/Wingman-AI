<?php

namespace Pelican\AiHelpAssistant\Services;

use App\Enums\SubuserPermission;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Carbon;

/** What happened to a server in the last 24 hours, from the panel's own activity log. */
class DailySummaryService
{
    private const EVENTS = ['server:crashed', 'server:power.start', 'server:power.restart', 'server:power.stop', 'server:power.kill', 'server:settings.reinstall'];

    /** @return array{lines: list<string>, quiet: bool}|null */
    public function summary(Server $server, User $user): ?array
    {
        if (!$user->can(SubuserPermission::ActivityRead, $server)) {
            return null;
        }
        $since = Carbon::now()->subDay();
        $counts = ActivityLog::query()
            ->where('timestamp', '>=', $since)
            ->whereIn('event', self::EVENTS)
            ->whereHas('subjects', fn ($query) => $query->where('subject_type', $server->getMorphClass())->where('subject_id', $server->id))
            ->selectRaw('event, count(*) as total')->groupBy('event')
            ->pluck('total', 'event')->map(fn ($total) => (int) $total)->all();
        $count = fn (string ...$events) => array_sum(array_map(fn (string $event) => $counts[$event] ?? 0, $events));

        $backups = $server->backups()->where('is_successful', true)->where('completed_at', '>=', $since)->count();
        $failedBackups = $server->backups()->where('is_successful', false)->whereNotNull('completed_at')->where('completed_at', '>=', $since)->count();
        $disk = app(UsageHistoryService::class)->diskChangeLastDay($server);

        $lines = [];
        foreach ([
            [$count('server:crashed'), 'crash', 'crashes'],
            [$count('server:power.start', 'server:power.restart'), 'start or restart', 'starts or restarts'],
            [$count('server:power.stop', 'server:power.kill'), 'stop', 'stops'],
            [$count('server:settings.reinstall'), 'reinstall', 'reinstalls'],
            [$backups, 'backup made', 'backups made'],
            [$failedBackups, 'backup failed', 'backups failed'],
        ] as [$total, $one, $many]) {
            if ($total > 0) {
                $lines[] = $total . ' ' . ($total === 1 ? $one : $many);
            }
        }
        if ($disk !== null && abs($disk) >= 1) {
            $lines[] = 'Disk use ' . ($disk > 0 ? 'grew' : 'shrank') . ' by ' . abs($disk) . ' MB';
        }

        return ['lines' => $lines, 'quiet' => $lines === []];
    }
}
