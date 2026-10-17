<?php

/**
 * tdd-gate — refuses to end an assistant turn while the TDD loop is open.
 *
 * Reads the per-session state written by tdd-guard.php:
 * - phase "regression": the Unit+Feature suites were red at their last run.
 * - phase "red": tests were just written and fail (expected RED phase), but
 *   no green implementation pass has landed yet.
 * - dirty: code or tests were edited since the last covering test run.
 *
 * Denies are capped at 3 retries per hook per user turn by Vibe; past that
 * the denial degrades to a warning, so an agent can still explain why it
 * legitimately stops at red (e.g. the user only asked for the test plan).
 */

declare(strict_types=1);

/** @return array<string, mixed> */
function payload(): array
{
    $raw = stream_get_contents(STDIN);

    return is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
}

/** @return array<string, mixed> */
function loadState(array $payload): array
{
    $sessionId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($payload['session_id'] ?? ''));
    $path = sys_get_temp_dir().'/vibe-tdd-'.($sessionId !== '' ? $sessionId : 'default').'.json';
    $raw = @file_get_contents($path);

    return is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
}

/** Refuses the turn; the reason is injected as a user message. */
function deny(string $reason): never
{
    echo json_encode(['decision' => 'deny', 'reason' => $reason]);
    exit(0);
}

$state = loadState(payload());
$phase = (string) ($state['phase'] ?? 'unknown');

if ($phase === 'regression') {
    deny('tdd-gate: Unit+Feature are RED at their last run - the task is not done. Continue the implementation pass and rerun `composer test` until green.');
}

if ($phase === 'red') {
    deny('tdd-gate: tests were written and are RED (normal TDD phase), but nothing is green yet. Do the minimal implementation pass and rerun the tests. Only stop at red if the user explicitly asked for the test plan alone - in that case, restate that and stop.');
}

if (! empty($state['dirty'])) {
    deny('tdd-gate: code or tests were edited since the last test run. Rerun the covering scope (`composer test` or the narrowest test file) before finishing this turn.');
}

// No dev activity in this session, or everything is green: passthrough.
exit(0);
