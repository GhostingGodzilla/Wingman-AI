<?php
// Compiles the widget template with a Pelican install's Blade compiler and RUNS it with fake
// widget state, so undefined variables and runtime errors fail here, not on a live panel.
// Usage: php wingman-ai/tools/render-check.php /var/www/pelican
$pelican = rtrim($argv[1] ?? '/var/www/pelican', '/');
require $pelican . '/vendor/autoload.php';
$src = dirname(__DIR__) . '/src';
$template = dirname(__DIR__) . '/resources/views/widgets/wingman-ai.blade.php';
$cacheDir = sys_get_temp_dir() . '/wingman-render-' . bin2hex(random_bytes(4));
mkdir($cacheDir, 0700);
register_shutdown_function(static function () use ($cacheDir): void {
    array_map('unlink', glob($cacheDir . '/*') ?: []);
    @rmdir($cacheDir);
});
spl_autoload_register(function (string $class) use ($src): void {
    $prefix = 'Pelican\\AiHelpAssistant\\';
    if (str_starts_with($class, $prefix)) {
        $file = $src . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$compiler = new Illuminate\View\Compilers\BladeCompiler(new Illuminate\Filesystem\Filesystem(), $cacheDir);
$compiler->withoutComponentTags();
$compiled = $compiler->compileString(file_get_contents($template));
if (preg_match('/<\?php\(/', $compiled)) {
    fwrite(STDERR, "FAIL: compiled template contains '<?php(' which PHP prints as text\n");
    exit(1);
}
file_put_contents($cacheDir . '/render.php', $compiled);

final class FakeEnv
{
    public function addLoop(mixed $data): void {}
    public function incrementLoopIndices(): void {}
    public function popLoop(): void {}
    public function getLastLoop(): ?object { return null; }
}

final class FakeWidget
{
    public function __construct(public array $state) {}
    public function __get(string $name): string { return '[action ' . $name . ']'; }
    protected function automaticStatusText(): string { return 'status text'; }
    protected function automaticReportTime(): string { return 'Oct 15, 2026 01:00 UTC'; }
    protected function reinstallStatus(): array { return $this->state['reinstallStatus']; }
    protected function isAiConfigured(): bool { return true; }
    protected function fixOffer(): ?array { return $this->state['fixOffer']; }
    protected function fixSummary(array $offer): string { return 'fix summary'; }
    protected function canAsk(): bool { return $this->state['canAsk']; }
    protected function eggTools(): ?array { return $this->state['eggTools']; }
    protected function serverHealth(): array { return $this->state['health'] ?? ['backups' => null, 'day' => null, 'usage' => null]; }
    protected function canChangeSettings(): bool { return $this->state['canChange'] ?? false; }
    public function render(string $file, array $vars): string
    {
        extract($vars);
        $__env = new FakeEnv();
        ob_start();
        try {
            include $file;
        } finally {
            $out = ob_get_clean();
        }

        return $out;
    }
}

$report = ['summary' => 'S', 'likely_cause' => 'C', 'confidence' => 'high', 'evidence' => ['e'], 'steps' => ['s'], 'needs_more_information' => ['n'], 'source' => 'ai', 'notice' => 'N', 'generated_at' => '2026-10-15T01:00:00+00:00'];
$scenarios = [
    'nothing to show' => [
        'vars' => ['report' => null, 'autoReport' => null, 'autoStatus' => [], 'eggProposal' => null, 'answer' => null, 'configProposal' => null],
        'state' => ['reinstallStatus' => ['pending' => null, 'notice' => null], 'fixOffer' => null, 'canAsk' => false, 'eggTools' => null],
        'expect' => [],
    ],
    'everything shown' => [
        'vars' => ['report' => null, 'autoReport' => $report + ['kind' => 'crash'], 'autoStatus' => ['status' => 'failed'], 'answer' => ['question' => 'Q?', 'answer' => 'A.'],
            'configProposal' => ['id' => 'c', 'file' => 'server.properties', 'summary' => 'Turn on the whitelist.', 'notes' => 'Restart needed.', 'restart' => true, 'diff' => [['-', 'white-list=false'], ['+', 'white-list=true']]],
            'eggProposal' => ['id' => 'x', 'summary' => 'Egg', 'reason' => 'R', 'reinstall' => true, 'warnings' => ['W'], 'egg_name' => 'E', 'servers_using' => 1, 'copies_script' => true,
                'changes' => [['label' => 'Install script', 'diff' => [[' ', 'a'], ['-', 'b'], ['+', 'c'], ['…', '3 unchanged lines']]], ['label' => 'Startup command', 'before' => 'x', 'after' => 'y']]]],
        'state' => ['reinstallStatus' => ['pending' => ['backup' => 'u', 'since' => 't'], 'notice' => null], 'fixOffer' => ['type' => 'reinstall'], 'canAsk' => true, 'canChange' => true,
            'health' => ['backups' => ['limit' => 0, 'count' => 0, 'last' => null, 'scheduled' => false, 'issues' => ['This server cannot keep any backups: its backup limit is 0.'], 'can_backup' => false, 'can_schedule' => false],
                'day' => ['lines' => ['2 crashes', '3 starts or restarts'], 'quiet' => false],
                'usage' => ['running_hours' => 30, 'memory_peak_mb' => 900, 'cpu_peak' => 80, 'cpu_average' => 20, 'disk_mb' => 1500, 'disk_growth_mb_per_day' => 5, 'suggestions' => ['This server has no memory limit.']]],
            'eggTools' => ['last_change' => ['applied_at' => '2026-10-15T01:00:00+00:00', 'fields' => ['script_install'], 'undoable' => true]]],
        'expect' => ['Crash while running', 'Wingman can fix this', '[action applyFixAction]', 'Backing up before reinstalling', 'Ask Wingman', 'You asked: Q?', 'Egg check', '[action applyEggFixAction]', '[action undoEggFixAction]', 'Check these carefully',
            'Server health', '1 backup warning', 'backup limit is 0', 'Last 24 hours', '2 crashes · 3 starts or restarts.', 'Usage and limits', 'memory peaked at 900 MB', 'Change a game setting', 'File: <span class="font-mono">server.properties', '+ white-list=true', '[action applyConfigAction]'],
    ],
    'manual performance report and notice' => [
        'vars' => ['report' => $report + ['kind' => 'performance'], 'autoReport' => $report + ['kind' => 'install'], 'autoStatus' => ['status' => 'idle'], 'eggProposal' => ['id' => 'x', 'summary' => 'No problem', 'reason' => '', 'reinstall' => false, 'warnings' => [], 'egg_name' => 'E', 'servers_using' => 2, 'copies_script' => false, 'changes' => []], 'answer' => null,
            'configProposal' => ['id' => null, 'file' => 'server.cfg', 'summary' => 'No change', 'notes' => 'The file does not need a change for this.', 'restart' => false, 'diff' => []]],
        'state' => ['reinstallStatus' => ['pending' => null, 'notice' => ['message' => 'The backup failed, so Wingman did not reinstall the server.', 'at' => 't']], 'fixOffer' => null, 'canAsk' => true,
            'eggTools' => ['last_change' => ['applied_at' => '2026-10-15T01:00:00+00:00', 'fields' => ['startup'], 'undoable' => false]], 'canChange' => true,
            'health' => ['backups' => ['limit' => 3, 'count' => 2, 'last' => '2026-10-14T04:07:00+00:00', 'scheduled' => false, 'issues' => ['No automatic backups are scheduled.'], 'can_backup' => true, 'can_schedule' => true],
                'day' => ['lines' => [], 'quiet' => true], 'usage' => null]],
        'expect' => ['Performance check', 'The backup failed', 'did not find a problem in the egg', 'cannot be undone automatically', 'Nothing happened', '2 of 3 backups kept', 'Newest:', 'Back up now', 'Back up every night', 'does not need a change'],
    ],
];
$failed = 0;
foreach ($scenarios as $name => $scenario) {
    try {
        $html = (new FakeWidget($scenario['state']))->render($cacheDir . '/render.php', $scenario['vars']);
        foreach ($scenario['expect'] as $text) {
            if (!str_contains($html, $text)) {
                throw new RuntimeException('missing "' . $text . '"');
            }
        }
        if (str_contains($html, '$displayReport') || str_contains($html, '<?php')) {
            throw new RuntimeException('raw PHP leaked into the page');
        }
        echo "PASS render: $name\n";
    } catch (Throwable $exception) {
        $failed++;
        echo "FAIL render: $name: " . $exception->getMessage() . "\n";
    }
}
exit($failed === 0 ? 0 : 1);
