---
name: taskr-issue-dev
description: Mandatory lifecycle for every TaskR issue — scout research (short functional comment on the issue, technical analysis in the session), plan comment before code, done comment, time entry, knowledge review. TaskR comments are signed and readable by non-tech; the issue-dev plugin enforces the order. Use whenever a task is a TaskR issue.
---

# TaskR Issue Dev — scout → plan → implement → done → log → knowledge

Every TaskR ticket follows this lifecycle, in this order. The `issue-dev` plugin enforces it: source edits are blocked until the plan comment exists, `log_time` is blocked until the done comment exists, comments on the workflow issue must carry an agent signature, and the gate refuses to end the owner's turn while a step is missing. The plugin tracks observed tool calls, not claims.

## Roles

- **Orchestrator** runs `issue_dev`, creates and moves tickets, dispatches the agents, verifies the diff, attests the knowledge review when nothing deserves an update.
- **Scout** (local model, free) does the research: one short functional comment on the issue, the full technical analysis in its report to the orchestrator.
- **Dev / expert** implement and post their own plan, done, time and knowledge updates.

Every comment on the workflow issue carries its author signature — `[scout]`, `[dev]`, `[expert]` or `[orchestrator]` — anywhere in the body, case-insensitive (`**[Scout]**` and `scout:` also count). The plugin refuses unsigned comments; a signed comment without a plan/done marker is ordinary tracker work (research summary, notes, questions) and never changes the workflow state.

## Declaring the workflow

```
issue_dev(action="start", workspaceId="<workspace_ulid>", issueId="<issue_ulid>")
issue_dev(action="status")
issue_dev(action="knowledge-reviewed")
issue_dev(action="cancel")
```

Run `start` as soon as a task is identified as a TaskR issue — it moves the issue to In Progress. Get the ULIDs from the ticket link, or discover them with `list_workspaces` then `list_issues`. If the task is not an issue workflow after all (triage, reading, admin), run `cancel`.

## Phase 1 — Research (scout)

One dispatch: the scout reads the issue and its comments, searches the knowledge base (several natural-language queries) and locates the concerned code. It posts ONE short comment on the issue, signed `[scout]`, functional and readable by non-tech: the goal as understood, the acceptance criteria, the points of attention and open questions — no file paths, no class names, no jargon. The technical analysis (`path:line` references, relevant knowledge pages, contradictions with the brief) goes in its session report to the orchestrator, who relays it in the implementing agent's brief.

## Phase 2 — Plan comment (dev/expert)

Before any code, the implementing agent posts the plan with `add_comment`. The body must contain the marker `<!-- taskr:plan -->` (it advances the workflow state) and the agent signature. The comment is functional, readable by non-tech, and states:

- the issue restated in a sentence or two,
- the chosen approach and why, in business terms,
- the observable result expected once it is done.

The technical detail — files to change, numbered test plan (`test_...` cases, per the TDD skill), technical deviations — stays in the opencode session and feeds the orchestrator's verification.

The plugin denies edits on source files (`app/`, `routes/`, `database/`, `config/`, `resources/js/`, `resources/views/`, `tests/`) until this comment is posted.

## Phase 3 — Implementation

Standard TDD loop (see the `tdd` skill): red, green, refactor; the E2E suite is a final gate only. The orchestrator verifies the diff and the test run itself before accepting the report.

## Phase 4 — Done comment (dev/expert)

`add_comment` containing `<!-- taskr:done -->`, signed: what was realized mapped against the plan, in observable business terms — what works now that did not before, how the tests validate it (plain words, no test names), the deviations and why. Files changed, commands and technical detail go in the session report; the orchestrator verifies the diff itself.

## Phase 5 — Time entry (dev/expert)

1. `list_comments` on the issue; the first comment's `created_at` is the reference.
2. `hours = (now − created_at) / 3600`, rounded to two decimals, minimum 0.01.
3. `log_time` with `hours`, `worked_at` = today, a short note naming the work.

The plugin blocks `log_time` before the done comment.

## Phase 6 — Knowledge reconciliation (dev/expert, or orchestrator)

Re-run `search_knowledge` on the touched areas; `update_knowledge` / `create_knowledge` for what the next agent must know — any such call satisfies this step. If nothing deserves an update, say so in the done comment: the orchestrator attests it with `issue_dev(action="knowledge-reviewed")`.

## TaskR statuses (driven by the plugin)

- **Start → In Progress**, automatically and best effort.
- **Closure → Done** when every step is complete. Best effort: an unreachable TaskR never blocks the workflow, the sync is simply skipped.

Do not fight these transitions and do not move the workflow issue back to Backlog.

## Limits

- This skill guides; the plugin enforces.
- Do not start an issue workflow for read-only exploration — only when code is about to change on a TaskR issue.
- One active workflow per repository at a time; concurrent sessions share it.
