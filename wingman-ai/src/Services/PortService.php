<?php

namespace Pelican\AiHelpAssistant\Services;

use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Models\Allocation;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Services\Allocations\AssignmentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Pelican\AiHelpAssistant\Support\GamePorts;
use Throwable;

/**
 * Gives a server the public ports its game needs from the admin's forwarded port
 * block, and points the game's port settings at them. Admin consoles stay private.
 */
class PortService
{
    /** @return array{ports: list<int>, join: ?string, can_assign: bool, range: string}|null */
    public function status(Server $server, User $user): ?array
    {
        $admin = $user->can('update', $server);
        if (!$admin && !$user->can(SubuserPermission::AllocationRead, $server)) {
            return null;
        }
        $settings = app(AssistantSettings::class)->get();
        $main = $server->allocation;

        return [
            'ports' => $server->allocations()->orderBy('port')->pluck('port')->map(fn ($port) => (int) $port)->all(),
            'join' => $main !== null ? $this->host($main) . ':' . $main->port : null,
            'can_assign' => $admin && $main === null,
            'range' => (int) $settings['port_range_start'] . '–' . (int) $settings['port_range_end'],
        ];
    }

    /** Sets up ports for a server without any. $user is null when done automatically. */
    public function assign(Server $server, ?User $user): string
    {
        $this->assert($user === null || $user->can('update', $server), 'Only panel admins can set up ports.');
        $this->assert($server->allocation_id === null, 'This server already has ports. Change them in Admin → Servers → this server.');
        $settings = app(AssistantSettings::class)->get();
        [$start, $end, $ip] = [(int) $settings['port_range_start'], (int) $settings['port_range_end'], (string) $settings['port_ip']];

        // One node at a time, so two servers never get the same ports.
        return Cache::lock('wingman-ai:ports:' . $server->node_id, 30)->block(10, function () use ($server, $user, $start, $end, $ip) {
            $variables = [];
            foreach ($server->variables()->get() as $variable) {
                $variables[(string) $variable->env_variable] = (string) ($variable->server_value ?? $variable->default_value ?? '');
            }
            $needs = GamePorts::needs((string) $server->egg->name, $variables);
            $taken = Allocation::query()->where('node_id', $server->node_id)->whereBetween('port', [$start, $end])
                ->pluck('port')->map(fn ($port) => (int) $port)->all();
            $plan = GamePorts::plan($needs, $taken, $start, $end);
            $this->assert($plan !== null, 'There are not enough free ports left in ' . $start . '–' . $end . '. Make the range bigger in Wingman AI settings, and forward the bigger range in your router too.');

            $ports = [$plan['main'], ...$plan['following'], ...array_values($plan['variables'])];
            // Pelican creates the ports for this server and makes the first one its main port.
            app(AssignmentService::class)->handle($server->node, ['allocation_ip' => $ip, 'allocation_ports' => array_map('strval', $ports)], $server);
            foreach ($plan['variables'] as $name => $port) {
                $variable = $server->egg->variables()->where('env_variable', $name)->first();
                if ($variable !== null) {
                    $server->serverVariables()->updateOrCreate(['variable_id' => $variable->id], ['variable_value' => (string) $port]);
                }
            }
            try {
                app(DaemonServerRepository::class)->setServer($server->refresh())->sync();
            } catch (Throwable) {
                // Wings reloads the server's settings when it next starts.
            }
            $activity = Activity::event('server:allocation.create')->subject($server);
            if ($user !== null) {
                $activity = $activity->actor($user);
            }
            $activity->property(['allocation' => $ip . ':' . implode(',', $ports), 'source' => 'wingman-ai'])->log();

            $settingsText = $plan['variables'] === [] ? '' : ' (' . implode(', ', array_map(fn ($name, $port) => $name . ' ' . $port, array_keys($plan['variables']), $plan['variables'])) . ')';

            return 'Ports ' . min($ports) . (count($ports) > 1 ? '–' . max($ports) : '') . ' are set up' . $settingsText . '. Players join at '
                . $this->host($server->allocation) . ':' . $plan['main'] . '. Restart the server to use them, and make sure ports ' . $start . '–' . $end . ' (TCP and UDP) are forwarded to this machine in your router.';
        });
    }

    /** Called after an install: give a server without ports its ports, if turned on. */
    public function autoAssign(Server $server): void
    {
        $settings = app(AssistantSettings::class)->get();
        if (!($settings['enabled'] ?? true) || !($settings['ports_auto'] ?? true) || $server->allocation_id !== null || $server->node->isUnderMaintenance()) {
            return;
        }
        try {
            $message = $this->assign($server, null);
            logger()->info('Wingman AI set up ports automatically.', ['server_id' => $server->id, 'message' => $message]);
        } catch (Throwable $exception) {
            logger()->warning('Wingman AI could not set up ports automatically.', ['server_id' => $server->id, 'reason' => $exception->getMessage()]);
        }
    }

    private function host(?Allocation $allocation): string
    {
        return (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: ($allocation?->alias ?? ''));
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw ValidationException::withMessages(['ports' => $message]);
        }
    }
}
