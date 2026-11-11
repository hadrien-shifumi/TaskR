---
name: taskr-mcp
description: How to use the TaskR MCP server efficiently — auth, the workspace-first rule, the Workspace → Project → Issue/Knowledge hierarchy, ULID identifiers, roles and guest perimeters, read vs write tools, semantic knowledge search, and error shapes. Use whenever an agent must read or write TaskR through the taskr MCP tools.
---

# TaskR MCP — how to drive it well

The TaskR MCP server (`App\Mcp\Servers\TaskrServer`, served at `/mcp`, see `routes/ai.php`) exposes 53 tools + 1 resource. From an agent's point of view the client prefixes every tool with `taskr_` (e.g. the server tool `list_issues` arrives as `taskr_list_issues`). Everything below uses the server-side names.

## 1. Auth and the workspace-first rule

- The endpoint is **stateless**: each request authenticates with a Sanctum personal access token as a bearer token (`AuthenticateMcpAgent`). The agent acts as its owning user — there is no agent identity of its own.
- **`list_workspaces` first, always.** It returns the workspaces the user is a member of, with the role (`owner` / `member` / `guest`) in each. Then pass the target **`workspace_id` (ULID) to every other tool** — nearly all of them require it.
- Every tool re-resolves the workspace on each call (`ResolveAgentWorkspace`): you must be a member, otherwise `forbidden`. A foreign workspace id fails exactly like a non-existent one — **no existence leak**. The workspace context is flushed after every call (`AgentTool`), so never rely on state between calls.
- All ids are **ULIDs** (workspace, project, issue, status, label, knowledge page), except **user ids which are integers** (used for `assignee` filters and `assignees`). Dates: `YYYY-MM-DD` in, ISO 8601 out. Issue payloads use camelCase keys (`workspaceId`, `projectId`, `statusId`, `parentId`, `blockedByIds`, `assigneeIds`, `labelIds`, `estimatedHours` — see `App\Mcp\Support\IssuePresenter`).
- Never invent a ULID: discover ids with the `list_*` tools, and retype them exactly.

## 2. The hierarchy (from the models)

```
Workspace
├── members (Membership: role owner|member|guest) + invitations
├── statuses (Status: name, category, position)   ← categories: backlog, in_progress, done, cancelled
├── labels (Label: name, color)
├── templates (IssueTemplate: name, description, priority, labels)
├── projects (Project: name, handle, description, target_date, archived_at)
│   └── project members (ProjectMember: role owner|manager|member)
│   └── issues (Issue) ── comments, time entries, labels, assignees
│       ├── parent / children (Issue.parent_id, no cycles)
│       └── dependencies (issue_dependencies: blockedBy / blocks, no cycles)
└── knowledge pages (KnowledgePage: tree via parent_id, optional project_id, soft-deleted, versioned)
    └── linked to issues via issue_knowledge_pages
```

- **Multi-tenancy is enforced by a global scope** (`WorkspaceScope`): without a resolved workspace context, workspace-scoped queries return nothing (`whereRaw('0 = 1')`). You always see only the current `workspace_id`.
- **Guests** have a project perimeter (`WorkspaceContext::accessibleProjectIds`): out-of-perimeter objects are `not_found`, not `forbidden`, and guests never get global KPIs (`get_project_health` returns only `myWorkload`).
- **Roles**: workspace `owner` is super-manager of every project. Statuses, labels (create), templates, member management are owner-only. Comments and time entries can be updated/deleted **only by their author**.

## 3. Reading efficiently (cheap → precise)

Typical discovery flow — each step is cheap, so don't skip to the expensive one:

1. `list_workspaces` → pick `workspace_id`.
2. `list_projects` → project ULIDs (+ `handle`, mentionable per workspace). Archived projects are visible; guests see only their perimeter.
3. `list_statuses`, `list_labels` → discover filter values. Prefer filtering by **`category`** (`backlog|in_progress|done|cancelled`) over status ULIDs when you don't care which exact status.
4. `list_issues` — stays light. Filters: `project`, `status`, `category`, `priority` (`none|low|high|urgent`), `label`, `assignee` (user id), `overdue=true`, `blocked=true` (at least one open blocker), `archived` (`exclude` default | `include` | `only`), `limit` (1–100, default 25) + `offset`. Returns `issues` + `total`.
5. `get_issue` (`issue_id`) → the full specification: description, status, priority, due date, estimate, logged hours, labels, assignees, parent, children, blockers. Pair with `list_issues` to shortlist, then read the spec here.
6. `list_comments` (`issue_id`, oldest first) → the discussion.
7. Knowledge base:
   - `list_knowledge` → all pages (id, title, `parentId`, `projectId`); **rebuild the tree from `parentId`**. Pages with no project are transverse (workspace-wide).
   - `search_knowledge` → **semantic search, not keywords**: ask a natural-language question, use several different queries (domain terms, entities, "how was X done before"), optionally scope with `project_id`, `limit` 1–50 (default 10). Results are ranked by cosine similarity (`score`, plus `title` and `excerpt`). Pages are vectorized asynchronously on create/update (`embedding` column; pgvector `<=>` in prod, exact cosine in PHP on sqlite). If embeddings are not configured you get an `embeddings_not_configured` error.
   - `get_knowledge` (`page_id`) → full markdown body, parent, project, last editor.
8. `get_project_health` (`project_id`) → completion %, overdue, time consumption, projection.
9. `list_activity` → append-only audit journal, newest first; pass **exactly one** of `issue_id` / `project_id`.
10. Resource: `taskr://workspaces/{workspace_id}/projects/{project_id}/issues` — the 25 newest issues of a project.

## 4. Writing

| Domain | Tools | Notes |
|---|---|---|
| Workspace | `create_workspace` (default statuses auto-created; `with_agent_labels=true` seeds `agent-ready`/`agent-failed`), `invite_member` (owner, 7-day expiry), `remove_member` (owner, cannot remove self), `list_members` (full members only) | |
| Projects | `create_project`, `update_project`, `archive_project` (toggle), `delete_project` (**fails if issues remain — archive instead**) | |
| Issues | `create_issue` (`project_id` + `title` required; optional `description`, `status_id` (default: first status), `priority`, `due_date`, `assignees`, `label_ids`, `template` ULID to pre-fill), `update_issue` (title/description/priority/due date), `move_issue` (`status_id`), `delete_issue` | |
| Issue structure | `set_issue_parent` (same workspace, no cycle; null clears), `set_issue_dependencies` (**replaces** the blocker list — empty array clears; same workspace, cycles refused), `set_labels` / `set_assignees` (**replace** — empty array clears), `set_issue_estimate` (hours, null clears) | |
| Discussion | `add_comment`, `list_comments`, `update_comment`, `delete_comment` (author only). Comments on the workflow issue must carry an agent signature; the `<!-- taskr:plan -->` / `<!-- taskr:done -->` markers advance the workflow state — see the `taskr-issue-dev` skill | |
| Time | `log_time` (`hours` ≥ 0.01, `worked_at` default today, optional `note`; author = authenticated user), `list_time_entries`, `update_time_entry` / `delete_time_entry` (author only) | |
| Statuses | `list_statuses`, `create_status` (owner; `category` required), `update_status`, `delete_status` (fails if used by issues), `reorder_statuses` (owner; full ordered id list) | |
| Labels & templates | `list_labels`, `create_label` (name unique per workspace, full members only); `list_templates`, `create_template` / `update_template` / `delete_template` (owner only) | |
| Knowledge | `create_knowledge` (markdown body, optional `parent_id` + `project_id`; vectorized async), `update_knowledge` (**partial** — omitted `parent_id`/`project_id` keep their current value; explicit `null` detaches; re-vectorizes, same rules as UI) `delete_knowledge` (**soft delete**, restorable 30 days, fails if the page has children), `list_knowledge_trash`, `restore_knowledge`, `force_delete_knowledge` (irreversible), `list_knowledge_versions`, `compare_knowledge_versions`, `restore_knowledge_version` (creates a NEW version capturing the restore) | |
| Audit | `list_activity` — read-only, there is no event mutation tool | |

## 5. Error shapes (never a crashed session)

Every tool wraps execution in one envelope (`AgentTool::handle`): errors come back as `isError` tool results with structured content, never as exceptions:

- `validation_failed` + `fields` — the offending fields and messages; fix the input and retry.
- `forbidden` — not a member of the workspace, or role too low (owner-only, author-only, guest...).
- `not_found` — missing **or out-of-perimeter** resource (guests, foreign workspace): indistinguishable on purpose.
- `business_rule` — domain rule refused (dependency cycle, delete non-empty project/status, trash a page with children...).
- `embeddings_not_configured` — `search_knowledge` unavailable; fall back to `list_knowledge` + `get_knowledge`.

## 6. Agent habits that save round trips

- Resolve `workspace_id` once per task, reuse it everywhere; don't call `list_workspaces` again mid-task unless unsure.
- Read wide, then deep: `list_issues` filters → `get_issue` on candidates; `search_knowledge` with 2–4 varied NL queries → `get_knowledge` on hits.
- Use `category` instead of `status` ULIDs, `blocked=true` / `overdue=true` instead of manual date math.
- All `set_*` tools **replace** the collection — send the full desired list, not a delta.
- Archived issues are hidden by default (`archived=exclude`); same for projects in the UI — check `archived` / `archived_at` explicitly when relevant.
- Pagination: `limit` + `offset`, and read `total` to know when to stop.
- Writes are audited: `list_activity` on the issue/project shows what happened, by whom, when.
- For the issue workflow lifecycle (research → plan comment → implement → done comment → time entry → knowledge review), follow the `taskr-issue-dev` skill — it drives the same tools plus the `issue_dev` tool.
