---
name: tdd
description: Mandatory test-first workflow — plan the tests before coding, prove them red, implement in passes until Unit+Feature are green, and treat the Browser (E2E) suite as a final gate only. Use for every feature, bug fix, or behavior change, backend or frontend.
---

# TDD — Red → Green → Refactor

Every behavior change goes through this loop, no exception. The `tdd` plugin (`.opencode/plugins/tdd/`) makes it real:

- After each edit on `tests/` or source code, the plugin runs the covering PHPUnit scope and appends a verdict block to the tool result.
- The plugin blocks E2E runs (`composer test:e2e`, Browser suite) while the dev loop is red or stale — E2E is the final gate, never part of the dev loop.
- The plugin refuses to end a turn while the suites are red or a run is pending.
- It also watches manual test runs through bash and records their outcome.

Never claim a test state you have not just seen in a tdd-guard block or a fresh run.

## Rules

1. Tests are derived from the spec (or the bug report), never from the implementation you are about to write.
2. Red comes first: a test that passes the moment it is written proves nothing.
3. When a test fails, fix the code. Edit the test only when the test itself is wrong — and say so explicitly.
4. The Browser (E2E) suite is never part of the dev loop. It runs once, at the final gate.
5. Done means: Unit+Feature green, observed in a tdd-guard block or a fresh `composer test`, **and** the final E2E gate green.

## Loop

### 1. Spec → test plan

Before writing any code:

- Restate the spec as a numbered list of test cases (`test_...` names) — one per behavior or acceptance criterion, including edge cases and rejections. Write the list in the conversation first.
- For workspace-scoped resources, apply the required coverage checklist from `taskr-backend` (minimal create, full create, cross-workspace isolation, validation rejection, guarded delete, list filtering).
- Track the loop phases in the todo list so progress stays visible.

### 2. Red

- Create the test file with `php artisan make:test --phpunit {Name}Test`. Use factories, never manual model creation for domain entities; read the global `testing-best-practices` skill before designing tests.
- Write the tests, then let the edit trigger the tdd plugin:
  - `🔴 RED — <file>` — correct state. Note which assertions fail; do not write more tests than the current step needs.
  - `🟢 GREEN` on a brand-new file — stop: the behavior already exists or the test asserts nothing. Strengthen the test until it is red.
  - `⛔ ERROR` — syntax or runtime error; fix it before judging red/green.

### 3. Green

- Write the minimum implementation that makes the failing tests pass (follow the `taskr-backend` / `taskr-frontend` skills for structure).
- Each implementation edit re-triggers the tdd plugin on that scope. Repeat passes until the plugin reports `🟢 Unit+Feature GREEN`. If it reports red, keep iterating — the task is not done while it is red.

### 4. Refactor

- Clean up code and tests while staying green. Every refactor edit re-runs the suite — keep it green at each step.

### 5. Final gate (E2E)

Once development is complete and Unit+Feature are green:

1. Write the new Browser tests derived from the spec's acceptance criteria — not from the implementation you just wrote. They should pass immediately; if they do not, either the feature or the test is wrong.
2. Run the E2E gate once: `composer test:e2e` (builds the frontend, runs the whole Browser suite). The plugin allows this run only when the dev loop is green. This single run validates both non-regression and the new coverage — do not run the full suite twice.
3. If an existing E2E test fails, it is a spec conflict: fix the code, or escalate to the user — never delete or weaken the test to pass.

Optional mid-loop checkpoint: when a change touches critical page flows and you need earlier confidence, run a cheap smoke subset (`php artisan test --compact tests/Browser/SmokeTest.php`) — never the whole Browser suite.

## Bug fixes

Reproduce first: write the failing test that captures the bug, watch it go RED, then fix. A bug fix without a red test first is a guess.

## Reading tdd-guard output

- Test-file edit (`tests/**/*Test.php`) → runs that file only.
- Source edit (`app/`, `routes/`, `database/`, `config/`, `resources/js`, `resources/views/`) or tests support file → runs the Unit+Feature suites.
- `⚠️ the Unit+Feature suites were RED` on a test edit → you are editing tests while the dev suites are red: justify it, or fix the code instead.
- `🌐 Browser (E2E) file` → E2E test files are excluded from the dev loop by design.

## When the tdd turn gate blocks you

- `Unit+Feature are RED` → keep implementing, rerun `composer test`.
- `tests were written and are RED` → do the implementation pass; only stop at red if the user explicitly asked for the test plan alone — then say so.
- `edited since the last test run` → rerun the covering scope before finishing.

## When the E2E gate blocks you

The plugin refuses `composer test:e2e` while Unit+Feature are red or stale. Bring `composer test` to green first, then run `composer test:e2e` once.
