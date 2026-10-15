<?php

declare(strict_types=1);

// Exercise the real widget with narrow dependencies; no browser poll may start a
// server or request AI. Full Livewire rendering remains an installed-panel check.
namespace Filament\Widgets {
    class Widget {}
}

namespace Filament\Actions\Contracts { interface HasActions {} }
namespace Filament\Actions\Concerns { trait InteractsWithActions {} }
namespace Filament\Schemas\Contracts { interface HasSchemas {} }
namespace Filament\Schemas\Concerns { trait InteractsWithSchemas {} }

namespace Filament\Facades {
    class Filament
    {
        public static mixed $tenant = null;

        public static function getTenant(): mixed
        {
            return self::$tenant;
        }
    }
}

namespace Livewire\Attributes {
    #[\Attribute(\Attribute::TARGET_PROPERTY)]
    class Locked {}

    #[\Attribute(\Attribute::TARGET_METHOD)]
    class On
    {
        public function __construct(public string $event) {}
    }
}

namespace Filament\Notifications {
    class Notification
    {
        public static array $sent = [];
        private array $data = [];
        public static function make(): self { return new self(); }
        public function title(string $title): self { $this->data['title'] = $title; return $this; }
        public function body(string $body): self { $this->data['body'] = $body; return $this; }
        public function danger(): self { $this->data['status'] = 'danger'; return $this; }
        public function success(): self { $this->data['status'] = 'success'; return $this; }
        public function send(): void { self::$sent[] = $this->data; }
    }
}

namespace Pelican\AiHelpAssistant\Services {
    class EggAdvisorService
    {
        public array $calls = [];
        public bool $available = false;
        public ?array $lastChange = null;
        public ?\Throwable $failure = null;

        public function available(\App\Models\Server $server, \App\Models\User $user): bool
        {
            $this->calls[] = 'available';

            return $this->available;
        }

        public function lastChange(\App\Models\Server $server, \App\Models\User $user): ?array
        {
            $this->calls[] = 'lastChange';

            return $this->lastChange;
        }

        public function propose(\App\Models\Server $server, \App\Models\User $user): array
        {
            $this->calls[] = 'propose';
            if ($this->failure !== null) { throw $this->failure; }

            return ['id' => 'proposal-1', 'summary' => 'Retry SteamCMD', 'reason' => '', 'changes' => [['label' => 'Install script', 'diff' => []]], 'warnings' => [], 'reinstall' => true, 'egg_name' => 'Risk of Rain 2', 'servers_using' => 1, 'copies_script' => false];
        }

        public function apply(\App\Models\Server $server, \App\Models\User $user, string $id, bool $reinstall): string
        {
            $this->calls[] = 'apply';

            return 'applied';
        }

        public function undo(\App\Models\Server $server, \App\Models\User $user): string
        {
            $this->calls[] = 'undo';

            return 'undone';
        }
    }

    class AssistantSettings
    {
        public array $settings = ['enabled' => true];

        public function get(): array { return $this->settings; }
    }

    class AutomaticStartupService
    {
        public array $calls = [];
        public array $statuses = [];
        public array $reports = [];
        public ?\Throwable $failure = null;

        public function attempt(\App\Models\Server $server, string $action = 'start'): void
        {
            $this->calls[] = ['attempt', $server->id, $action];
            if ($this->failure !== null) { throw $this->failure; }
        }

        public function status(\App\Models\Server $server, \App\Models\User $user): array
        {
            $this->calls[] = ['status', $server->id, $user];
            if ($this->failure !== null) { throw $this->failure; }

            return $this->statuses[$server->id] ?? ['status' => 'idle', 'latest_timestamp' => null];
        }

        public function latest(\App\Models\Server $server, \App\Models\User $user): ?array
        {
            $this->calls[] = ['latest', $server->id, $user];

            return $this->reports[$server->id] ?? null;
        }
    }

    class RepairService
    {
        public array $calls = [];
        public ?array $offer = null;
        public ?\Throwable $failure = null;

        public function offer(\App\Models\Server $server, \App\Models\User $user, ?array $report): ?array
        {
            $this->calls[] = ['offer', $server->id, $report];
            if ($this->failure !== null) { throw $this->failure; }

            return $this->offer;
        }

        public function apply(\App\Models\Server $server, \App\Models\User $user, ?array $report, ?string $image = null): string
        {
            $this->calls[] = ['apply', $server->id];

            return 'applied';
        }
    }

    class StartupMonitorService
    {
        public array $calls = [];

        public function prepare(\App\Models\Server $server, \App\Models\User $user, string $mode): array
        {
            $this->calls[] = ['prepare', $server->id, $mode];

            return ['run_id' => 'manual-run'];
        }

        public function complete(\App\Models\Server $server, \App\Models\User $user, string $id, array $capture): array
        {
            $this->calls[] = ['complete', $server->id, $id];

            return ['summary' => 'Manual diagnosis', 'source' => 'rules'];
        }
    }
}

namespace {
    require __DIR__ . '/integration/framework-stubs.php';

    use App\Models\Server;
    use App\Models\User;
    use Filament\Facades\Filament;
    use Livewire\Attributes\Locked;
    use Pelican\AiHelpAssistant\Filament\Server\Widgets\AiHelpAssistant;
    use Pelican\AiHelpAssistant\Services\AssistantSettings;
    use Filament\Notifications\Notification;
    use Pelican\AiHelpAssistant\Services\AutomaticStartupService;
    use Pelican\AiHelpAssistant\Services\EggAdvisorService;
    use Pelican\AiHelpAssistant\Services\RepairService;
    use Pelican\AiHelpAssistant\Services\StartupMonitorService;

    function app(string $class): object
    {
        return $GLOBALS['ui_services'][$class] ?? throw new RuntimeException('Unexpected service requested: ' . $class);
    }

    function user(): ?User
    {
        return $GLOBALS['ui_user'] ?? null;
    }

    function collect(array $values): object
    {
        return new class($values) {
            public function __construct(private array $values) {}
            public function flatten(): self { $flat = []; array_walk_recursive($this->values, function ($value) use (&$flat) { $flat[] = $value; }); return new self($flat); }
            public function first(): mixed { return $this->values[0] ?? null; }
        };
    }

    function logger(): object
    {
        return new class { public function warning(string $message, array $context = []): void { $GLOBALS['ui_logs'][] = [$message, $context]; } };
    }

    require dirname(__DIR__) . '/src/Filament/Server/Widgets/AiHelpAssistant.php';

    function uiExpect(bool $condition, string $message): void
    {
        if (!$condition) { throw new RuntimeException($message); }
    }

    function uiFixture(bool $startupRead = true): array
    {
        $server = new Server();
        Filament::$tenant = $server;
        $GLOBALS['ui_user'] = new User($startupRead ? ['websocket.connect', 'startup.read'] : ['websocket.connect']);
        $automatic = new AutomaticStartupService();
        $manual = new StartupMonitorService();
        $GLOBALS['ui_services'] = [AutomaticStartupService::class => $automatic, StartupMonitorService::class => $manual, AssistantSettings::class => new AssistantSettings(), RepairService::class => new RepairService(), EggAdvisorService::class => new EggAdvisorService()];
        Notification::$sent = [];

        return [new AiHelpAssistant(), $automatic, $manual, $server];
    }

    function uiText(AiHelpAssistant $widget): string
    {
        return (new ReflectionMethod($widget, 'automaticStatusText'))->invoke($widget);
    }

    function uiFixOffer(AiHelpAssistant $widget): ?array
    {
        return (new ReflectionMethod($widget, 'fixOffer'))->invoke($widget);
    }

    $tests = [];
    $tests['initial mount loads an existing automatic diagnosis without manual actions'] = static function (): void {
        [$widget, $automatic, $manual] = uiFixture();
        $automatic->statuses[1] = ['status' => 'failed', 'latest_timestamp' => '2026-10-14T23:00:00+00:00'];
        $automatic->reports[1] = ['summary' => 'A required file is missing.', 'generated_at' => '2026-10-14T23:00:00+00:00', 'outcome' => 'failed'];
        $widget->mount();
        uiExpect($widget->autoReport === $automatic->reports[1], 'Prior automatic report was not loaded.');
        uiExpect($widget->autoStatus['status'] === 'failed', 'Current automatic status was not loaded.');
        uiExpect(array_column($automatic->calls, 0) === ['status', 'latest'], 'Polling performed an action other than reading status/results.');
        uiExpect($manual->calls === [] && $widget->report === null, 'Initial automatic load invoked a manual diagnosis.');
    };

    $tests['polling updates automatic results while preserving a manual report'] = static function (): void {
        [$widget, $automatic, $manual] = uiFixture();
        $widget->report = ['summary' => 'A manual explanation'];
        $automatic->reports[1] = ['summary' => 'New automatic explanation'];
        $widget->refreshAutomaticDiagnosis();
        uiExpect($widget->report['summary'] === 'A manual explanation', 'Polling overwrote the manual report.');
        uiExpect($widget->autoReport['summary'] === 'New automatic explanation', 'Automatic result did not refresh.');
        uiExpect($manual->calls === [], 'A browser poll triggered capture or power.');
    };

    $tests['users without startup settings access cannot retrieve owner-context automatic reports'] = static function (): void {
        [$widget, $automatic, $manual] = uiFixture(startupRead: false);
        $widget->autoReport = ['summary' => 'Previously visible privileged report'];
        $automatic->reports[1] = ['summary' => 'Owner-context report'];
        $widget->mount();
        uiExpect($widget->autoReport === null && $automatic->calls === [], 'Restricted user read a privileged automatic report.');
        uiExpect($widget->autoStatus['status'] === 'permission_required', 'Missing startup permission was not explained.');
        uiExpect($widget->prepareDiagnosis('existing')['run_id'] === 'manual-run', 'Console-only user lost manual diagnosis access.');
        uiExpect(count($manual->calls) === 1, 'Manual action was not preserved.');
    };

    $tests['each poll resolves the current tenant and authenticated permissions again'] = static function (): void {
        [$widget, $automatic] = uiFixture();
        $widget->mount();
        $other = new Server();
        $other->id = 2;
        Filament::$tenant = $other;
        $GLOBALS['ui_user'] = new User(['websocket.connect', 'startup.read'], 2);
        $automatic->reports[2] = ['summary' => 'Second server report'];
        $widget->refreshAutomaticDiagnosis();
        uiExpect($widget->autoReport['summary'] === 'Second server report', 'Polling used a stale or public server identity.');
        uiExpect(array_slice(array_column($automatic->calls, 1), -2) === [2, 2], 'Read methods received the wrong server.');
        $GLOBALS['ui_user']->permissions = [];
        try {
            $widget->refreshAutomaticDiagnosis();
            throw new RuntimeException('Revoked permission was accepted.');
        } catch (IntegrationAbortException $error) {
            uiExpect($error->status === 403, 'Revoked permission returned the wrong result.');
        }
    };

    $tests['startup permission revocation clears cached automatic widget state'] = static function (): void {
        [$widget, $automatic] = uiFixture();
        $automatic->reports[1] = ['summary' => 'Private owner report'];
        $widget->mount();
        $before = count($automatic->calls);
        $GLOBALS['ui_user']->permissions = ['websocket.connect'];
        $widget->refreshAutomaticDiagnosis();
        uiExpect($widget->autoReport === null && count($automatic->calls) === $before, 'Revocation retained or reloaded a privileged report.');
    };

    $tests['disabled queue cooldown and daily-limit states show fixed actionable text'] = static function (): void {
        [$widget] = uiFixture();
        foreach ([['status' => 'disabled'], ['status' => 'requires_queue'], ['status' => 'disabled', 'reason' => 'requires_queue'], ['status' => 'idle', 'reason' => 'queue_unavailable'], ['status' => 'idle', 'reason' => 'cooldown'], ['status' => 'idle', 'reason' => 'daily_limit']] as $state) {
            $widget->autoStatus = $state;
            uiExpect(uiText($widget) !== '' && !str_contains(uiText($widget), '{'), 'Automatic state had no readable explanation.');
        }
        $widget->autoStatus = ['status' => 'untrusted-provider-secret', 'reason' => 'untrusted-provider-secret'];
        uiExpect(!str_contains(uiText($widget), 'untrusted-provider-secret'), 'Raw backend dictionaries were echoed into status text.');
        $widget->autoStatus = ['status' => 'failed', 'reason' => 'monitor_unavailable'];
        uiExpect(!str_contains(uiText($widget), 'startup failed'), 'A monitor outage was described as a server crash.');
    };

    $tests['backend read failures do not reveal exceptions or stale reports'] = static function (): void {
        [$widget, $automatic] = uiFixture();
        $widget->autoReport = ['summary' => 'Stale private report'];
        $automatic->failure = new RuntimeException('secret-api-key-in-exception');
        $widget->refreshAutomaticDiagnosis();
        uiExpect($widget->autoReport === null && $widget->autoStatus === ['status' => 'unavailable'], 'Failure did not clear stale automatic data.');
        uiExpect(!str_contains(uiText($widget), 'secret-api-key'), 'Exception details were exposed.');
    };

    $tests['backend status dictionaries cannot put unexpected data into browser-visible state'] = static function (): void {
        [$widget, $automatic] = uiFixture();
        $automatic->statuses[1] = ['status' => 'secret-status-value', 'reason' => 'secret-reason-value', 'raw_exception' => 'secret-error-text'];
        $widget->refreshAutomaticDiagnosis();
        uiExpect($widget->autoStatus === ['status' => 'idle', 'reason' => null], 'Unexpected status data entered the Livewire snapshot.');
    };

    $tests['report state is locked and the polling action accepts no client identity or capture'] = static function (): void {
        $reflection = new ReflectionClass(AiHelpAssistant::class);
        foreach (['report', 'autoReport', 'autoStatus'] as $property) {
            uiExpect($reflection->getProperty($property)->getAttributes(Locked::class) !== [], 'Client can overwrite server report state: ' . $property);
        }
        uiExpect($reflection->getMethod('refreshAutomaticDiagnosis')->getNumberOfParameters() === 0, 'Polling accepts a client-selected context.');
        uiExpect(!$reflection->hasProperty('server'), 'A public server model controls the widget identity.');
    };

    $tests['automatic timestamps are explicit and absent timestamps cannot become the current time'] = static function (): void {
        [$widget] = uiFixture();
        $method = new ReflectionMethod($widget, 'automaticReportTime');
        $widget->autoReport = ['generated_at' => '2026-10-14T23:00:00+00:00'];
        uiExpect($method->invoke($widget) === 'Oct 14, 2026 23:00 UTC', 'Report timestamp is ambiguous.');
        $widget->autoReport = [];
        uiExpect($method->invoke($widget) === 'Time unavailable', 'Missing report timestamp became the current time.');
    };

    $tests['normal console power intent is observed only for the current server and permitted start or restart'] = static function (): void {
        [$widget, $automatic, $manual, $server] = uiFixture();
        $GLOBALS['ui_user']->permissions = ['websocket.connect', 'control.start'];
        $widget->observeNormalPower($server->uuid, 'start');
        $widget->observeNormalPower($server->uuid, 'restart');
        $widget->observeNormalPower('other-server-uuid', 'start');
        $widget->observeNormalPower($server->uuid, 'stop');
        uiExpect($automatic->calls === [['attempt', 1, 'start']], 'Intent trusted an unrelated UUID, action, or permission.');
        $GLOBALS['ui_user']->permissions = ['websocket.connect', 'control.restart'];
        $widget->observeNormalPower($server->uuid, 'restart');
        uiExpect(end($automatic->calls) === ['attempt', 1, 'restart'], 'Restart intent was not observed with its actual action.');
        uiExpect($manual->calls === [], 'The intent observer sent a manual or power action.');
        $attribute = (new ReflectionMethod($widget, 'observeNormalPower'))->getAttributes(\Livewire\Attributes\On::class)[0]->newInstance();
        uiExpect($attribute->event === 'setServerState', 'The widget does not listen for normal console power events.');
    };

    $tests['observer errors and unauthenticated power events never interrupt normal console actions'] = static function (): void {
        [$widget, $automatic, , $server] = uiFixture();
        $GLOBALS['ui_user']->permissions = ['websocket.connect', 'control.start'];
        $automatic->failure = new RuntimeException('secret-observer-error');
        $widget->observeNormalPower($server->uuid, 'start');
        $GLOBALS['ui_user'] = null;
        $before = count($automatic->calls);
        $widget->observeNormalPower($server->uuid, 'start');
        uiExpect(count($automatic->calls) === $before, 'An unauthenticated event invoked automatic observation.');
    };

    $tests['fix box asks the repair service about the displayed report and polling never repairs'] = static function (): void {
        [$widget, $automatic, , $server] = uiFixture();
        $repairs = $GLOBALS['ui_services'][RepairService::class];
        uiExpect(uiFixOffer($widget) === null && $repairs->calls === [], 'No report must mean no fix offer and no repair lookup.');
        $report = ['summary' => 'The game or application files are missing.', 'generated_at' => '2026-10-14T23:00:00+00:00', 'fix' => ['type' => 'reinstall']];
        $automatic->reports[1] = $report;
        $widget->refreshAutomaticDiagnosis();
        $repairs->offer = ['type' => 'reinstall'];
        uiExpect(uiFixOffer($widget) === ['type' => 'reinstall'], 'A permitted repair was not offered.');
        uiExpect($repairs->calls[0] === ['offer', $server->id, $report], 'The offer must be checked against the displayed automatic report.');
        $repairs->offer = null;
        uiExpect(uiFixOffer($widget) === null, 'A repair the service refuses must not be shown.');
        $repairs->failure = new RuntimeException('secret-repair-error');
        uiExpect(uiFixOffer($widget) === null, 'A repair lookup failure must hide the fix box.');
        for ($i = 0; $i < 3; $i++) { $widget->refreshAutomaticDiagnosis(); }
        uiExpect(!in_array('apply', array_column($repairs->calls, 0), true), 'Polling applied a repair.');
    };

    $tests['an applied fix is hidden for that report but not for a new one'] = static function (): void {
        [$widget, $automatic] = uiFixture();
        $repairs = $GLOBALS['ui_services'][RepairService::class];
        $repairs->offer = ['type' => 'eula'];
        $automatic->reports[1] = ['summary' => 'EULA', 'generated_at' => '2026-10-14T23:00:00+00:00', 'fix' => ['type' => 'eula']];
        $widget->refreshAutomaticDiagnosis();
        $fingerprint = (new ReflectionMethod($widget, 'fixFingerprint'))->invoke($widget, $automatic->reports[1]);
        (new ReflectionProperty($widget, 'appliedFix'))->setValue($widget, $fingerprint);
        uiExpect(uiFixOffer($widget) === null, 'The fix button reappeared for a report that was already repaired.');
        $automatic->reports[1]['generated_at'] = '2026-10-14T23:10:00+00:00';
        $widget->refreshAutomaticDiagnosis();
        uiExpect(uiFixOffer($widget) === ['type' => 'eula'], 'A newer report must offer its fix again.');
        uiExpect((new ReflectionProperty($widget, 'appliedFix'))->getAttributes(Locked::class) !== [], 'Applied fix state must be locked against browser edits.');
    };

    $tests['egg tools are hidden unless the advisor allows this admin and polling never changes eggs'] = static function (): void {
        [$widget] = uiFixture();
        $advisor = $GLOBALS['ui_services'][EggAdvisorService::class];
        $eggTools = new ReflectionMethod($widget, 'eggTools');
        uiExpect($eggTools->invoke($widget) === null && $advisor->calls === ['available'], 'Egg tools showed for a user the advisor did not allow.');
        $advisor->available = true;
        $advisor->lastChange = ['applied_at' => '2026-10-14T23:00:00+00:00', 'fields' => ['script_install'], 'undoable' => true];
        uiExpect($eggTools->invoke($widget) === ['last_change' => $advisor->lastChange], 'Egg tools must show the last undoable change.');
        $advisor->failure = new RuntimeException('secret');
        $advisor->available = false;
        for ($i = 0; $i < 3; $i++) { $widget->refreshAutomaticDiagnosis(); $eggTools->invoke($widget); }
        uiExpect(array_intersect(['propose', 'apply', 'undo'], $advisor->calls) === [], 'Rendering or polling asked the AI or changed an egg.');
    };

    $tests['checking an egg stores only the review and reports failures without details'] = static function (): void {
        [$widget] = uiFixture();
        $advisor = $GLOBALS['ui_services'][EggAdvisorService::class];
        $widget->checkEgg();
        uiExpect($widget->eggProposal['id'] === 'proposal-1' && !in_array('apply', $advisor->calls, true), 'Checking the egg must only store a proposal.');
        uiExpect((new ReflectionProperty($widget, 'eggProposal'))->getAttributes(Locked::class) !== [], 'The egg proposal must be locked against browser edits.');
        $widget->dismissEggProposal();
        uiExpect($widget->eggProposal === null, 'Dismiss must clear the proposal.');
        $advisor->failure = new RuntimeException('secret-provider-body');
        $widget->checkEgg();
        uiExpect($widget->eggProposal === null && end(Notification::$sent)['status'] === 'danger', 'A failed check must show an error.');
        uiExpect(!str_contains(json_encode(Notification::$sent), 'secret-provider-body'), 'Exception details must not reach the browser.');
        $advisor->failure = \Illuminate\Validation\ValidationException::withMessages(['egg' => 'Please wait before asking the AI about this egg again.']);
        $widget->checkEgg();
        uiExpect(end(Notification::$sent)['body'] === 'Please wait before asking the AI about this egg again.', 'Validation messages must be shown to the admin.');
    };

    $failed = 0;
    foreach ($tests as $name => $test) {
        try {
            $test();
            fwrite(STDOUT, 'PASS ' . $name . PHP_EOL);
        } catch (Throwable $error) {
            $failed++;
            fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL);
        }
    }
    fwrite(STDOUT, count($tests) . ' automatic UI scenarios, ' . $failed . ' failures.' . PHP_EOL);
    exit($failed > 0 ? 1 : 0);
}
