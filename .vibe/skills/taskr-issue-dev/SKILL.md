---
name: taskr-issue-dev
description: Mandatory lifecycle for every TaskR issue — research the ticket in the knowledge base and the code before implementing, post a plan comment before coding, post a completion comment when done, log the time spent from the issue's first comment to now, and reconcile the knowledge base. Enforced by the issue-dev hooks. Use whenever a task is a TaskR issue.
---

# TaskR Issue Dev — research → plan → implement → report → log → knowledge

Every TaskR ticket follows this lifecycle, in this order. The `.vibe/hooks.toml` `issue-dev` hooks make it real: `php .vibe/hooks/issue-dev.php` is both the state machine the agent drives (CLI mode) and the enforcement hook (Vibe mode). The gate refuses to end a turn while a declared issue workflow is incomplete. Never claim a step was done without having actually performed it — the hook tracks observed tool calls, not claims.

## Declaring the workflow

Run these from the repo root:

```
php .vibe/hooks/issue-dev.php start <workspace_ulid> <issue_ulid>
php .vibe/hooks/issue-dev.php status
php .vibe/hooks/issue-dev.php knowledge-reviewed
php .vibe/hooks/issue-dev.php cancel
```

Run `start` as soon as a task is identified as a TaskR issue — before any research or code. Get the ULIDs from the ticket link, or discover them with `list_workspaces` then `list_issues` (filter by project/status/assignee). If the task turns out not to be an issue workflow after all (triage, admin work, pure reading), run `cancel` — the gate then stands down.

## Phase 1 — Research (before any code)

Study the issue from every angle. The plan comment is blocked until the hook has observed both halves of this phase:

1. The issue itself: title, description, status, labels, estimate, existing comments (`list_issues` filters, `list_comments`).
2. Knowledge base: `search_knowledge` with several natural-language queries — the feature's domain terms, the entities involved, similar past work — then `get_knowledge` on the relevant hits. Scope with `project_id` when the issue belongs to a project.
3. Codebase: locate the actions, models, pages and tests concerned (read files, grep, `php artisan route:list`). Use the `taskr-backend` / `taskr-frontend` skills for structure expectations.

## Phase 2 — Plan comment (before any code)

Before writing any code, post the plan with `add_comment`. The body must start with the marker line `<!-- taskr:plan -->` — comments without a marker are refused — and state:

- the issue restated in a sentence or two (proves it was read),
- findings from the knowledge base and the code (page titles, file paths),
- what will be developed: the chosen approach and the files to create or change,
- the test plan (numbered `test_...` cases, per the TDD skill).

The hook denies edits on source files (`app/`, `routes/`, `database/`, `config/`, `resources/js/`, `resources/views/`, `tests/`) until this comment is posted.

## Phase 3 — Implementation

Standard TDD loop (see the `tdd` skill): red, green, refactor, then the E2E final gate. The issue-dev hook lets edits through from here; tdd-guard takes over the feedback loop.

## Phase 4 — Done comment

When the implementation is green and the E2E gate has run, post the report with `add_comment`. The body must start with `<!-- taskr:done -->` and cover:

- what was realized, mapped against the plan,
- tests added or updated, and their final state,
- files changed,
- deviations from the plan and why.

The marker matters: it is what the hook uses to tell this comment from any other.

## Phase 5 — Time entry

1. `list_comments` on the issue (oldest first); the first comment's `created_at` is the reference — with this workflow it is the plan comment.
2. Compute `hours = (now − created_at) / 3600`, rounded to two decimals, minimum 0.01 (the API floor).
3. `log_time` with `hours`, `worked_at` = today, and a short note naming the work.

Sketch:

```ts
const comments = await tools.connector_taskr_34e2.list_comments({ workspace_id, issue_id });
const startedAt = new Date(comments[0].created_at); // adapt to the actual returned shape
const hours = Math.max(0.01, Math.round(((Date.now() - startedAt.getTime()) / 3_600_000) * 100) / 100);
await tools.connector_taskr_34e2.log_time({
  workspace_id,
  issue_id,
  hours,
  worked_at: new Date().toISOString().slice(0, 10),
  note: 'Development of <issue title>',
});
```

The hook blocks `log_time` before the done comment: the duration must cover the whole development, first comment to now.

## Phase 6 — Knowledge reconciliation

Compare what was learned and changed against the knowledge base, and keep it truthful for the next agent:

- Re-run `search_knowledge` on the areas touched by the issue.
- Update stale pages (`update_knowledge`) or create missing ones (`create_knowledge`) — conventions, gotchas, architecture facts that a future session must know. Any `update_knowledge` / `create_knowledge` call satisfies this step.
- If nothing deserves an update, attest it: `php .vibe/hooks/issue-dev.php knowledge-reviewed`, and justify it in the done comment ("Knowledge: reviewed X and Y, still accurate").

## Gate behavior

The post-agent gate denies ending a turn while steps are missing, listing exactly what remains — research (knowledge + code), plan comment, done comment, time entry, knowledge review. Finish them, or `cancel` if this is not a TaskR issue workflow. Denials are capped at 3 per hook per turn and then degrade to a warning — never rely on that.
