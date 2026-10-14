---
name: TDD
description: Mandatory test-first workflow for taskr — derive the test plan from the specs, prove red before implementing, finish only when Unit+Feature are green and the Browser (E2E) suite passes at the final gate. Use for every feature, bug fix, or behavior change, backend or frontend.
---

# TDD — Red → Green → Refactor

Every behavior change goes through this loop, no exception. The `tdd-guard` plugin (`.opencode/plugins/tdd/`) runs PHPUnit automatically after each `edit`/`write` and appends a status block to the tool result. That status drives the loop — never claim a test state you have not just seen in a tdd-guard block or a fresh run.

## Rules

1. Tests are derived from the spec, never from the implementation you are about to write.
2. Red comes first: a test that passes the moment it is written proves nothing.
3. When a test fails, fix the code. Edit the test only when the test itself is wrong — and say so explicitly.
4. Done means: Unit+Feature green, observed in the latest tdd-guard status or a fresh run, **and** the Browser (E2E) suite green at the final gate.

## Loop

### 1. Spec → test plan

Before writing any code:

- Restate the spec as a numbered list of test cases (`test_...` names) — one per behavior or acceptance criterion, including edge cases and rejections. Write the list in the conversation first.
- For workspace-scoped resources, apply the required coverage checklist from `.opencode/skills/taskr-backend/references/testing.md` (minimal create, full create, cross-workspace isolation, validation rejection, guarded delete, list filtering).

### 2. Red

- Create the test file with `php artisan make:test --phpunit {Name}Test`. Use factories, never manual model creation for domain entities; read the global `testing-best-practices` skill before designing tests.
- Write the tests, then let the edit trigger tdd-guard:
  - `🔴 RED` — correct state. Note which assertions fail; do not write more tests than the current step needs.
  - `🟢 GREEN` on a brand-new file — stop: the behavior already exists or the test asserts nothing. Strengthen the test until it is red.
  - `⛔ ERROR` — syntax or runtime error; fix it before judging red/green.

### 3. Green

- Write the minimum implementation that makes the failing test pass (follow the `taskr-backend` / `taskr-frontend` skills for structure).
- Each implementation edit re-triggers tdd-guard on that scope. Repeat until green.

### 4. Refactor

- Clean up code and tests while staying green. Every refactor edit re-runs the suite — keep it green at each step.

### 5. Final gate

- The last tdd-guard status on a source edit must be `🟢 Unit+Feature GREEN`. If it is red, the task is not done. Never report success on an unverified suite.
- Then, once development is complete, run the E2E gate: `composer test:e2e` (builds the frontend, runs the Browser suite). Both gates must be green before reporting done. If E2E fails, fix the code — not the E2E test — unless the test itself is wrong.

## Bug fixes

Reproduce first: write the failing test that captures the bug, watch it go 🔴, then fix. A bug fix without a red test first is a guess.

## Reading tdd-guard output

- Test-file edit (`tests/**/*Test.php`) → runs that file only.
- Source edit (`app/`, `routes/`, `database/`, `config/`, `resources/js`, `resources/views/`) or tests support file → runs the Unit+Feature suites (~2 s). The Browser suite (E2E) is never run by tdd-guard on source edits — too slow for the loop; it only runs at the final gate via `composer test:e2e`.
- `⚠️ the Unit+Feature suites were RED` on a test edit → you are editing tests while the dev suites are red: justify it, or fix the code instead.
