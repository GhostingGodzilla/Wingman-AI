<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Support/SecretRedactor.php';
require_once dirname(__DIR__, 2) . '/src/Support/ReportNormalizer.php';
require_once dirname(__DIR__, 2) . '/src/Support/RuleDiagnostics.php';

use Pelican\AiHelpAssistant\Support\ReportNormalizer;
use Pelican\AiHelpAssistant\Support\RuleDiagnostics;
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
$absent = static function (string $text, array $secrets) use ($check): void {
    foreach ($secrets as $secret) {
        $check(!str_contains($text, $secret), 'A credential survived redaction: ' . $secret);
    }
};

$redacted = SecretRedactor::redact("\e[31mError:\e[0m Unable to access jarfile /home/container/server.jar\x00\x07\nJava 21.0.4\tready");
$check($redacted === "Error: Unable to access jarfile /home/container/server.jar\nJava 21.0.4\tready", 'Redaction must preserve useful paths and versions while stripping terminal controls.');
$redacted = SecretRedactor::redact("literal=abcdefgh token=abcdef\nabc", ['abc', 'abcdefgh', '']);
$absent($redacted, ['abcdefgh', 'abcdef', 'abc']);
$check(!str_contains($redacted, '[REDACTED]defgh'), 'Known secrets must be masked longest-first.');

$redacted = SecretRedactor::redact(<<<'LOG'
DB_PASSWORD=database-secret
{"api_key":"json-secret", "port":25565}
rcon.password: "a quoted secret with spaces"
license-key='licensed secret'
Authorization: Bearer bearer-secret.123
--password "cli secret" --port 25565
postgresql://dbuser:dbpass@db.example:5432/game?sslmode=require&token=query-secret&port=5432
LOG);
$absent($redacted, ['database-secret', 'json-secret', 'a quoted secret with spaces', 'licensed secret', 'bearer-secret.123', 'cli secret', 'dbuser', 'dbpass', 'query-secret']);
$check(str_contains($redacted, '/game?sslmode=require&token=[REDACTED]&port=5432'), 'URL redaction should retain safe connection context.');
$check(str_contains($redacted, '--port 25565'), 'Safe CLI flags must be retained.');

$redacted = SecretRedactor::redact("password=\"truncated secret words\nError: missing server.jar\n-----BEGIN PRIVATE KEY-----\nprivate-payload\n-----END PRIVATE KEY-----\nJava 17\n-----BEGIN OPENSSH PRIVATE KEY-----\ntruncated-key");
$absent($redacted, ['truncated secret words', 'private-payload', 'truncated-key']);
$check(str_contains($redacted, 'Error: missing server.jar') && str_contains($redacted, 'Java 17'), 'PEM and unterminated-secret masking must not consume unrelated completed lines.');
$redacted = SecretRedactor::redact("API_\e[31mKEY\e[0m=ansi-secret");
$absent($redacted, ['ansi-secret']);
$redacted = SecretRedactor::redact('key sk-proj-abcdefghijklmnopqrstuvwxyz123456 and ghp_abcdefghijklmnopqrstuvwxyz123456');
$absent($redacted, ['sk-proj-abcdefghijklmnopqrstuvwxyz123456', 'ghp_abcdefghijklmnopqrstuvwxyz123456']);
$redacted = SecretRedactor::redact('https://example.test/game?api%5Fkey=encoded-secret&name=safe' . "\nAPI key: spaced-label-secret");
$absent($redacted, ['encoded-secret', 'spaced-label-secret']);
$check(str_contains($redacted, '&name=safe'), 'Encoded URL parameter names must be inspected without losing safe parameters.');

$diagnose = static fn (string $logs, array $extra = []): array => RuleDiagnostics::diagnose(array_replace(['logs' => $logs, 'outcome' => 'failed', 'observed_state' => 'offline', 'observations' => ['capture_mode' => 'startup']], $extra));
$cases = [
    ['Error: Unable to access jarfile paper.jar', 'Java application'],
    ['/bin/sh: python: not found', 'executable'],
    ['./entrypoint.sh: line 22: ./server: No such file or directory', 'executable'],
    ["/usr/bin/env: 'python': No such file or directory", 'executable'],
    ['java.lang.UnsupportedClassVersionError: Main has been compiled by a more recent version of the Java Runtime (class file version 65.0), this version recognizes up to 61.0', 'Java runtime'],
    ['Error: listen EADDRINUSE: address already in use 0.0.0.0:25565', 'address or port'],
    ['open /home/container/world: Permission denied', 'permissions'],
    ['java.lang.OutOfMemoryError: Java heap space', 'memory'],
    ['write failed: No space left on device', 'storage'],
    ["ModuleNotFoundError: No module named 'uvloop'", 'dependency'],
    ['Missing or unsupported mandatory dependencies:', 'dependency'],
    ['You need to agree to the EULA in order to run the server.', 'EULA'],
    ['JSONDecodeError: Expecting property name enclosed in double quotes: line 3 column 2', 'configuration'],
    ['error while loading shared libraries: libsteam_api.so: cannot open shared object file', 'runtime library'],
    ['OCI runtime create failed: failed to create container process', 'container startup'],
];
foreach ($cases as [$logs, $expected]) {
    $report = $diagnose($logs);
    $check(str_contains(strtolower($report['summary']), strtolower($expected)), 'Expected diagnosis category: ' . $expected . '; got ' . $report['summary']);
    $check($report['source'] === 'rules' && $report['evidence'] !== [] && $report['steps'] !== [], 'A known rule must include provenance, evidence, and next steps.');
}
$report = $diagnose('java.lang.UnsupportedClassVersionError: Main (class file version 65.0), this version only recognizes class file versions up to 61.0');
$check(str_contains(implode(' ', $report['steps']), 'Java 21 or newer'), 'A Java class version must identify the minimum compatible runtime.');

$report = $diagnose('Process exited with code: 137');
$check($report['confidence'] === 'low' && str_contains($report['likely_cause'], 'does not prove'), 'Exit 137 alone must not be diagnosed as proven OOM.');
$report = $diagnose('oom_killed: false; Process exited with code: 137');
$check($report['confidence'] === 'low', 'A false OOM flag must not become affirmative memory evidence.');
$report = $diagnose("[Pelican Daemon]: Exit code: 137\n[Pelican Daemon]: Out of memory: false");
$check($report['confidence'] === 'low', 'Common daemon Out of memory: false output must not become a proven memory failure.');
$report = $diagnose('Process exited with code: 1');
$check($report['confidence'] === 'low' && $report['needs_more_information'] !== [], 'Generic failure must remain uncertain.');
$report = $diagnose('old: Unable to access jarfile old.jar', ['outcome' => 'running', 'observed_state' => 'running']);
$check(str_contains($report['summary'], 'currently running') && !str_contains($report['summary'], 'cannot be loaded'), 'Current running outcome must override historical fatal signatures.');
$report = $diagnose('old: Unable to access jarfile old.jar', ['outcome' => 'observed', 'observed_state' => 'offline', 'observations' => ['capture_mode' => 'existing']]);
$check(str_contains($report['likely_cause'], 'historical') && str_contains($report['evidence'][0], 'historical'), 'Existing logs must be explicitly labeled historical.');
$report = $diagnose('old: Unable to access jarfile old.jar', ['observations' => ['capture_mode' => 'automatic', 'logs_may_include_previous_run' => true]]);
$check(str_contains($report['summary'], 'failure was observed') && str_contains($report['likely_cause'], 'previous run') && str_contains($report['evidence'][0], 'historical'), 'An automatically observed failure may be confirmed while a retained older log cause remains uncertain.');
$report = $diagnose('old: Unable to access jarfile old.jar', ['outcome' => 'timeout', 'observed_state' => 'starting', 'observations' => ['capture_mode' => 'automatic', 'logs_may_include_previous_run' => true]]);
$check($report['confidence'] === 'low' && str_contains($report['summary'], 'unconfirmed') && str_contains($report['evidence'][0], 'historical'), 'Automatic timeout with retained old logs must keep both outcome and cause uncertain.');
$report = $diagnose('old: Unable to access jarfile old.jar', ['outcome' => 'connection_error', 'observed_state' => 'running']);
$check($report['confidence'] === 'low' && str_contains($report['likely_cause'], 'does not establish an application crash'), 'A failed console connection must not become a game crash diagnosis.');
$report = $diagnose('Unable to access jarfile server.jar', ['outcome' => 'timeout', 'observed_state' => 'starting']);
$check($report['confidence'] === 'low' && str_contains($report['summary'], 'unconfirmed'), 'Timeout with an error signature must preserve outcome uncertainty.');
$report = $diagnose('still loading resources', ['outcome' => 'timeout', 'observed_state' => 'starting']);
$check($report['confidence'] === 'low' && str_contains($report['likely_cause'], 'timeout alone'), 'A timeout alone must not prove a crash.');
$report = $diagnose('old: Unable to access jarfile old.jar', ['observations' => ['capture_mode' => 'startup', 'oom_killed' => true]]);
$check(str_contains($report['summary'], 'killed for exceeding memory'), 'Current runtime OOM flag should override old log errors.');
$report = RuleDiagnostics::diagnose(['logs' => "Unable to access jarfile old.jar\nDone (3.00s)! For help, type help", 'observations' => ['capture_mode' => 'startup']]);
$check(str_contains($report['summary'], 'successful startup'), 'Later readiness output must override older errors when no failed state is observed.');
$report = RuleDiagnostics::diagnose(['logs' => 'Done (3.00s)! For help, type help', 'outcome' => 'failed', 'observations' => ['capture_mode' => 'startup']]);
$check($report['confidence'] === 'low' && !str_contains($report['summary'], 'successful'), 'An explicit failed outcome must not be overturned by an older readiness line.');
$report = $diagnose("Unable to access jarfile old.jar\nwrite failed: ENOSPC");
$check(str_contains($report['summary'], 'storage'), 'The most recent relevant error must take priority.');
$report = $diagnose('EACCES opening password=hunter2 file /home/container/server.cfg');
$check(!str_contains(implode(' ', $report['evidence']), 'hunter2'), 'Rule evidence must be redacted.');
$report = RuleDiagnostics::diagnose(['logs' => []]);
$check($report['confidence'] === 'low', 'Empty logs must remain inconclusive.');

$valid = ['summary' => 'Startup failed', 'likely_cause' => 'Missing executable', 'confidence' => 'HIGH', 'evidence' => ['command not found'], 'steps' => ['Check runtime'], 'needs_more_information' => [], 'source' => 'attacker'];
$normalized = ReportNormalizer::normalize("```json\n" . json_encode($valid, JSON_THROW_ON_ERROR) . "\n```");
$check($normalized['confidence'] === 'high' && !array_key_exists('source', $normalized), 'Structured normalization must constrain confidence and discard untrusted provenance.');
$check(ReportNormalizer::normalize(['summary' => 'x', 'likely_cause' => 'y', 'confidence' => 'certain'])['confidence'] === 'low', 'Unsupported confidence must downgrade to low.');
$normalized = ReportNormalizer::normalize(['summary' => 'x'], ['likely_cause' => 'fallback', 'steps' => ['collect fresh logs']]);
$check($normalized['likely_cause'] === 'fallback' && $normalized['steps'] === ['collect fresh logs'], 'Missing report fields may use validated rule fallback.');
$normalized = ReportNormalizer::normalize(array_replace($valid, ['summary' => str_repeat('é', 700), 'evidence' => array_fill(0, 20, 'same'), 'steps' => array_map(static fn (int $number): string => 'Step ' . $number, range(1, 20))]));
$check(preg_match('//u', $normalized['summary']) === 1 && (function_exists('mb_strlen') ? mb_strlen($normalized['summary'], 'UTF-8') : preg_match_all('/./u', $normalized['summary'])) === 500, 'Text bounds must preserve valid UTF-8.');
$check(count($normalized['evidence']) === 1 && count($normalized['steps']) === 8, 'List fields must be deduplicated and bounded.');
$normalized = ReportNormalizer::normalize(array_replace($valid, ['evidence' => ['password=must-not-leak']]));
$check(!str_contains($normalized['evidence'][0], 'must-not-leak'), 'AI output should receive defensive redaction.');
$knownSecret = 'known-literal-secret-value';
$normalized = ReportNormalizer::normalize(array_replace($valid, ['summary' => str_repeat('x', 490) . $knownSecret]), [], [$knownSecret]);
$check(!str_contains($normalized['summary'], 'known-lite'), 'Known credentials must be redacted before length bounds can cut a secret into an unmatchable fragment.');

$malformed = [
    'not json secret-value',
    '[]',
    '{}',
    ['summary' => 'ok', 'likely_cause' => ['not a string']],
    array_replace($valid, ['evidence' => [123]]),
    array_replace($valid, ['evidence' => null]),
    array_replace($valid, ['summary' => "\e[31m\e[0m"]),
    array_replace($valid, ['steps' => ['nested' => 'object']]),
    array_replace($valid, ['needs_more_information' => [['nested']]]),
];
foreach ($malformed as $raw) {
    $thrown = false;
    try {
        ReportNormalizer::normalize($raw);
    } catch (InvalidArgumentException $exception) {
        $thrown = true;
        $check($exception->getMessage() === 'The AI response was not a valid diagnosis report.', 'Malformed output errors must not leak response content.');
    }
    $check($thrown, 'Malformed or non-string report fields must fail safely.');
}

echo 'Helpers: ' . $checks . " checks passed.\n";
