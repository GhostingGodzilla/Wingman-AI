<?php

namespace Pelican\AiHelpAssistant\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use Throwable;

/** Recent console lines through the panel's Wings connection; empty when unavailable. */
class ConsoleHistory
{
    public function recent(Server $server, int $lines = 100, int $maxBytes = 48000): string
    {
        try {
            $rows = app(DaemonServerRepository::class)->setServer($server)->getHttpClient()->connectTimeout(5)->timeout(10)
                ->get('/api/servers/' . $server->uuid . '/logs', ['size' => $lines])->throw()->json('data');
        } catch (Throwable) {
            return '';
        }
        if (!is_array($rows)) {
            return '';
        }

        // Keep whole lines from the end so a secret label stays attached to its value.
        $kept = [];
        $bytes = 0;
        foreach (array_reverse(array_slice($rows, -$lines)) as $row) {
            if (!is_string($row) || $bytes + strlen($row) + 1 > $maxBytes) {
                continue;
            }
            $kept[] = $row;
            $bytes += strlen($row) + 1;
        }

        return implode("\n", array_reverse($kept));
    }
}
