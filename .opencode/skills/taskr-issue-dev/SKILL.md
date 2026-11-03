---
name: taskr-issue-dev
description: Mandatory lifecycle for every TaskR issue — research the ticket in the knowledge base and the code before implementing, post a plan comment before coding, post a completion comment when done, log the time spent from the issue's first comment to now, and reconcile the knowledge base. The plugin also drives the issue statuses (In Progress on start, Done at closure, parent/child coherence). Enforced by the issue-dev plugin. Use whenever a task is a TaskR issue.
---

# TaskR Issue Dev — research → plan → implement → report → log → knowledge

Every TaskR ticket follows this lifecycle, in this order. The `issue-dev` plugin (`.opencode/plugins/issue-dev/`) enforces it: the `issue_dev` tool is both the state machine the agent drives and the enforcement layer. The gate refuses to end a turn while a declared issue workflow is incomplete. Never claim a step was done without having actually performed it — the plugin tracks observed tool calls, not claims.

## Declaring the workflow

Use the `issue_dev` tool to manage the workflow state:

```
issue_dev(action="start", workspaceId="<workspace_ulid>", issueId="<issue_ulid>")
issue_dev(action="status")
issue_dev(action="knowledge-reviewed")
issue_dev(action="cancel")
```

Run `start` as soon as a task is identified as a TaskR issue — before any research or code. Get the ULIDs from the ticket link, or discover them with `list_workspaces` then `list_issues` (filter by project/status/assignee). If the task turns out not to be an issue workflow after all (triage, admin work, pure reading), run `cancel` — the gate then stands down.

## Phase 1 — Research (before any code)

Study the issue from every angle. The plan comment is blocked until the plugin has observed both halves of this phase:

1. The issue itself: title, description, status, labels, estimate, existing comments (`list_issues` filters, `list_comments`).
2. Knowledge base: `search_knowledge` with several natural-language queries — the feature's domain terms, the entities involved, similar past work — then `get_knowledge` on the relevant hits. Scope with `project_id` when the issue belongs to a project.
3. Codebase: locate the actions, models, pages and tests concerned (read files, grep, `php artisan route:list`). Use the `taskr-backend` / `taskr-frontend` skills for structure expectations.

## Phase 2 — Plan comment (before any code)

Before writing any code, post the plan with `add_comment`. The body must start with the marker line `<!-- taskr:plan -->` — comments without a marker are refused — and state:

- the issue restated in a sentence or two (proves it was read),
- findings from the knowledge base and the code (page titles, file paths),
- what will be developed: the chosen approach and the files to create or change,
- the test plan (numbered `test_...` cases, per the TDD skill).

The plugin denies edits on source files (`app/`, `routes/`, `database/`, `config/`, `resources/js/`, `resources/views/`, `tests/`) until this comment is posted.

Draft the plan in `.opencode/scratch/plan-<issueId>.md` and post it from that file — the plugin mirrors the posted body back to the same path, so the file stays the canonical re-readable copy (see Persisted context in scratch files).

## Phase 3 — Implementation

Standard TDD loop (see the `tdd` skill): red, green, refactor, then the E2E final gate. The issue-dev plugin lets edits through from here; the tdd plugin takes over the feedback loop.

## Phase 4 — Done comment

When the implementation is green and the E2E gate has run, post the report with `add_comment`. The body must start with `<!-- taskr:done -->` and cover:

- what was realized, mapped against the plan,
- tests added or updated, and their final state,
- files changed,
- deviations from the plan and why.

The marker matters: it is what the plugin uses to tell this comment from any other.

Draft the report in `.opencode/scratch/done-<issueId>.md` and post it from that file — the plugin mirrors the posted body back to the same path.

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

The plugin blocks `log_time` before the done comment: the duration must cover the whole development, first comment to now.

## Phase 6 — Knowledge reconciliation

Compare what was learned and changed against the knowledge base, and keep it truthful for the next agent:

- Re-run `search_knowledge` on the areas touched by the issue.
- Update stale pages (`update_knowledge`) or create missing ones (`create_knowledge`) — conventions, gotchas, architecture facts that a future session must know. Any `update_knowledge` / `create_knowledge` call satisfies this step.
- If nothing deserves an update, attest it: `issue_dev(action="knowledge-reviewed")`, and justify it in the done comment ("Knowledge: reviewed X and Y, still accurate").

## Persisted context in scratch files

Subagents are dispatched with a fresh context every time: the shared context lives in files under `.opencode/scratch/` (git-ignored), never inlined in briefs. These files are caches — the source of truth stays TaskR and the code.

The issue-dev plugin maintains two kinds of them:

- **Epic dossier** — on `issue_dev action="start"`, when the issue has a parent, the plugin scaffolds `.opencode/scratch/epic-<epicId>/` and records `epic_id` in the workflow state. It creates three stubs, never overwriting existing files, so the dossier is filled once and survives the epic's whole run:
  - `kb.md` — spec, arbitrages, ULIDs, gotchas. Filled by `reader` (via the orchestrator) on the epic's first ticket.
  - `patterns.md` — observed signatures as `fichier:ligne`, test and factory patterns. Filled by `scout`. Line numbers drift: regenerate the file on every domain change — never trust stale references.
  - `review-checklist.md` — invariants to check on every ticket of the epic (tenancy, ULIDs, scoped validation…). Filled by `reviewer`.
  
  An issue without a parent gets no dossier; an unreachable TaskR skips the scaffold without ever failing the start.
- **Plan/done mirrors** — as soon as a marked comment is posted on the workflow issue, the plugin writes the exact body to `.opencode/scratch/plan-<issueId>.md` (marker `<!-- taskr:plan -->`) or `.opencode/scratch/done-<issueId>.md` (marker `<!-- taskr:done -->`). Draft the comment in that file and post it from there — the mirror then guarantees the file matches what TaskR holds. Any subagent can re-read the plan afterwards without TaskR access.

Working conventions:

- Briefs reference these files ("lis d'abord `.opencode/scratch/epic-<id>/kb.md` et `patterns.md`") and stay limited to the ticket's delta — re-inlining shared context wastes the context window and corrupts long bodies.
- Delete `.opencode/scratch/epic-<id>/` when the epic closes; the plan/done mirrors are per-issue and can be kept as history.

## TaskR statuses (driven by the plugin)

The plugin keeps the issue statuses coherent with the workflow, automatically and best effort over the TaskR MCP server:

- **Start → In Progress**: `issue_dev action="start"` moves the issue to the workspace's In Progress status. If its parent is still Backlog, the parent is moved to In Progress too — a parent never stays in Backlog while a child is in progress. An issue already In Progress is left untouched.
- **Closure → Done**: when every step is complete, the gate moves the issue to Done. If some children are not Done, the issue is left where it is — the gate names the blocking children once, then closes the workflow; finish the children, then `move_issue` the parent to Done.
- **`move_issue` guard**: moving a parent to Done while a child is open (not Done, not Cancelled) is refused with the blocking children listed. Moving an issue to In Progress or Done lifts a Backlog parent to In Progress.
- Done and Cancelled both close a child: a cancelled child no longer blocks its parent.
- When TaskR is unreachable, nothing is blocked and no status is changed — the workflow proceeds normally and the outage is reported in the tool message.

Do not fight these transitions: never move the workflow issue back to Backlog, and close the children before closing a parent.

## Gate behavior

The post-agent gate denies ending a turn while steps are missing, listing exactly what remains — research (knowledge + code), plan comment, done comment, time entry, knowledge review. Finish them, or `cancel` if this is not a TaskR issue workflow. When every step is complete the gate also moves the issue to Done (see TaskR statuses above).

## Tool reference

| Tool | Purpose |
| --- | --- |
| `issue_dev` | Manage the workflow state machine (start, status, knowledge-reviewed, cancel) |
| `list_workspaces` | Discover workspace ids — always the first call |
| `list_projects` | Find the project tracking this repository |
| `list_statuses` | Resolve status ids by category |
| `list_labels` | Discover label ids |
| `list_issues` | Find work — filters: `project`, `status`, `category`, `priority`, `label`, `assignee`, `overdue`, `limit`, `offset` |
| `create_issue` | New issue (parent first, children after) |
| `set_issue_parent` | Link a child to its parent (`parent_id: null` detaches) |
| `move_issue` | Change the status — the only way a status evolves; the plugin refuses a parent → Done while a child is open and lifts a Backlog parent when a child moves forward |
| `update_issue` | Title, description, priority, due date |
| `set_labels` / `set_assignees` | Labels and assignees |
| `add_comment` / `list_comments` | Progress notes |
| `log_time` / `list_time_entries` | Time tracking |
| `search_knowledge` / `get_knowledge` | Knowledge base search |
| `update_knowledge` / `create_knowledge` | Knowledge base updates |

## Limits

- This skill guides; the plugin enforces. The plugin blocks out-of-order steps; it does not block legitimate work.
- Do not start an issue workflow for read-only exploration (reading code, answering questions) — only when you are about to change code on a TaskR issue.
