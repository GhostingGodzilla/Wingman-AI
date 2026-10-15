<?php

/**
 * Dependency-free contract tests for automatic game ports using small Laravel/Pelican
 * doubles. Run: php tests/ports.test.php. Not a panel smoke test.
 */

namespace App\Enums {
    enum SubuserPermission: string
    {
        case AllocationRead = 'allocation.read';
    }
}

namespace App\Models {
    class Node { public int $id = 1; public bool $maintenance = false; public function isUnderMaintenance(): bool { return $this->maintenance; } }
    class Allocation
    {
        public static array $rows = [];
        public string $alias;
        public function __construct(public int $id, public int $node_id, public string $ip, public int $port, public ?int $server_id = null) { $this->alias = $ip; }
        public static function query(): object
        {
            return new class {
                private array $where = [];
                private array $between = [];
                public function where(string $column, mixed $value): self { $this->where[$column] = $value; return $this; }
                public function whereBetween(string $column, array $range): self { $this->between = $range; return $this; }
                public function pluck(string $column): object
                {
                    $ports = array_values(array_map(fn (Allocation $a) => $a->port, array_filter(Allocation::$rows, fn (Allocation $a) => $a->node_id === $this->where['node_id'] && $a->port >= $this->between[0] && $a->port <= $this->between[1])));
                    return new class($ports) { public function __construct(private array $items) {} public function map(callable $c): self { return new self(array_map($c, $this->items)); } public function all(): array { return $this->items; } };
                }
            };
        }
    }
    class Server
    {
        public ?int $allocation_id = null;
        public int $node_id = 1;
        public Node $node;
        public object $egg;
        public array $values = [];
        public array $eggVariables = [];
        public function __construct(public int $id = 7, string $game = 'Risk of Rain 2')
        {
            $this->node = new Node();
            $this->egg = new class($this, $game) {
                public string $name;
                public function __construct(private Server $server, string $game) { $this->name = $game; }
                public function variables(): object
                {
                    return new class($this->server) {
                        private string $env = '';
                        public function __construct(private Server $server) {}
                        public function where(string $column, string $value): self { $this->env = $value; return $this; }
                        public function first(): ?object { return $this->server->eggVariables[$this->env] ?? null; }
                    };
                }
            };
        }
        public function __get(string $name): mixed
        {
            if ($name === 'allocation') {
                foreach (Allocation::$rows as $allocation) if ($allocation->id === $this->allocation_id) return $allocation;
                return null;
            }
            throw new \LogicException($name);
        }
        public function variables(): object
        {
            $rows = array_map(fn (object $v) => (object) ['env_variable' => $v->env_variable, 'default_value' => $v->default_value, 'server_value' => $this->values[$v->id] ?? null], $this->eggVariables);
            return new class(array_values($rows)) { public function __construct(private array $rows) {} public function get(): array { return $this->rows; } };
        }
        public function serverVariables(): object
        {
            return new class($this) { public function __construct(private Server $server) {} public function updateOrCreate(array $match, array $values): void { $this->server->values[$match['variable_id']] = $values['variable_value']; } };
        }
        public function allocations(): object
        {
            $ports = array_values(array_map(fn (Allocation $a) => $a->port, array_filter(Allocation::$rows, fn (Allocation $a) => $a->server_id === $this->id)));
            sort($ports);
            return new class($ports) {
                public function __construct(private array $ports) {}
                public function orderBy(string $column): self { return $this; }
                public function pluck(string $column): object { return new class($this->ports) { public function __construct(private array $items) {} public function map(callable $c): self { return new self(array_map($c, $this->items)); } public function all(): array { return $this->items; } }; }
            };
        }
        public function refresh(): self { return $this; }
    }
    class User
    {
        public function __construct(public bool $admin = true, public array $permissions = []) {}
        public function can(\App\Enums\SubuserPermission|string $permission, Server $server): bool
        {
            return $permission === 'update' ? $this->admin : in_array($permission->value, $this->permissions, true);
        }
    }
}

namespace App\Services\Allocations {
    class AssignmentService
    {
        public array $calls = [];
        public function handle(\App\Models\Node $node, array $data, ?\App\Models\Server $server = null): array
        {
            $this->calls[] = $data;
            $ids = [];
            foreach ($data['allocation_ports'] as $port) {
                $allocation = new \App\Models\Allocation(count(\App\Models\Allocation::$rows) + 1, $node->id, $data['allocation_ip'], (int) $port, $server?->id);
                \App\Models\Allocation::$rows[] = $allocation;
                $ids[] = $allocation->id;
            }
            if ($server && !$server->allocation_id) $server->allocation_id = $ids[0];
            return $ids;
        }
    }
}

namespace App\Repositories\Daemon {
    class DaemonServerRepository
    {
        public int $syncs = 0;
        public bool $fail = false;
        public function setServer(\App\Models\Server $server): self { return $this; }
        public function sync(): void { $this->syncs++; if ($this->fail) throw new \RuntimeException('Wings offline'); }
    }
}

namespace App\Facades {
    class Activity
    {
        public static array $events = [];
        public static function event(string $event): self { self::$events[] = [$event]; return new self(); }
        public function subject(mixed $subject): self { return $this; }
        public function actor(mixed $actor): self { self::$events[array_key_last(self::$events)][] = 'actor'; return $this; }
        public function property(string|array $key, mixed $value = null): self { self::$events[array_key_last(self::$events)][] = $key; return $this; }
        public function log(): void {}
    }
}

namespace Illuminate\Support\Facades {
    class TestLock { public function block(int $seconds, callable $callback): mixed { return $callback(); } }
    class Cache
    {
        public static array $locks = [];
        public static function lock(string $key, int $seconds): TestLock { self::$locks[] = $key; return new TestLock(); }
    }
}

namespace Illuminate\Validation {
    class ValidationException extends \RuntimeException
    {
        public static function withMessages(array $messages): self { return new self((string) reset($messages)); }
    }
}

namespace Pelican\AiHelpAssistant\Services {
    class AssistantSettings
    {
        public array $values = ['enabled' => true, 'ports_auto' => true, 'port_range_start' => 27000, 'port_range_end' => 27099, 'port_ip' => '0.0.0.0'];
        public function get(): array { return $this->values; }
    }
}

namespace {
    use App\Facades\Activity;
    use App\Models\Allocation;
    use App\Models\Server;
    use App\Models\User;
    use App\Repositories\Daemon\DaemonServerRepository;
    use App\Services\Allocations\AssignmentService;
    use Illuminate\Support\Facades\Cache;
    use Pelican\AiHelpAssistant\Services\AssistantSettings;
    use Pelican\AiHelpAssistant\Services\PortService;

    function app(string $class): object { return $GLOBALS['bindings'][$class]; }
    function config(string $key): mixed { return $key === 'app.url' ? 'https://ghostingsquad.duckdns.org' : null; }
    function logger(): object { return new class { public function info(string $m, array $c = []): void { $GLOBALS['logs'][] = ['info', $c['message'] ?? '']; } public function warning(string $m, array $c = []): void { $GLOBALS['logs'][] = ['warning', $c['reason'] ?? '']; } }; }

    require __DIR__ . '/../src/Support/GamePorts.php';
    require __DIR__ . '/../src/Services/PortService.php';

    function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
    function rejects(callable $operation, string $contains): void
    {
        try { $operation(); } catch (Throwable $exception) {
            check(str_contains($exception->getMessage(), $contains), 'Wrong rejection: ' . $exception->getMessage());
            return;
        }
        throw new RuntimeException('Expected rejection containing: ' . $contains);
    }
    function harness(string $game = 'Risk of Rain 2'): array
    {
        Allocation::$rows = [];
        Activity::$events = [];
        Cache::$locks = [];
        $GLOBALS['logs'] = [];
        $GLOBALS['bindings'] = [AssistantSettings::class => new AssistantSettings(), AssignmentService::class => new AssignmentService(), DaemonServerRepository::class => new DaemonServerRepository()];
        $server = new Server(7, $game);
        $server->eggVariables = $game === 'Risk of Rain 2'
            ? ['QUERY' => (object) ['id' => 11, 'env_variable' => 'QUERY', 'default_value' => '27016'], 'STEAM' => (object) ['id' => 12, 'env_variable' => 'STEAM', 'default_value' => '27017'], 'PLAYERS' => (object) ['id' => 13, 'env_variable' => 'PLAYERS', 'default_value' => '4']]
            : ['TELNET_PORT' => (object) ['id' => 21, 'env_variable' => 'TELNET_PORT', 'default_value' => '8081']];

        return [new PortService(), $server];
    }

    $count = 0;
    $runTest = function (string $name, callable $test) use (&$count): void {
        $test();
        $count++;
        echo "PASS $name\n";
    };

    $runTest('an admin sets up a game\'s ports from the block and its port settings follow', function () {
        [$ports, $server] = harness();
        $message = $ports->assign($server, new User());
        check(app(AssignmentService::class)->calls === [['allocation_ip' => '0.0.0.0', 'allocation_ports' => ['27000', '27001', '27002']]], 'Pelican must create the planned ports.');
        check($server->allocation_id === 1 && $server->allocation->port === 27000, 'The first port must be the main port.');
        check($server->values === [11 => '27001', 12 => '27002'], 'QUERY and STEAM must point at the new ports; other settings stay.');
        check(app(DaemonServerRepository::class)->syncs === 1 && Cache::$locks === ['wingman-ai:ports:1'], 'Wings must be updated under the node lock.');
        check(Activity::$events[0] === ['server:allocation.create', 'actor', ['allocation' => '0.0.0.0:27000,27001,27002', 'source' => 'wingman-ai']], 'The change must be audited with its actor.');
        check(str_contains($message, 'Ports 27000–27002 are set up (QUERY 27001, STEAM 27002)') && str_contains($message, 'ghostingsquad.duckdns.org:27000') && str_contains($message, '27000–27099 (TCP and UDP)'), 'The result must say where to join and what to forward: ' . $message);
        rejects(fn () => $ports->assign($server, new User()), 'already has ports');
    });

    $runTest('the next server gets the next free run and private ports stay private', function () {
        [$ports, $first] = harness();
        $ports->assign($first, new User());
        $second = new Server(8, '7 Days To Die');
        $second->eggVariables = ['TELNET_PORT' => (object) ['id' => 21, 'env_variable' => 'TELNET_PORT', 'default_value' => '8081']];
        $ports->assign($second, new User());
        check(end(app(AssignmentService::class)->calls)['allocation_ports'] === ['27003', '27004', '27005', '27006'] && $second->values === [], '7 Days To Die must get the next run, and telnet must not be moved or exposed.');
    });

    $runTest('only admins may set up ports and a full block is explained', function () {
        [$ports, $server] = harness();
        rejects(fn () => $ports->assign($server, new User(false, ['allocation.read'])), 'Only panel admins');
        app(AssistantSettings::class)->values['port_range_end'] = 27001;
        rejects(fn () => $ports->assign($server, new User()), 'not enough free ports left in 27000–27001');
        check(Allocation::$rows === [] && app(AssignmentService::class)->calls === [], 'Refusals must not create ports.');
    });

    $runTest('automatic setup runs only when turned on and the server has no ports', function () {
        [$ports, $server] = harness();
        app(AssistantSettings::class)->values['ports_auto'] = false;
        $ports->autoAssign($server);
        check(Allocation::$rows === [], 'Automatic setup must respect the setting.');
        app(AssistantSettings::class)->values['ports_auto'] = true;
        $server->node->maintenance = true;
        $ports->autoAssign($server);
        check(Allocation::$rows === [], 'Nothing may change during node maintenance.');
        $server->node->maintenance = false;
        app(DaemonServerRepository::class)->fail = true;
        $ports->autoAssign($server);
        check(count(Allocation::$rows) === 3 && Activity::$events[0][1] === ['allocation' => '0.0.0.0:27000,27001,27002', 'source' => 'wingman-ai'], 'Automatic setup must work without an actor, even if Wings is offline.');
        check($GLOBALS['logs'][0][0] === 'info', 'Automatic setup must be logged.');
        $ports->autoAssign($server);
        check(count(Allocation::$rows) === 3, 'A server with ports must be left alone.');
        [$ports, $server] = harness();
        app(AssistantSettings::class)->values['port_range_end'] = 27000;
        $ports->autoAssign($server);
        check($GLOBALS['logs'][0][0] === 'warning' && str_contains($GLOBALS['logs'][0][1], 'not enough free ports'), 'A failed automatic setup must be logged, not thrown.');
    });

    $runTest('port status shows the join address to permitted users', function () {
        [$ports, $server] = harness();
        check($ports->status($server, new User(false)) === null, 'Ports need allocation access.');
        check($ports->status($server, new User(false, ['allocation.read'])) === ['ports' => [], 'join' => null, 'can_assign' => false, 'range' => '27000–27099'], 'Without ports there is no address, and only admins may assign.');
        check($ports->status($server, new User())['can_assign'] === true, 'Admins may assign ports to a server without any.');
        $ports->assign($server, new User());
        check($ports->status($server, new User(false, ['allocation.read'])) === ['ports' => [27000, 27001, 27002], 'join' => 'ghostingsquad.duckdns.org:27000', 'can_assign' => false, 'range' => '27000–27099'], 'Ports and the join address must be shown.');
    });

    echo "$count port contract tests passed.\n";
}
