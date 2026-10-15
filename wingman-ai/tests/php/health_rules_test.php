<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Support/SecretRedactor.php';
require_once dirname(__DIR__, 2) . '/src/Support/RightSizeRules.php';
require_once dirname(__DIR__, 2) . '/src/Support/ConfigEdits.php';

use Pelican\AiHelpAssistant\Support\ConfigEdits;
use Pelican\AiHelpAssistant\Support\RightSizeRules;

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
$refuses = static function (callable $operation, string $contains) use ($check): void {
    try {
        $operation();
    } catch (InvalidArgumentException $exception) {
        $check(str_contains($exception->getMessage(), $contains), 'Wrong refusal: ' . $exception->getMessage());

        return;
    }
    $check(false, 'Expected a refusal containing: ' . $contains);
};
$mb = 1048576;
$hours = static function (int $count, callable $sample, string $state = 'running') use ($mb): array {
    $buckets = [];
    for ($hour = 0; $hour < $count; $hour++) {
        $buckets = RightSizeRules::record($buckets, 1792000000 + $hour * 3600, $state, $sample($hour));
    }

    return $buckets;
};

// Recording: hourly buckets, running-only stats, and a 7-day window.
$buckets = RightSizeRules::record([], 1791997200, 'running', ['memory_bytes' => 500 * $mb, 'cpu_absolute' => 40, 'disk_bytes' => 100 * $mb]);
$buckets = RightSizeRules::record($buckets, 1791997800, 'running', ['memory_bytes' => 700 * $mb, 'cpu_absolute' => 80, 'disk_bytes' => 110 * $mb]);
$buckets = RightSizeRules::record($buckets, 1791998400, 'offline', ['memory_bytes' => 0, 'cpu_absolute' => 0, 'disk_bytes' => 120 * $mb]);
$bucket = reset($buckets);
$check(count($buckets) === 1 && $bucket['mem_max'] === 700 * $mb && $bucket['cpu_max'] === 80.0 && $bucket['cpu_n'] === 2 && $bucket['running'] === 2 && $bucket['samples'] === 3, 'Samples in one hour must merge, counting only running samples for usage.');
$check($bucket['disk_first'] === 100 * $mb && $bucket['disk_last'] === 120 * $mb, 'Disk use must track the first and last sample of the hour.');
$check(count($hours(200, fn () => ['memory_bytes' => 1, 'cpu_absolute' => 1, 'disk_bytes' => 1])) === RightSizeRules::KEEP_HOURS, 'Only the last 7 days of hours may be kept.');

$limits = ['memory_mb' => 4096, 'cpu_percent' => 200, 'disk_mb' => 0];
$learning = RightSizeRules::analyze($hours(10, fn () => ['memory_bytes' => 900 * $mb, 'cpu_absolute' => 30, 'disk_bytes' => 1]), $limits);
$check(str_contains($learning['suggestions'][0], '10 of 24 running hours') && count($learning['suggestions']) === 1, 'Under a day of data, Wingman must say it is still learning.');
$check(RightSizeRules::analyze($hours(30, fn () => ['memory_bytes' => 0, 'cpu_absolute' => 0, 'disk_bytes' => 1], 'offline'), $limits)['running_hours'] === 0, 'Offline hours are not running hours.');

$tight = RightSizeRules::analyze($hours(30, fn ($hour) => ['memory_bytes' => 3900 * $mb, 'cpu_absolute' => $hour < 4 ? 199 : 50, 'disk_bytes' => 1]), $limits);
$check(str_contains($tight['suggestions'][0], 'Raise the limit to about 5 GB'), 'Memory near the limit must suggest raising it.');
$check(str_contains($tight['suggestions'][1], 'CPU hit its 200% limit in 4 of the last 30'), 'Repeated CPU capping must be reported.');

$roomy = RightSizeRules::analyze($hours(80, fn () => ['memory_bytes' => 900 * $mb, 'cpu_absolute' => 30, 'disk_bytes' => 1]), $limits);
$check(str_contains($roomy['suggestions'][0], 'About 1.5 GB would be enough') && $roomy['memory_peak_mb'] === 900 && $roomy['cpu_average'] === 30, 'A mostly unused memory limit may be lowered after 3 days.');
$check(RightSizeRules::analyze($hours(30, fn () => ['memory_bytes' => 900 * $mb, 'cpu_absolute' => 30, 'disk_bytes' => 1]), $limits)['suggestions'] === ['The limits fit how this server is used. Nothing to change.'], 'Lowering needs 3 days of data; otherwise nothing to change.');
$unlimited = RightSizeRules::analyze($hours(30, fn () => ['memory_bytes' => 2000 * $mb, 'cpu_absolute' => 30, 'disk_bytes' => 1]), ['memory_mb' => 0, 'cpu_percent' => 0, 'disk_mb' => 0]);
$check(str_contains($unlimited['suggestions'][0], 'no memory limit') && str_contains($unlimited['suggestions'][0], 'about 3 GB'), 'An unlimited server must get a protective limit suggestion.');

$full = RightSizeRules::analyze($hours(30, fn () => ['memory_bytes' => 100 * $mb, 'cpu_absolute' => 5, 'disk_bytes' => 9500 * $mb]), ['memory_mb' => 0, 'cpu_percent' => 0, 'disk_mb' => 10000]);
$check((bool) array_filter($full['suggestions'], fn ($line) => str_contains($line, 'The disk is 95% full')), 'A nearly full disk must be reported.');
$growing = RightSizeRules::analyze($hours(49, fn ($hour) => ['memory_bytes' => 100 * $mb, 'cpu_absolute' => 5, 'disk_bytes' => (8000 + $hour * 20) * $mb]), ['memory_mb' => 0, 'cpu_percent' => 0, 'disk_mb' => 10000]);
$check($growing['disk_growth_mb_per_day'] === 480 && (bool) array_filter($growing['suggestions'], fn ($line) => str_contains($line, 'full in about 2 days')), 'Fast disk growth must predict when it is full.');

// Config edits.
$config = "motd=Hello\nmax-players=4\nrcon.password=hunter22\nwhite-list=false\nserver-port=25565\n";
$masked = ConfigEdits::mask($config);
$check($masked === "motd=Hello\nmax-players=4\nrcon.password=[REDACTED]\nwhite-list=false\nserver-port=25565\n", 'Password values must be masked, keeping line count and key names.');
$check(ConfigEdits::mask("{\n  \"api_key\": \"abc123\",\n  \"name\": \"x\"\n}") === "{\n  \"api_key\": [REDACTED],\n  \"name\": \"x\"\n}", 'JSON secrets must be masked.');
$check(ConfigEdits::mask("hostname My Server owned by hunter22\n", ['hunter22']) === "hostname My Server owned by [REDACTED]\n", 'Known secret values must be masked anywhere.');
$check(ConfigEdits::mask("rcon_password \"\"\n") === "rcon_password \"\"\n", 'An empty secret has nothing to hide.');

$managed = ConfigEdits::managedKeys('{"server.properties":{"parser":"properties","find":{"server-port":"{{server.build.default.port}}","query.port":"x"}}}', './server.properties');
$check($managed === ['server-port', 'port'], 'Managed keys must come from the matching egg config file, by last path segment.');
$check(ConfigEdits::managedKeys(['other.cfg' => ['find' => ['a' => 'b']]], 'server.properties') === [] && ConfigEdits::managedKeys(null, 'x') === [], 'Other or missing config files manage nothing.');

$updated = ConfigEdits::apply($config, [['find' => 'max-players=4', 'replace' => 'max-players=8'], ['find' => 'white-list=false', 'replace' => 'white-list=true']], ['enforce-whitelist=true'], $managed);
$check($updated === "motd=Hello\nmax-players=8\nrcon.password=hunter22\nwhite-list=true\nserver-port=25565\nenforce-whitelist=true\n", 'Edits must change exact lines and append before the final newline, keeping the real password.');
$check(ConfigEdits::apply("a=1\r\nb=2\r\n", [['find' => 'b=2', 'replace' => 'b=3']], [], []) === "a=1\nb=3\n", 'Windows line endings must be handled.');

$refuses(fn () => ConfigEdits::apply($config, [['find' => 'rcon.password=[REDACTED]', 'replace' => 'rcon.password=new']], [], $managed), 'hidden value');
$refuses(fn () => ConfigEdits::apply($config, [['find' => 'server-port=25565', 'replace' => 'server-port=25566']], [], $managed), '"server-port" is set by Pelican');
$refuses(fn () => ConfigEdits::apply($config, [], ['server-port=1'], $managed), '"server-port" is set by Pelican');
$refuses(fn () => ConfigEdits::apply($config, [['find' => 'missing=1', 'replace' => 'x']], [], $managed), 'not found exactly once');
$refuses(fn () => ConfigEdits::apply("a=1\na=1\n", [['find' => 'a=1', 'replace' => 'a=2']], [], []), 'not found exactly once');
$refuses(fn () => ConfigEdits::apply($config, [['find' => 'motd=Hello', 'replace' => 'a'], ['find' => 'motd=Hello', 'replace' => 'b']], [], []), 'same line twice');
$refuses(fn () => ConfigEdits::apply($config, [['find' => 'motd=Hello', 'replace' => "a\nb=c"]], [], []), 'cannot write');
$refuses(fn () => ConfigEdits::apply($config, [['find' => 'motd=Hello', 'replace' => 'motd=[REDACTED]']], [], []), 'cannot write');
$refuses(fn () => ConfigEdits::apply($config, [], [], []), 'no changes');
$refuses(fn () => ConfigEdits::apply($config, array_fill(0, 21, ['find' => 'motd=Hello', 'replace' => 'x']), [], []), 'too many');
$refuses(fn () => ConfigEdits::apply($config, [['find' => 'motd=Hello']], [], []), 'expected format');
$refuses(fn () => ConfigEdits::apply("hostname owned by hunter22\n", [['find' => 'hostname owned by [REDACTED]', 'replace' => 'hostname x']], [], [], ['hunter22']), 'hidden value');

echo 'Health rules: ' . $checks . " checks passed.\n";
