<?php

/**
 * issue-dev — lifecycle enforcement for TaskR issue development.
 *
 * One script, two contexts:
 * - CLI mode (arguments, called by the agent through bash): the state
 *   machine the taskr-issue-dev skill drives — `start`, `status`,
 *   `knowledge-reviewed`, `cancel`.
 * - Hook mode (JSON payload on stdin, no arguments): dispatched by
 *   .vibe/hooks.toml.
 *   - pre_tool: blocks out-of-order actions while a workflow is active —
 *     source edits before the plan comment, unmarked TaskR comments, time
 *     entries before the done comment.
 *   - post_tool: records progress from the observed tool calls (knowledge
 *     search, code exploration, plan/done comments, time entries, knowledge
 *     updates) and nudges the agent to declare the workflow when it touches
 *     the TaskR connector with no active workflow.
 *   - post_agent: refuses to end a turn while a declared workflow is
 *     incomplete.
 *
 * State lives in one JSON file per project root (keyed by this script's own
 * location so the CLI and the hook always agree, even when the agent's cwd
 * differs). One active workflow per repo at a time: concurrent Vibe sessions
 * on the same checkout share it.
 *
 * The taskr-issue-dev skill defines the workflow; this hook makes each step
 * impossible to skip, mirroring the tdd-guard/tdd-gate pattern.
 */

declare(strict_types=1);

const PLAN_MARKER = 'taskr:plan';
const DONE_MARKER = 'taskr:done';

// Editing these paths counts as implementation and requires the plan
// comment. .vibe/, docs and scratch files are excluded on purpose.
const SOURCE_FILE = '~(^|/)(app|routes|database|config|resources/js|resources/views|tests)/~';

function root(): string
{
    return dirname(__DIR__, 2);
}

/** @return array<string, mixed> */
function payload(): array
{
    $raw = stream_get_contents(STDIN);

    return is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
}

function statePath(): string
{
    return sys_get_temp_dir().'/vibe-issue-dev-'.substr(sha1(root()), 0, 12).'.json';
}

/** @return array<string, mixed> */
function loadState(): array
{
    $raw = @file_get_contents(statePath());

    return is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
}

/** @param array<string, mixed> $state */
function saveState(array $state): void
{
    @file_put_contents(statePath(), json_encode($state));
}

/** @return array<string, mixed> */
function freshState(string $workspaceId, string $issueId): array
{
    return [
        'active' => true,
        'workspace_id' => $workspaceId,
        'issue_id' => $issueId,
        'started_at' => time(),
        'knowledge_searched' => false,
        'code_explored' => false,
        'plan_comment' => false,
        'done_comment' => false,
        'time_logged' => false,
        'knowledge_reviewed' => false,
    ];
}

/** Refuses the tool call / turn; the reason becomes the error the LLM sees. */
function deny(string $reason): never
{
    echo json_encode(['decision' => 'deny', 'reason' => $reason]);
    exit(0);
}

/** Appends a context block to the tool result the LLM will see. */
function addContext(string $text): never
{
    echo json_encode(['hook_specific_output' => ['additional_context' => $text]]);
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

/** The bash command the agent uses to drive this state machine itself. */
function isCliInvocation(string $command): bool
{
    return (bool) preg_match('~issue-dev\.php\s+(start|status|knowledge-reviewed|cancel)\b~', $command);
}

/**
 * @param array<string, mixed> $state
 * @return array<int, string>
 */
function missingSteps(array $state): array
{
    $steps = [];
    if (empty($state['knowledge_searched'])) {
        $steps[] = 'search the knowledge base (search_knowledge + get_knowledge on the relevant hits)';
    }
    if (empty($state['code_explored'])) {
        $steps[] = 'explore the codebase (read files / grep the areas concerned)';
    }
    if (empty($state['plan_comment'])) {
        $steps[] = 'post the plan comment (add_comment starting with `<!-- '.PLAN_MARKER.' -->`)';
    }
    if (empty($state['done_comment'])) {
        $steps[] = 'post the done comment (add_comment starting with `<!-- '.DONE_MARKER.' -->`)';
    }
    if (empty($state['time_logged'])) {
        $steps[] = 'log the time spent (first comment created_at -> now, then log_time)';
    }
    if (empty($state['knowledge_reviewed'])) {
        $steps[] = 'reconcile the knowledge base (update_knowledge / create_knowledge, or `php .vibe/hooks/issue-dev.php knowledge-reviewed` when nothing deserves an update)';
    }

    return $steps;
}

/** @param array<string, mixed> $state */
function issueLabel(array $state): string
{
    return (string) ($state['issue_id'] ?? 'unknown');
}

/**
 * CLI mode. Exit code 0 on success, 1 on usage error (the bash verdict tells
 * the agent something went wrong).
 *
 * @param array<int, string> $argv
 */
function cliMain(array $argv): int
{
    $command = (string) ($argv[1] ?? '');
    $state = loadState();

    switch ($command) {
        case 'start':
            $workspaceId = trim((string) ($argv[2] ?? ''));
            $issueId = trim((string) ($argv[3] ?? ''));
            if (! preg_match('/^[0-9A-Za-z]{20,}$/', $workspaceId) || ! preg_match('/^[0-9A-Za-z]{20,}$/', $issueId)) {
                fwrite(STDERR, 'usage: php .vibe/hooks/issue-dev.php start <workspace_ulid> <issue_ulid>'.PHP_EOL);

                return 1;
            }
            saveState(freshState($workspaceId, $issueId));
            echo 'issue-dev: workflow opened for issue '.$issueId.'.'.PHP_EOL
                .'Next: research the issue (search_knowledge + get_knowledge + code exploration), then the plan comment with `<!-- '.PLAN_MARKER.' -->`.'.PHP_EOL;

            return 0;

        case 'status':
            if (empty($state['active'])) {
                echo 'issue-dev: no active workflow.'.PHP_EOL;

                return 0;
            }
            echo 'issue-dev: workflow active for issue '.issueLabel($state).' (started '.date('Y-m-d H:i', (int) ($state['started_at'] ?? time())).').'.PHP_EOL;
            $steps = missingSteps($state);
            echo $steps === []
                ? 'All steps complete - the gate will close the workflow.'.PHP_EOL
                : 'Remaining:'.PHP_EOL.'- '.implode(PHP_EOL.'- ', $steps).PHP_EOL;

            return 0;

        case 'knowledge-reviewed':
            if (empty($state['active'])) {
                fwrite(STDERR, 'issue-dev: no active workflow to mark.'.PHP_EOL);

                return 1;
            }
            $state['knowledge_reviewed'] = true;
            saveState($state);
            echo 'issue-dev: knowledge review attested.'.PHP_EOL;
            $steps = missingSteps($state);
            echo $steps === []
                ? 'All steps complete.'.PHP_EOL
                : 'Remaining:'.PHP_EOL.'- '.implode(PHP_EOL.'- ', $steps).PHP_EOL;

            return 0;

        case 'cancel':
            saveState(['active' => false]);
            echo 'issue-dev: workflow cancelled - the gate stands down.'.PHP_EOL;

            return 0;

        default:
            fwrite(STDERR, 'usage: php .vibe/hooks/issue-dev.php start <workspace_ulid> <issue_ulid> | status | knowledge-reviewed | cancel'.PHP_EOL);

            return 1;
    }
}

/**
 * pre_tool ordering rules, active workflow only. Denies are how the
 * lifecycle stays honest — each message names the step that must come first.
 *
 * @param array<string, mixed> $state
 * @param array<string, mixed> $input
 */
function preToolChecks(array $state, string $tool, array $input): void
{
    if (str_contains($tool, 'bash') && isCliInvocation((string) ($input['command'] ?? ''))) {
        return;
    }

    $path = inputPath($input);
    if ((str_contains($tool, 'edit') || str_contains($tool, 'write')) && $path !== null && preg_match(SOURCE_FILE, $path)) {
        if (empty($state['plan_comment'])) {
            deny('issue-dev: the plan comment for issue '.issueLabel($state).' must be posted before touching code. Research the issue first (search_knowledge + get_knowledge + code exploration), then add_comment starting with `<!-- '.PLAN_MARKER.' -->` (see the taskr-issue-dev skill).');
        }

        return;
    }

    if (str_contains($tool, 'typescript')) {
        $code = (string) ($input['code'] ?? '');

        if (str_contains($code, 'log_time') && empty($state['done_comment'])) {
            deny('issue-dev: log_time comes after the done comment - the logged duration runs from the issue\'s first comment to now, so the implementation must be finished and reported first.');
        }

        if (str_contains($code, 'add_comment')) {
            $hasPlan = str_contains($code, PLAN_MARKER);
            $hasDone = str_contains($code, DONE_MARKER);

            if (! $hasPlan && ! $hasDone) {
                deny('issue-dev: comments on issue '.issueLabel($state).' must start with a marker line - `<!-- '.PLAN_MARKER.' -->` for the pre-implementation plan, `<!-- '.DONE_MARKER.' -->` for the completion report.');
            }
            if ($hasDone && empty($state['plan_comment'])) {
                deny('issue-dev: the plan comment comes first - post the plan (`<!-- '.PLAN_MARKER.' -->`) before the done comment.');
            }
            if ($hasDone && ! empty($state['done_comment'])) {
                deny('issue-dev: the done comment for issue '.issueLabel($state).' was already posted - amend it with update_comment instead of adding a new one.');
            }
            if ($hasPlan && ! empty($state['plan_comment'])) {
                deny('issue-dev: the plan comment for issue '.issueLabel($state).' was already posted - the next marked comment is the done report (`<!-- '.DONE_MARKER.' -->`) once the implementation is finished.');
            }
            if ($hasPlan) {
                $blockers = [];
                if (empty($state['knowledge_searched'])) {
                    $blockers[] = 'search the knowledge base first (search_knowledge + get_knowledge)';
                }
                if (empty($state['code_explored'])) {
                    $blockers[] = 'explore the codebase first (read files / grep)';
                }
                if ($blockers !== []) {
                    deny('issue-dev: research before planning - '.implode('; ', $blockers).'.');
                }
            }
        }
    }
}

/**
 * post_tool progress recording — observed tool calls only, never claims, and
 * only on successful calls (a failed connector call proves nothing).
 *
 * @param array<string, mixed> $state
 * @param array<string, mixed> $payload
 */
function recordProgress(array &$state, string $tool, array $payload): bool
{
    $input = is_array($payload['tool_input'] ?? null) ? $payload['tool_input'] : [];
    if (($payload['tool_status'] ?? '') === 'failure') {
        return false;
    }

    $changed = false;
    if (str_contains($tool, 'typescript')) {
        $code = (string) ($input['code'] ?? '');
        if (str_contains($code, 'search_knowledge') && empty($state['knowledge_searched'])) {
            $state['knowledge_searched'] = true;
            $changed = true;
        }
        if ((str_contains($code, 'update_knowledge') || str_contains($code, 'create_knowledge')) && empty($state['knowledge_reviewed'])) {
            $state['knowledge_reviewed'] = true;
            $changed = true;
        }
        if (str_contains($code, 'add_comment') && str_contains($code, PLAN_MARKER) && empty($state['plan_comment'])) {
            $state['plan_comment'] = true;
            $changed = true;
        }
        if (str_contains($code, 'add_comment') && str_contains($code, DONE_MARKER) && ! empty($state['plan_comment']) && empty($state['done_comment'])) {
            $state['done_comment'] = true;
            $changed = true;
        }
        if (str_contains($code, 'log_time') && ! empty($state['done_comment']) && empty($state['time_logged'])) {
            $state['time_logged'] = true;
            $changed = true;
        }
    }

    if (str_contains($tool, 'bash') && ! isCliInvocation((string) ($input['command'] ?? ''))) {
        $command = (string) ($input['command'] ?? '');
        if (preg_match('/\b(grep|rg|find|artisan)\b/', $command) && empty($state['code_explored'])) {
            $state['code_explored'] = true;
            $changed = true;
        }
    }

    if (str_contains($tool, 'read')) {
        $path = inputPath($input);
        if ($path !== null && preg_match(SOURCE_FILE, $path) && empty($state['code_explored'])) {
            $state['code_explored'] = true;
            $changed = true;
        }
    }

    return $changed;
}

$argv = $_SERVER['argv'] ?? [];
if (count($argv) > 1) {
    exit(cliMain($argv));
}

$payload = payload();
$event = (string) ($payload['hook_event_name'] ?? '');
$tool = strtolower((string) ($payload['tool_name'] ?? ''));
$state = loadState();
$active = ! empty($state['active']);

if ($event === 'pre_tool') {
    if ($active) {
        preToolChecks($state, $tool, is_array($payload['tool_input'] ?? null) ? $payload['tool_input'] : []);
    }
    exit(0);
}

if ($event === 'post_tool') {
    if ($active) {
        if (recordProgress($state, $tool, $payload)) {
            saveState($state);
        }
        exit(0);
    }

    // Not in a workflow, yet the TaskR connector is being used: remind the
    // agent the lifecycle exists before it silently bypasses it.
    $input = is_array($payload['tool_input'] ?? null) ? $payload['tool_input'] : [];
    $code = (string) ($input['code'] ?? '');
    if (str_contains($tool, 'typescript') && str_contains($code, 'connector_taskr_34e2')) {
        addContext(implode("\n", [
            '-- issue-dev --',
            'The TaskR connector is in use with no declared issue workflow.',
            'If this task is a TaskR issue: run `php .vibe/hooks/issue-dev.php start <workspace_ulid> <issue_ulid>` and follow the taskr-issue-dev skill (research, plan comment, implement, done comment, time entry, knowledge review).',
            'If it is not (triage, admin, read-only lookup): ignore this reminder.',
            '----------------',
        ]));
    }
    exit(0);
}

if ($event === 'post_agent') {
    if (! $active) {
        exit(0);
    }
    $steps = missingSteps($state);
    if ($steps === []) {
        $state['active'] = false;
        $state['completed_at'] = time();
        saveState($state);
        exit(0);
    }
    deny('issue-dev-gate: the TaskR issue '.issueLabel($state).' workflow is incomplete:'.PHP_EOL.'- '.implode(PHP_EOL.'- ', $steps).PHP_EOL.'Finish them (see the taskr-issue-dev skill), or run `php .vibe/hooks/issue-dev.php cancel` if this is not a TaskR issue workflow.');
}

exit(0);
