<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Support/AutomaticStartupState.php';

use Pelican\AiHelpAssistant\Support\AutomaticStartupState as Startup;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$started = static fn (float $now = 100, string $generation = 'attempt-a'): array => Startup::callback([], 'offline', 'starting', $now, $generation)['state'];

$result = Startup::callback([], 'offline', 'starting', 100, 'a');
$check($result['action'] === 'watch' && $result['state']['generation'] === 'a' && $result['state']['started_at'] === 100.0, 'A starting transition must create a watch generation.');
$state = $result['state'];
$duplicate = Startup::callback($state, 'offline', 'starting', 101, 'candidate-b');
$check($duplicate['action'] === 'ignore' && $duplicate['state'] === $state, 'Duplicate starts must preserve generation and start time.');
$running = Startup::callback($state, 'starting', 'running', 102, 'candidate-c');
$check($running['action'] === 'watch' && $running['state']['generation'] === 'a' && $running['state']['running_since'] === 102.0, 'Running callbacks must keep the generation and begin the quiet timer.');
$duplicate = Startup::callback($running['state'], 'starting', 'running', 103, 'candidate-d');
$check($duplicate['action'] === 'ignore' && $duplicate['state']['running_since'] === 102.0, 'Duplicate running must not reset the quiet timer.');
$result = Startup::observe($running['state'], 'running', 121, 120, 20, 'a');
$check($result['action'] === 'watch' && $result['state']['terminal'] === null, 'Running must finish the quiet period.');
$result = Startup::observe($result['state'], 'running', 122, 120, 20, 'a');
$check($result['action'] === 'healthy' && $result['state']['terminal'] === 'healthy', 'Stable running becomes healthy after twenty seconds.');
$terminal = $result['state'];
$result = Startup::callback($terminal, 'running', 'offline', 130, 'candidate-e');
$check($result['action'] === 'ignore' && $result['state'] === $terminal, 'Stop after healthy startup must not trigger diagnosis.');
$result = Startup::callback($terminal, 'offline', 'starting', 140, 'new-attempt');
$check($result['action'] === 'watch' && $result['state']['generation'] === 'new-attempt' && $result['state']['terminal'] === null, 'A new start after terminal health gets a new generation.');
$result = Startup::callback($terminal, 'starting', 'starting', 140, 'delayed-duplicate');
$check($result['action'] === 'ignore', 'Delayed starting-to-starting must not revive a terminal attempt.');

$result = Startup::callback($started(), 'starting', 'offline', 101, 'ignored-candidate');
$check($result['action'] === 'watch' && $result['state']['attempt_candidate'], 'Fast offline must wait for fresh confirmation.');
$result = Startup::observe($result['state'], 'offline', 102, 120, 20, 'attempt-a');
$check($result['action'] === 'failed' && $result['state']['terminal'] === 'failed', 'Confirmed starting-to-offline is a failure.');
$result = Startup::observe($result['state'], 'offline', 103, 120, 20, 'attempt-a');
$check($result['action'] === 'ignore', 'Terminal failure must not duplicate diagnosis.');

$result = Startup::callback([], 'starting', 'offline', 10, 'bootstrapped');
$check($result['action'] === 'watch' && $result['state']['attempt_candidate'] && $result['state']['generation'] === 'bootstrapped', 'Missed starting callback must allow rapid-failure bootstrap.');
$result = Startup::observe($result['state'], 'offline', 11, 120, 20, 'bootstrapped');
$check($result['action'] === 'failed', 'Bootstrapped failure still uses runtime confirmation.');
$result = Startup::callback([], 'running', 'offline', 10, 'none');
$check($result['action'] === 'ignore' && $result['state'] === [], 'An existing running-to-offline without a known start is not a startup attempt.');
$result = Startup::callback([], 'offline', 'running', 10, 'none');
$check($result['action'] === 'ignore', 'Already-running servers must not create startup watches.');

$state = Startup::callback($started(), 'starting', 'running', 105, 'unused')['state'];
$result = Startup::callback($state, 'running', 'offline', 110, 'unused');
$result = Startup::observe($result['state'], 'offline', 111, 120, 20, 'attempt-a');
$check($result['action'] === 'failed', 'Offline during quiet period is an early failure.');
$state = Startup::callback($started(), 'starting', 'running', 105, 'unused')['state'];
$result = Startup::observe($state, 'offline', 125, 120, 20, 'attempt-a');
$check($result['action'] === 'failed', 'A delayed offline snapshot alone cannot prove continuous healthy running during the quiet period.');
$state = Startup::callback($state, 'running', 'offline', 125, 'unused')['state'];
$result = Startup::observe($state, 'offline', 126, 120, 20, 'attempt-a');
$check($result['action'] === 'cancel' && $result['reason'] === 'stopped_after_quiet_period', 'A known offline transition after the quiet period is outside early failure attribution.');
$state = Startup::callback($started(), 'starting', 'running', 101, 'unused')['state'];
$state = Startup::callback($state, 'running', 'offline', 102, 'unused')['state'];
$result = Startup::observe($state, 'offline', 130, 120, 20, 'attempt-a');
$check($result['action'] === 'failed' && $result['state']['state_at'] === 102.0, 'Queue delay cannot stretch a known one-second run into a healthy quiet period.');
$result = Startup::observe($state, 'offline', 140, 30, 20, 'attempt-a');
$check($result['action'] === 'failed', 'An early known failure remains attributable when confirmation runs after the watch deadline but before the stale-job cutoff.');

$result = Startup::callback($started(), 'starting', 'stopping', 101, 'unused');
$check($result['action'] === 'cancel' && $result['state']['stopped'], 'Explicit stop must cancel diagnosis.');
$result = Startup::callback($result['state'], 'stopping', 'offline', 102, 'unused');
$check($result['action'] === 'ignore', 'Offline after explicit stop remains cancelled.');
$result = Startup::callback($result['state'], 'offline', 'starting', 103, 'restart');
$check($result['action'] === 'watch' && $result['state']['generation'] === 'restart' && !$result['state']['stopped'], 'Next start after normal stop gets a clean attempt.');
$result = Startup::observe($started(), 'stopping', 101, 120, 20, 'attempt-a');
$check($result['action'] === 'cancel', 'Fresh stopping snapshot cancels the attempt.');

$state = Startup::callback($started(), 'starting', 'running', 101, 'unused')['state'];
$state = Startup::observe($state, 'running', 105, 120, 20, 'attempt-a')['state'];
$result = Startup::callback($state, 'starting', 'offline', 106, 'unused');
$result = Startup::observe($result['state'], 'running', 107, 120, 20, 'attempt-a');
$check($result['action'] === 'watch' && !$result['state']['attempt_candidate'] && $result['state']['running_since'] === 101.0, 'Fresh running overrides stale offline without resetting the established quiet timer.');
$result = Startup::observe($result['state'], 'running', 121, 120, 20, 'attempt-a');
$check($result['action'] === 'healthy', 'A stale offline event must not stop health confirmation.');
$result = Startup::callback($state, 'offline', 'starting', 104, 'out-of-order');
$check($result['action'] === 'ignore' && $result['state'] === $state, 'Older callbacks cannot replace newer runtime evidence.');
$result = Startup::observe($state, 'offline', 104, 120, 20, 'attempt-a');
$check($result['action'] === 'ignore' && $result['state'] === $state, 'Older snapshots cannot replace newer observations.');
$result = Startup::callback($state, 'starting', 'starting', 106, 'duplicate');
$check($result['action'] === 'ignore' && $result['state'] === $state, 'Delayed starting-to-starting cannot rotate an active running generation.');

$state = $started();
$result = Startup::observe($state, 'offline', 101, 120, 20, 'older-generation');
$check($result['action'] === 'ignore' && $result['state'] === $state && $result['reason'] === 'stale_generation', 'Stale queued generations must leave the newer attempt untouched.');
$result = Startup::observe($state, 'starting', 130, 30, 20, 'attempt-a');
$check($result['action'] === 'timeout' && $result['state']['terminal'] === 'timeout', 'Starting at the watch deadline becomes an inconclusive timeout.');
$result = Startup::observe($state, 'unknown', 130, 30, 20, 'attempt-a');
$check($result['action'] === 'timeout', 'Unknown state times out without claiming a crash.');
$result = Startup::observe($state, 'offline', 251, 30, 20, 'attempt-a');
$check($result['action'] === 'cancel' && $result['reason'] === 'observation_too_late', 'Heavily delayed queue jobs must not diagnose old runs.');
$result = Startup::observe($state, 'offline', 131, 30, 20, 'attempt-a');
$check($result['action'] === 'cancel' && $result['reason'] === 'stopped_outside_startup_window', 'An offline snapshot beyond the watch window cannot be attributed to startup.');
$result = Startup::observe($state, 'running', 131, 30, 20, 'attempt-a');
$check($result['action'] === 'watch', 'Fresh running after deadline must get a quiet confirmation instead of timeout.');
$result = Startup::observe($result['state'], 'running', 151, 30, 20, 'attempt-a');
$check($result['action'] === 'healthy', 'Late stable running finishes without diagnosis.');

$state = Startup::callback($started(), 'starting', 'running', 101, 'unused')['state'];
$result = Startup::observe($state, 'starting', 105, 120, 20, 'attempt-a');
$check($result['action'] === 'watch' && $result['state']['running_since'] === null, 'Confirmed starting interrupts a prior running quiet timer.');
$result = Startup::observe($result['state'], 'running', 110, 120, 20, 'attempt-a');
$check($result['state']['running_since'] === 110.0, 'Running after confirmed starting starts a new quiet period.');
$result = Startup::observe([], 'offline', 100);
$check($result['action'] === 'ignore', 'Jobs without an attempt are no-ops.');

$intent = Startup::intent([], 100, 'intent-a');
$check($intent['action'] === 'watch' && $intent['state']['awaiting_transition'] && $intent['state']['state'] === 'unknown', 'Preboot intent must record an attempt without claiming a runtime transition.');
$duplicate = Startup::intent($intent['state'], 105, 'replacement');
$check($duplicate['action'] === 'ignore' && $duplicate['state'] === $intent['state'], 'Duplicate intents must preserve active generation and start time.');
$result = Startup::observe($intent['state'], 'offline', 110, 30, 20, 'intent-a');
$check($result['action'] === 'watch' && $result['state']['awaiting_transition'], 'Offline before an actual start transition must wait instead of claiming a crash.');
$result = Startup::observe($result['state'], 'offline', 130, 30, 20, 'intent-a');
$check($result['action'] === 'timeout' && $result['reason'] === 'intent_transition_unconfirmed', 'A rejected preboot attempt without callbacks must eventually report an inconclusive timeout.');
$result = Startup::observe($intent['state'], 'running', 110, 30, 20, 'intent-a');
$check($result['action'] === 'watch' && $result['state']['running_since'] === null, 'An old running instance during restart intent must not begin healthy confirmation.');
$result = Startup::observe($result['state'], 'running', 130, 30, 20, 'intent-a');
$check($result['action'] === 'timeout', 'Running without a confirmed start transition remains inconclusive at the intent deadline.');
$result = Startup::callback($intent['state'], 'offline', 'starting', 105, 'candidate-new');
$check($result['action'] === 'watch' && !$result['state']['awaiting_transition'] && $result['state']['generation'] === 'intent-a' && $result['state']['started_at'] === 100.0, 'Actual starting callback confirms intent without rotating generation or resetting its deadline.');
$result = Startup::callback($result['state'], 'starting', 'offline', 106, 'unused');
$result = Startup::observe($result['state'], 'offline', 107, 30, 20, 'intent-a');
$check($result['action'] === 'failed', 'Confirmed start followed by offline may be diagnosed as an actual startup failure.');
$result = Startup::callback($intent['state'], 'starting', 'offline', 105, 'unused');
$check(!$result['state']['awaiting_transition'] && $result['state']['attempt_candidate'], 'A starting-to-offline callback confirms intent when the initial starting callback was missed.');
$result = Startup::observe($result['state'], 'offline', 106, 30, 20, 'intent-a');
$check($result['action'] === 'failed', 'A confirmed rapid failed transition after intent must be diagnosed.');
$result = Startup::observe($intent['state'], 'starting', 105, 30, 20, 'intent-a');
$check(!$result['state']['awaiting_transition'] && $result['action'] === 'watch', 'Fresh starting snapshot confirms an intent without a callback.');
$result = Startup::observe($result['state'], 'running', 106, 30, 20, 'intent-a');
$result = Startup::observe($result['state'], 'running', 126, 30, 20, 'intent-a');
$check($result['action'] === 'healthy', 'A snapshot-confirmed starting transition followed by stable running completes without diagnosis.');
$restart = $intent['state'];
$restart['intent_action'] = 'restart';
$result = Startup::callback($restart, 'running', 'stopping', 105, 'unused');
$check($result['action'] === 'watch' && $result['state']['awaiting_transition'], 'Stopping the old instance during a pending restart intent must not cancel the restart watch.');
$result = Startup::observe($result['state'], 'stopping', 106, 30, 20, 'intent-a');
$check($result['action'] === 'watch' && !$result['state']['stopped'], 'The pending restart may pass through a fresh stopping snapshot.');
$result = Startup::callback($result['state'], 'stopping', 'starting', 107, 'unused');
$result = Startup::callback($result['state'], 'starting', 'stopping', 108, 'unused');
$check($result['action'] === 'cancel', 'Stopping after the new restart transition is confirmed cancels normally.');
$result = Startup::callback($intent['state'], 'offline', 'stopping', 105, 'unused');
$check($result['action'] === 'cancel', 'A normal start intent stopped explicitly must cancel.');
$result = Startup::intent($terminal, 200, 'intent-b');
$check($result['action'] === 'watch' && $result['state']['generation'] === 'intent-b' && $result['state']['awaiting_transition'], 'An intent after a terminal attempt gets a fresh generation.');

echo 'Automatic startup state: ' . $checks . " checks passed.\n";
