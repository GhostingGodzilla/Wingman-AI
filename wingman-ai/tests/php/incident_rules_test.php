<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Support/SecretRedactor.php';
require_once dirname(__DIR__, 2) . '/src/Support/InstallLogChecker.php';
require_once dirname(__DIR__, 2) . '/src/Support/PerformanceRules.php';

use Pelican\AiHelpAssistant\Support\InstallLogChecker;
use Pelican\AiHelpAssistant\Support\PerformanceRules;

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

// Install logs: the Risk of Rain 2 case that Pelican marked as installed.
$steam = "steam user is not set.\nUsing anonymous user.\n[  0%] Checking for available updates...\nERROR! Failed to install app '1180760' (Missing configuration)\n"
    . "cp: cannot stat '/mnt/server/Risk of Rain 2_Data/Config/server_startup.cfg': No such file or directory\n-----------------------------------------\nInstallation completed...\n";
$report = InstallLogChecker::check($steam, true);
$check($report['summary'] === 'The install finished with errors: SteamCMD could not download the game.', 'A SteamCMD failure behind a "successful" install must be reported.');
$check(str_contains($report['likely_cause'], 'marked the server as installed anyway'), 'The report must explain why Pelican still said installed.');
$check(str_contains($report['steps'][0], 'Missing configuration') && $report['fix'] === ['type' => 'reinstall'], 'The known first-run error must suggest reinstalling.');
$check($report['evidence'] === ["ERROR! Failed to install app '1180760' (Missing configuration)", "cp: cannot stat '/mnt/server/Risk of Rain 2_Data/Config/server_startup.cfg': No such file or directory"], 'Evidence must list the error lines in log order.');
$check($report['source'] === 'rules' && $report['confidence'] === 'high', 'Rule reports must be marked as built-in checks.');

$check(InstallLogChecker::check("Success! App '1180760' fully installed.\nInstallation completed...\n", true) === null, 'A clean, successful install must produce no report.');
$check(InstallLogChecker::check('', true) === null, 'An empty log from a successful install must produce no report.');

$failed = InstallLogChecker::check("Downloading files\nexit status 1\n", false);
$check($failed['summary'] === 'The installation failed.' && $failed['evidence'] === ['Downloading files', 'exit status 1'], 'An unexplained failed install must show the end of the log.');
$check(InstallLogChecker::check('', false)['evidence'] === ['The install log is empty.'], 'A failed install with no log must say so.');

foreach ([
    ["curl: (22) The requested URL returned error: 404\n", 'A download failed during installation.'],
    ["gzip: stdin: not in gzip format\ntar: Child returned status 1\ntar: Error is not recoverable: exiting now\n", 'A downloaded file could not be unpacked.'],
    ["FAILED login with result code Invalid Password\n", 'Logging in to Steam failed.'],
    ["Error! App '1180760' state is 0x202 after update job.\n", 'SteamCMD stopped with an error state.'],
    ["write error: No space left on device\n", 'The disk ran out of space during installation.'],
    ["install.sh: line 4: jq: command not found\n", 'The install container is missing a command the script needs.'],
    ["ERROR: something unexpected\n", 'The install log contains errors.'],
] as [$log, $summary]) {
    $check((InstallLogChecker::check($log, false)['summary'] ?? '') === $summary, 'Unexpected install diagnosis for: ' . trim($log));
}
$check(InstallLogChecker::check("curl: (6) Could not resolve host\ncp: cannot stat 'x': No such file or directory\n", false)['summary'] === 'A download failed during installation.', 'The root cause must outrank its consequences.');
$check(!str_contains(json_encode(InstallLogChecker::check("ERROR: login token=abcdefghijklmnop failed\n", false)), 'abcdefghijklmnop'), 'Install evidence must be redacted.');

// Performance.
$limits = ['memory_mb' => 2048, 'cpu_percent' => 200, 'disk_mb' => 10000, 'startup' => 'java -Xms128M -Xmx2048M -jar server.jar'];
$busy = [['cpu_absolute' => 196, 'memory_bytes' => 1950 * 1048576, 'disk_bytes' => 9500 * 1048576], ['cpu_absolute' => 199, 'memory_bytes' => 1980 * 1048576, 'disk_bytes' => 9500 * 1048576]];
$lagLog = "[12:00:01] [Server thread/WARN]: Can't keep up! Is the server overloaded? Running 5032ms or 100 ticks behind\n[12:01:00] [Server thread/WARN]: Can't keep up! Is the server overloaded? Running 2100ms or 42 ticks behind\n";
$report = PerformanceRules::analyze('running', $busy, $limits, $lagLog);
$check($report['confidence'] === 'high' && $report['source'] === 'rules', 'Hitting limits must be a high-confidence finding.');
$check($report['summary'] === 'Memory is nearly full (and 4 more problems).', 'The summary must name the main problem and count the rest.');
foreach (['Memory is nearly full', 'all of its CPU limit', 'Java is allowed to use', 'disk is nearly full', 'cannot keep up'] as $expected) {
    $check(str_contains($report['likely_cause'], $expected), 'Missing performance finding: ' . $expected);
}
$check(in_array('2 "Can\'t keep up" warnings in recent output; worst: 5,032 ms behind', $report['evidence'], true), 'Lag warnings must be counted with the worst delay.');
$check(count(array_filter($report['evidence'], fn ($line) => str_starts_with($line, 'Console:'))) === 0, 'The Minecraft warning must not be counted twice as a generic lag line.');
$check($report['evidence'][0] === 'CPU: 198% of a 200% limit (100% = one CPU core)' && $report['evidence'][1] === 'Memory: 1.9 GB of 2.0 GB', 'Measurements must be shown plainly.');

$idle = PerformanceRules::analyze('running', [['cpu_absolute' => 12, 'memory_bytes' => 500 * 1048576, 'disk_bytes' => 1048576]], ['memory_mb' => 0, 'cpu_percent' => 0, 'disk_mb' => 0, 'startup' => ''], '');
$check($idle['summary'] === 'No resource limit is being hit and the console shows no lag warnings.' && $idle['needs_more_information'] === ['A check taken during the lag.'], 'A healthy server must say so and ask for a check during lag.');
$check($idle['evidence'][1] === 'Memory: 500 MB (no limit)', 'Unlimited servers must say so.');
$check(PerformanceRules::analyze('offline', [], $limits, '')['summary'] === 'The server is not running, so there is nothing to measure.', 'A stopped server cannot be measured.');
$check(PerformanceRules::analyze('running', [['cpu_absolute' => 5, 'memory_bytes' => 1, 'disk_bytes' => 1]], ['memory_mb' => 0, 'cpu_percent' => 0, 'disk_mb' => 0, 'startup' => ''], "java.lang.OutOfMemoryError: GC overhead limit exceeded\n")['summary'] === 'Java ran out of memory.', 'Java memory exhaustion in the console must be reported.');
$check(str_contains(PerformanceRules::analyze('running', [['cpu_absolute' => 5, 'memory_bytes' => 1, 'disk_bytes' => 1]], ['memory_mb' => 0, 'cpu_percent' => 0, 'disk_mb' => 0, 'startup' => ''], "[Watchdog Thread/ERROR]: The server has stopped responding!\n")['summary'], 'watchdog'), 'A watchdog warning must be reported.');

echo 'Incident rules: ' . $checks . " checks passed.\n";
