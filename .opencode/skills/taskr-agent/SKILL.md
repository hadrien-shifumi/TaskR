---
name: Taskr Agent
description: Track all development work in Taskr through the taskr MCP tools — create a parent issue (with child issues) before writing code, move the issue to an in_progress status when starting to work on it, comment progress, and move it to done only when the Definition of Done is met. Use when the user asks for a feature or a bug fix on this project, or whenever you are about to modify code.
---

# Taskr Agent

Every code change on this project is tracked in Taskr through the `taskr` MCP server. This skill defines when to create issues, how to structure parent/child issues, and how an issue's status evolves with the work. The `taskr-tracker` plugin (`.opencode/plugins/taskr-tracker/`) appends a reminder to source edits made without an issue in progress.

## Hard rules

1. **Never invent an identifier.** Call `list_workspaces` first, then `list_projects`, `list_statuses` and `list_labels`. Tool answers are structured JSON with camelCase keys (`id`, `statusId`, `parentId`, `title`, …) — always read IDs from them.
2. **Address statuses by category, never by name or id.** `list_statuses` returns each status with a `category`: `backlog`, `in_progress`, `done` or `cancelled`. Starting work means moving to a status of category `in_progress`; finishing means `done`; abandoning means `cancelled`. Workspaces can rename or add statuses (an `In Review` status still has category `in_progress`), so never hardcode a status ULID.
3. **One workspace, one project.** This repository is tracked in the Taskr project matching its name in `list_projects`; if several match, ask the user.

## Workflow

### 1. New feature or bug — before writing code

1. Create the **parent issue**: `create_issue(workspace_id, project_id, title, description)` — the description carries the spec (what, why, acceptance criteria). Set `priority` (`none`/`low`/`high`/`urgent`) when obvious.
2. If the work splits into steps, create one **child issue** per step and link it: `create_issue(...)`, then `set_issue_parent(workspace_id, issue_id=<child>, parent_id=<parent>)`. `create_issue` has no `parent_id` parameter — linking is always a second call.
3. Report the created issues (titles + ids) to the user.

New issues land in the workspace's first status (backlog) by default — that is the expected starting point.

### 2. Starting work on an issue

As soon as you begin working on an issue:

1. `move_issue(workspace_id, issue_id, status_id)` to a status of category `in_progress`.
2. `add_comment(workspace_id, issue_id, body)` — one line: what you are starting, and the plan.

### 3. While working

- `add_comment` at meaningful milestones and on blockers (what, why, next step).
- The **TDD** skill applies in full: red → green → refactor, E2E gate at the end.
- Scope changes: `update_issue` (title/description/priority/due date) or split new child issues.

### 4. Finishing

Move the issue to a status of category `done` **only** when the Definition of Done is met:

- Unit + Feature suites green (tdd-guard status or `php artisan test`)
- Browser (E2E) suite green at the final gate (`composer test:e2e`) for behavior changes
- The diff has been re-read

Then `move_issue` to `done` and `add_comment` a closing summary: what changed, which files, test results, remaining risks. The user reviews and can reopen the issue.

If the Definition of Done is not met when the session ends: **stay in in_progress** and comment what remains. Never mark an unfinished issue done.

### 5. Abandoning

`move_issue` to a status of category `cancelled` + `add_comment` explaining why.

### 6. Resuming a session

`list_issues(workspace_id, category=in_progress)` (or `category=backlog`) to find the open work; pick it up where it stopped.

## Tool reference

| Tool | Purpose |
| --- | --- |
| `list_workspaces` | Discover workspace ids — always the first call |
| `list_projects` | Find the project tracking this repository |
| `list_statuses` | Resolve status ids by category |
| `list_labels` | Discover label ids |
| `list_issues` | Find work — filters: `project`, `status`, `category`, `priority`, `label`, `assignee`, `overdue`, `limit`, `offset` |
| `create_issue` | New issue (parent first, children after) |
| `set_issue_parent` | Link a child to its parent (`parent_id: null` detaches) |
| `move_issue` | Change the status — the only way a status evolves |
| `update_issue` | Title, description, priority, due date |
| `set_labels` / `set_assignees` | Labels and assignees |
| `add_comment` / `list_comments` | Progress notes |
| `log_time` / `list_time_entries` | Time tracking |

## Limits

- This skill guides; it does not block. The plugin reminds; it does not block either.
- Do not create issues for read-only exploration (reading code, answering questions) — only when you are about to change code.
