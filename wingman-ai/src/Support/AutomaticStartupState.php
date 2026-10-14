<?php

declare(strict_types=1);

namespace Pelican\AiHelpAssistant\Support;

/** Pure reducer: callbacks nominate attempts; fresh runtime observations decide outcomes. */
final class AutomaticStartupState
{
    public static function intent(array $state, float $now, ?string $generation = null): array
    {
        if (isset($state['generation']) && ($state['terminal'] ?? null) === null) {
            return self::decision($state, 'ignore', 'duplicate_intent');
        }
        $state = self::begin($generation, $now, 'unknown');
        $state['awaiting_transition'] = true;
        return self::decision($state, 'watch', 'intent_recorded');
    }

    public static function callback(
        array $state,
        string $previousState,
        string $newState,
        float $now,
        ?string $generation = null,
    ): array {
        $previousState = self::status($previousState);
        $newState = self::status($newState);
        if ($newState === 'unknown') {
            return self::decision($state, 'ignore', 'unknown_callback_state');
        }
        if ($state !== [] && $now < max((float) ($state['last_callback_at'] ?? 0), (float) ($state['last_snapshot_at'] ?? 0))) {
            return self::decision($state, 'ignore', 'out_of_order_callback');
        }

        $active = $state !== [] && ($state['terminal'] ?? null) === null;
        if ($newState === 'starting') {
            if ($active && ($state['awaiting_transition'] ?? false)) {
                $state['awaiting_transition'] = false;
                $state['state'] = 'starting';
                $state['state_at'] = $now;
                $state['last_callback_at'] = $now;
                $state['running_since'] = null;
                $state['attempt_candidate'] = false;
                return self::decision($state, 'watch', 'intent_transition_observed');
            }
            // Duplicate callbacks must not rotate the generation or its start time.
            if ($active && ($state['state'] ?? null) === 'starting') {
                return self::decision($state, 'ignore', 'duplicate_starting');
            }
            // A delayed duplicate from the same starting transition cannot revive a terminal attempt.
            if ($state !== [] && $previousState === 'starting') {
                return self::decision($state, 'ignore', 'starting_transition_duplicate');
            }
            return self::decision(self::begin($generation, $now, 'starting'), 'watch', 'attempt_started');
        }

        if ($state === []) {
            // The first callback may arrive after a very short failed start.
            if ($previousState !== 'starting' || !in_array($newState, ['running', 'offline'], true)) {
                return self::decision($state, 'ignore', 'no_startup_attempt');
            }
            $state = self::begin($generation, $now, $newState);
            $state['attempt_candidate'] = $newState === 'offline';
            return self::decision($state, 'watch', 'attempt_bootstrapped');
        }

        if (!$active) {
            return self::decision($state, 'ignore', 'attempt_terminal');
        }
        if (($state['awaiting_transition'] ?? false)
            && $previousState === 'starting' && in_array($newState, ['offline', 'running'], true)) {
            // The starting callback can be missing while its next transition still arrives.
            $state['awaiting_transition'] = false;
        }
        if (($state['awaiting_transition'] ?? false)
            && ($newState !== 'stopping' || ($state['intent_action'] ?? '') === 'restart')) {
            $state['state'] = $newState;
            $state['state_at'] = $now;
            $state['last_callback_at'] = $now;
            $state['running_since'] = null;
            $state['attempt_candidate'] = false;
            return self::decision($state, 'watch', 'awaiting_start_transition');
        }
        if ($newState === 'stopping') {
            $state['state'] = 'stopping';
            $state['state_at'] = $now;
            $state['last_callback_at'] = $now;
            $state['stopped'] = true;
            $state['attempt_candidate'] = false;
            $state['terminal'] = 'cancel';
            return self::decision($state, 'cancel', 'explicit_stop');
        }
        if (($state['state'] ?? null) === $newState) {
            return self::decision($state, 'ignore', 'duplicate_callback');
        }

        $state['state'] = $newState;
        $state['state_at'] = $now;
        $state['last_callback_at'] = $now;
        if ($newState === 'running') {
            // Keep an observed run's original timer across unconfirmed offline callbacks.
            $state['running_since'] ??= $now;
            $state['attempt_candidate'] = false;
            return self::decision($state, 'watch', 'running_needs_quiet_period');
        }
        if ($newState === 'offline') {
            $state['attempt_candidate'] = true;
            // Do not diagnose from callback order: a new runtime snapshot must confirm this.
            return self::decision($state, 'watch', 'offline_needs_confirmation');
        }

        return self::decision($state, 'watch', 'awaiting_runtime_observation');
    }

    public static function observe(
        array $state,
        string $daemonState,
        float $now,
        int $watchSeconds = 120,
        int $quietSeconds = 20,
        ?string $expectedGeneration = null,
    ): array {
        if ($state === []) {
            return self::decision($state, 'ignore', 'no_startup_attempt');
        }
        if ($expectedGeneration !== null && ($state['generation'] ?? null) !== $expectedGeneration) {
            return self::decision($state, 'ignore', 'stale_generation');
        }
        if (($state['terminal'] ?? null) !== null) {
            return self::decision($state, 'ignore', 'attempt_terminal');
        }
        if ($now < max((float) ($state['last_snapshot_at'] ?? 0), (float) ($state['last_callback_at'] ?? 0))) {
            return self::decision($state, 'ignore', 'out_of_order_snapshot');
        }
        $watchSeconds = max(1, $watchSeconds);
        $quietSeconds = max(1, $quietSeconds);
        $elapsed = max(0, $now - (float) $state['started_at']);
        if ($elapsed > $watchSeconds + 120) {
            $state['terminal'] = 'cancel';
            $state['attempt_candidate'] = false;
            return self::decision($state, 'cancel', 'observation_too_late');
        }

        $daemonState = self::status($daemonState);
        $state['last_snapshot_at'] = $now;
        $state['last_snapshot_state'] = $daemonState;
        if (($state['awaiting_transition'] ?? false) && $daemonState === 'starting') {
            $state['awaiting_transition'] = false;
        }
        if (($state['awaiting_transition'] ?? false)
            && ($daemonState !== 'stopping' || ($state['intent_action'] ?? '') === 'restart')) {
            if (($state['state'] ?? null) !== $daemonState) {
                $state['state_at'] = $now;
            }
            $state['state'] = $daemonState;
            $state['running_since'] = null;
            $state['attempt_candidate'] = false;
            if ($elapsed >= $watchSeconds) {
                $state['terminal'] = 'timeout';
                return self::decision($state, 'timeout', 'intent_transition_unconfirmed');
            }
            return self::decision($state, 'watch', 'awaiting_start_transition');
        }
        if ($daemonState === 'stopping' || ($state['stopped'] ?? false)) {
            $state['state'] = $daemonState;
            $state['state_at'] = $now;
            $state['stopped'] = true;
            $state['attempt_candidate'] = false;
            $state['terminal'] = 'cancel';
            return self::decision($state, 'cancel', 'explicit_stop');
        }
        if ($daemonState === 'running') {
            $state['running_since'] ??= $now;
            if (($state['state'] ?? null) !== 'running') {
                $state['state_at'] = $now;
            }
            $state['state'] = 'running';
            $state['attempt_candidate'] = false;
            if ($now - (float) $state['running_since'] >= $quietSeconds) {
                $state['terminal'] = 'healthy';
                return self::decision($state, 'healthy', 'stable_running');
            }
            // A healthy live process takes precedence over the elapsed startup deadline.
            return self::decision($state, 'watch', 'running_needs_quiet_period');
        }
        if ($daemonState === 'offline') {
            // A queued check may arrive well after an already-seen immediate crash.
            // Measure that candidate's transition time, not when its confirmation runs.
            $hadOfflineCandidate = ($state['state'] ?? null) === 'offline' && ($state['attempt_candidate'] ?? false);
            $offlineAt = $hadOfflineCandidate ? (float) $state['state_at'] : $now;
            $state['state'] = 'offline';
            $state['state_at'] = $offlineAt;
            $state['attempt_candidate'] = false;
            if ($hadOfflineCandidate && $state['running_since'] !== null && $offlineAt - (float) $state['running_since'] >= $quietSeconds) {
                $state['terminal'] = 'cancel';
                return self::decision($state, 'cancel', 'stopped_after_quiet_period');
            }
            if ($offlineAt - (float) $state['started_at'] > $watchSeconds) {
                $state['terminal'] = 'cancel';
                return self::decision($state, 'cancel', 'stopped_outside_startup_window');
            }
            $state['terminal'] = 'failed';
            return self::decision($state, 'failed', 'early_offline_confirmed');
        }
        if ($daemonState === 'starting') {
            if (($state['state'] ?? null) !== 'starting') {
                $state['state_at'] = $now;
            }
            $state['state'] = 'starting';
            // A runtime-confirmed starting state interrupts any earlier running period.
            $state['running_since'] = null;
            $state['attempt_candidate'] = false;
        }
        if ($elapsed >= $watchSeconds) {
            $state['terminal'] = 'timeout';
            return self::decision($state, 'timeout', 'startup_result_unconfirmed');
        }

        return self::decision($state, 'watch', $daemonState === 'starting' ? 'still_starting' : 'runtime_state_unknown');
    }

    private static function begin(?string $generation, float $now, string $status): array
    {
        return [
            'generation' => $generation !== null && $generation !== '' ? $generation : sprintf('attempt-%.6F', $now),
            'started_at' => $now,
            'state' => $status,
            'state_at' => $now,
            'running_since' => $status === 'running' ? $now : null,
            'stopped' => false,
            'attempt_candidate' => false,
            'awaiting_transition' => false,
            'last_callback_at' => $now,
            'last_snapshot_at' => null,
            'last_snapshot_state' => null,
            'terminal' => null,
        ];
    }

    private static function status(string $state): string
    {
        return match (strtolower(trim($state))) {
            'starting' => 'starting',
            'running', 'online' => 'running',
            'stopping' => 'stopping',
            'offline', 'stopped', 'crashed' => 'offline',
            default => 'unknown',
        };
    }

    private static function decision(array $state, string $action, string $reason): array
    {
        return ['state' => $state, 'action' => $action, 'reason' => $reason];
    }
}
