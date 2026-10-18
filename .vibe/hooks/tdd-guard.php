<?php

/**
 * tdd-guard — red/green feedback loop for Vibe hooks.
 *
 * Dispatches on hook_event_name:
 * - pre_tool (bash): refuses E2E runs (`composer test:e2e`, `--testsuite=Browser`,
 *   tests/Browser) while the dev suites are red or stale — E2E is the final
 *   gate, never part of the dev loop.
 * - post_tool (edit/write on tests/ or source): runs PHPUnit — the edited file
 *   only when it is a test, the Unit+Feature suites when it is source or test
 *   support code — and appends the verdict to the tool result. Informational
 *   only: it never blocks an edit.
 * - post_tool (bash): watches manual test runs (`composer test`, `artisan test`,
 *   phpunit/pest) and records the outcome so tdd-gate.php can judge the turn.
 *
 * Per-session state lives in the system temp dir (see statePath). The TDD skill
 * defines the workflow; this hook supplies the feedback loop that makes it
 * real, mirroring the tdd-guard plugin under .opencode/plugins/tdd/.
 */

declare(strict_types=1);

const PHPUNIT_TIMEOUT_SECONDS = 90;

/**
 * phpunit.xml sets these <env> entries only when absent, so values inherited
 * from the host process would silently override the testing profile. Strip
 * them all: phpunit.xml is the single source of truth for test runs.
 */
const LARAVEL_ENV_PREFIX = '/^(APP_|DB_|SESSION_|CACHE_|QUEUE_|MAIL_|BROADCAST_|REDIS_|MEMCACHED_|FILESYSTEM_|AWS_|LOG_|BCRYPT_|VITE_|TELESCOPE_|PULSE_|NIGHTWATCH_)/';

const TEST_FILE = '~(^|/)tests/.*Test\.php$~';
const TEST_DIR = '~(^|/)tests/~';
const SOURCE_FILE = '~(^|/)(app|routes|database|config|resources/js|resources/views)/~';

// Dev-loop scope: Unit + Feature. E2E (Browser) is excluded from every
// automatic run — it is the final gate, not the feedback loop.
const DEV_SUITES = ['--testsuite', 'Unit,Feature'];

/** @return array<string, mixed> */
function payload(): array
{
    $raw = stream_get_contents(STDIN);

    return is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
}

/** @param array<string, mixed> $payload */
function statePath(array $payload): string
{
    $sessionId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($payload['session_id'] ?? ''));

    return sys_get_temp_dir().'/vibe-tdd-'.($sessionId !== '' ? $sessionId : 'default').'.json';
}

/** @param array<string, mixed> $state */
function saveState(array $payload, array $state): void
{
    @file_put_contents(statePath($payload), json_encode($state));
}

/** @return array<string, mixed> */
function loadState(array $payload): array
{
    $raw = @file_get_contents(statePath($payload));

    return is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
}

/** @return array<string, string> */
function sanitizedEnv(): array
{
    $env = getenv();
    foreach (array_keys($env) as $key) {
        if (preg_match(LARAVEL_ENV_PREFIX, (string) $key)) {
            unset($env[$key]);
        }
    }

    return $env;
}

/**
 * Runs vendor/bin/phpunit and returns its exit code and combined output.
 *
 * @param  array<int, string>  $args
 * @return array{code: int, out: string}
 */
function runPhpunit(string $root, array $args): array
{
    $phpunit = $root.'/vendor/bin/phpunit';
    if (!is_file($phpunit)) {
        return ['code' => -1, 'out' => ''];
    }

    $process = proc_open(
        [PHP_BINARY, $phpunit, ...$args],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        sanitizedEnv(),
    );
    if (!is_resource($process)) {
        return ['code' => -1, 'out' => ''];
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $out = '';
    $code = -1;
    $start = microtime(true);
    while (true) {
        $out .= (string) stream_get_contents($pipes[1]);
        $out .= (string) stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (! $status['running']) {
            $code = (int) $status['exitcode'];
            break;
        }
        if (microtime(true) - $start > PHPUNIT_TIMEOUT_SECONDS) {
            proc_terminate($process);
            $code = 124;
            break;
        }
        usleep(50_000);
    }

    $out .= (string) stream_get_contents($pipes[1]);
    $out .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return ['code' => $code, 'out' => trim($out)];
}

/**
 * @return array{state: 'green'|'red'|'empty'|'error', summary: string, failures: array<int, string>}
 */
function summarize(int $code, string $out): array
{
    // PHPUnit colors its output (phpunit.xml sets colors="true"); strip ANSI
    // so the verdict regexes match.
    $out = (string) preg_replace('/\x1b\[[0-9;?]*[a-zA-Z]/', '', $out);

    // Tolerates a machine-readable one-liner some output layers emit for phpunit.
    if (preg_match('/^\s*\{"tool":"(?:phpunit|artisan test)".*\}\s*$/s', $out, $m)) {
        $json = json_decode($m[0], true);
        if (is_array($json)) {
            $count = sprintf('%s tests, %s assertions', $json['tests'] ?? '?', $json['assertions'] ?? '?');
            if (($json['result'] ?? null) === 'passed') {
                return ['state' => 'green', 'summary' => $count, 'failures' => []];
            }
            $names = array_merge((array) ($json['failures'] ?? []), (array) ($json['error_details'] ?? []));
            $names = array_slice(array_filter(array_map(
                static fn ($f) => is_array($f) ? (string) ($f['test'] ?? '') : '',
                $names,
            )), 0, 3);

            return ['state' => 'red', 'summary' => sprintf('%s, %d failed', $count, $json['failed'] ?? 0), 'failures' => $names];
        }
    }

    if ($code === 0) {
        return [
            'state' => 'green',
            'summary' => (string) (preg_match('/^OK\b.*$/m', $out, $m) ? trim($m[0]) : 'all tests passed'),
            'failures' => [],
        ];
    }

    if ($code === 124) {
        return ['state' => 'error', 'summary' => 'test run exceeded '.PHPUNIT_TIMEOUT_SECONDS.'s and was killed', 'failures' => []];
    }

    if (preg_match('/No tests (?:found|executed)/', $out)) {
        return ['state' => 'empty', 'summary' => 'no tests executed in this scope', 'failures' => []];
    }

    if (preg_match('/^.*PHP (?:Parse|Fatal) error:.*$/m', $out, $m)) {
        return ['state' => 'error', 'summary' => trim($m[0]), 'failures' => []];
    }

    preg_match_all('/^\d+\)\s+\S+/m', $out, $matches);
    $failures = array_slice(array_map(
        static fn ($f) => (string) preg_replace('/^\d+\)\s+/', '', $f),
        $matches[0],
    ), 0, 3);

    return [
        'state' => 'red',
        'summary' => (string) (preg_match('/^Tests:.*$/m', $out, $m) ? trim($m[0]) : 'exit code '.$code),
        'failures' => $failures,
    ];
}

/** Appends a verdict block to the tool result the LLM will see. */
function addContext(string $text): never
{
    echo json_encode(['hook_specific_output' => ['additional_context' => $text]]);
    exit(0);
}

/** Refuses the tool call / turn; the reason becomes the error the LLM sees. */
function deny(string $reason): never
{
    echo json_encode(['decision' => 'deny', 'reason' => $reason]);
    exit(0);
}

/** @param array<string, mixed> $input */
function inputPath(array $input): ?string
{
    foreach (['file_path', 'path', 'filePath', 'target_path'] as $key) {
        $value = $input[$key] ?? null;
        if (is_string($value) && $value !== '') {
            return str_replace('\\', '/', $value);
        }
    }

    return null;
}

/** @param array<string, mixed> $payload */
function absolutePath(array $payload, string $path): string
{
    if ($path[0] === '/') {
        return $path;
    }
    $cwd = (string) ($payload['cwd'] ?? getcwd());

    return rtrim($cwd, '/').'/'.$path;
}

function isE2eCommand(string $command): bool
{
    return (bool) preg_match('/composer\s+(?:run\s+)?test:e2e|--testsuite(?:=|\s+)Browser|tests\/Browser\/|artisan\s+test\b[^\n]*Browser/', $command);
}

function isDevTestCommand(string $command): bool
{
    return (bool) preg_match('/vendor\/bin\/(?:phpunit|pest)|composer\s+(?:run\s+)?test\b|artisan\s+test\b/', $command);
}

/** A narrow run targets one file or filter and cannot clear a suite verdict. */
function isNarrowRun(string $command): bool
{
    return (bool) preg_match('/--filter\b|tests\/\S+|artisan\s+test\s+--compact\s+\S/', $command);
}

/** @param array<string, mixed> $state */
function recordVerdict(array &$state, string $phase, bool $clearDirty): void
{
    $state['phase'] = $phase;
    $state['phase_at'] = time();
    if ($clearDirty) {
        $state['dirty'] = false;
    }
}

/**
 * Records the outcome of a test run the agent made through bash, parsed from
 * the tool output the agent just saw.
 *
 * @param array<string, mixed> $state
 */
function recordBashRun(array &$state, string $command, string $outputText, bool $toolFailed): void
{
    if (isE2eCommand($command)) {
        $state['e2e_at'] = time();

        return;
    }
    if (! isDevTestCommand($command)) {
        return;
    }

    $verdict = summarize($toolFailed ? 1 : 0, $outputText);
    if ($verdict['state'] === 'empty' || $verdict['state'] === 'error') {
        return;
    }

    if (isNarrowRun($command)) {
        // Narrow runs cannot prove the suites: green changes nothing, red is red.
        if ($verdict['state'] === 'red') {
            recordVerdict($state, 'red', false);
        }

        return;
    }

    recordVerdict($state, $verdict['state'] === 'green' ? 'green' : 'regression', true);
}

/** @param array<int, string> $lines */
function block(array $lines): string
{
    return implode("\n", ['-- tdd-guard --', ...$lines, '----------------']);
}

/**
 * @param  array{state: string, summary: string, failures: array<int, string>}  $verdict
 * @return array<int, string>
 */
function testFileLines(array $verdict, string $rel, bool $isNewFile): array
{
    $lines = [];
    if ($verdict['state'] === 'green') {
        $lines[] = "GREEN - {$rel} ({$verdict['summary']})";
        if ($isNewFile) {
            $lines[] = 'brand-new test passed immediately: the behavior already exists, or the test asserts nothing. A new TDD test must fail first.';
        }
    } elseif ($verdict['state'] === 'empty') {
        $lines[] = "{$rel}: {$verdict['summary']} - empty skeleton?";
    } elseif ($verdict['state'] === 'error') {
        $lines[] = "{$rel}: {$verdict['summary']}";
    } else {
        $lines[] = "RED - {$rel} ({$verdict['summary']})";
        foreach ($verdict['failures'] as $failure) {
            $lines[] = "   x {$failure}";
        }
        $lines[] = 'Expected while the behavior does not exist yet - write the minimal implementation that turns this green.';
    }

    return $lines;
}

/**
 * @param  array{state: string, summary: string, failures: array<int, string>}  $verdict
 * @return array<int, string>
 */
function suiteLines(array $verdict): array
{
    $lines = [];
    if ($verdict['state'] === 'green') {
        $lines[] = "Unit+Feature GREEN ({$verdict['summary']})";
        $lines[] = 'Browser (E2E) excluded from the dev loop - run `composer test:e2e` once at the final gate.';
    } elseif ($verdict['state'] === 'red') {
        $lines[] = "Unit+Feature RED - {$verdict['summary']}";
        foreach ($verdict['failures'] as $failure) {
            $lines[] = "   x {$failure}";
        }
        $lines[] = 'The task is not done while this is red.';
    } elseif ($verdict['state'] === 'error') {
        $lines[] = "Unit+Feature cannot run: {$verdict['summary']}";
    } else {
        $lines[] = $verdict['summary'];
    }

    return $lines;
}

$payload = payload();
$event = (string) ($payload['hook_event_name'] ?? '');
$tool = strtolower((string) ($payload['tool_name'] ?? ''));
$input = is_array($payload['tool_input'] ?? null) ? $payload['tool_input'] : [];
$state = loadState($payload);
$root = dirname(__DIR__, 2);
$isNewFile = str_contains($tool, 'write');

// Final-gate enforcement: no E2E while the dev loop is red or stale.
if ($event === 'pre_tool') {
    // Tool names may be namespaced by the runtime (e.g. "file_system.bash").
    if (str_contains($tool, 'bash') && isE2eCommand((string) ($input['command'] ?? ''))) {
        if (in_array($state['phase'] ?? '', ['red', 'regression'], true) || ! empty($state['dirty'])) {
            deny('E2E is the final gate: the Unit+Feature suites are red or stale. Bring `composer test` to green first, then run `composer test:e2e` once.');
        }
    }
    exit(0);
}

if ($event !== 'post_tool') {
    exit(0);
}

// The agent ran tests through bash: trust the output it just saw.
if (str_contains($tool, 'bash')) {
    recordBashRun($state, (string) ($input['command'] ?? ''), (string) ($payload['tool_output_text'] ?? ''), ($payload['tool_status'] ?? '') === 'failure');
    saveState($payload, $state);
    exit(0);
}

// Edits and writes on test/source files: run the covering scope.
$path = inputPath($input);
if ($path === null || (! preg_match(TEST_FILE, $path) && ! preg_match(TEST_DIR, $path) && ! preg_match(SOURCE_FILE, $path))) {
    exit(0);
}

$absPath = absolutePath($payload, $path);
$rel = ltrim(str_replace($root, '', $absPath), '/');
$state['dirty'] = true;

if (preg_match(TEST_FILE, $path)) {
    if (preg_match('~(^|/)tests/Browser/~', $path)) {
        // Browser (E2E) files need the Playwright server and are excluded
        // from the dev loop by design: running them under plain phpunit
        // fails (exit 255) and would record a phantom RED verdict. The
        // dirty flag stands; the next dev-suite run clears it.
        saveState($payload, $state);
        addContext(block([
            'Browser (E2E) file '.$rel.' - excluded from the dev loop; covered by `composer test:e2e` at the final gate.',
        ]));
    }

    $run = runPhpunit($root, [$absPath]);
    $verdict = summarize($run['code'], $run['out']);
    // A green single file only clears the red-test phase, never a suite regression.
    if ($verdict['state'] === 'red') {
        recordVerdict($state, 'red', false);
    } elseif ($verdict['state'] === 'green' && ($state['phase'] ?? '') !== 'regression') {
        recordVerdict($state, 'green', true);
    }
    $lines = testFileLines($verdict, $rel, $isNewFile);
    if (($state['phase'] ?? '') === 'regression' && $verdict['state'] !== 'green') {
        $lines[] = 'the Unit+Feature suites were RED at their last run - fix the code rather than adjusting the test, unless the test itself is wrong.';
    }
} else {
    $run = runPhpunit($root, DEV_SUITES);
    $verdict = summarize($run['code'], $run['out']);
    recordVerdict($state, $verdict['state'] === 'green' ? 'green' : ($verdict['state'] === 'red' ? 'regression' : ($state['phase'] ?? 'unknown')), $verdict['state'] === 'green');
    $lines = suiteLines($verdict);
}

saveState($payload, $state);
addContext(block($lines));
