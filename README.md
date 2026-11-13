<p align="center"><img src="public/assets/taskr-lockup.svg" width="220" alt="TaskR"></p>

# TaskR

**TaskR** is a minimalist ticket tracker for teams who want to track issues, time and project health — without agile ceremony, issue types or configurable workflows. Just workspaces, projects and issues.

It's built to be driven **by humans through the web UI and by AI agents through a built-in MCP server**, with the same rules and permissions on both sides.

## ✨ What you get

- **Workspaces & projects** — multi-tenant, invite teammates by email, strict data isolation. Each project tracks progress, overdue issues and hours logged vs. estimated, with an `on track` / `at risk` / `late` projection.
- **Issues your way** — Markdown descriptions, priorities, free-form labels, multiple assignees, parent/child hierarchy, dependencies between issues, and statuses you define yourself (no imposed workflow).
- **Comments & notifications** — @mentions, a mentions inbox, and an optional email digest.
- **Time tracking** — estimates and time entries, right on the issue.
- **Knowledge base** — Markdown pages with history, version comparison and restore, plus semantic search powered by any OpenAI-compatible embeddings endpoint.
- **API & MCP** — personal access tokens and a stateless MCP server (~50 tools) so an AI agent can manage your workspace just like a human would.
- **French & English**, switchable per user.

## 🚀 Try it in a few minutes

```bash
git clone https://github.com/hadrien-shifumi/TaskR.git && cd TaskR
cp .env.example .env   # set APP_KEY, e.g. `openssl rand -base64 32`
docker compose up -d
```

Open http://localhost:8080 — that's it, PostgreSQL and the app are both running.

Prefer a classic PHP setup instead of Docker, or want to hack on the code?
See [`docs/installation_EN.md`](docs/installation_EN.md) (or [`_FR`](docs/installation_FR.md)) for local development, or
[`docs/self-hosting_EN.md`](docs/self-hosting_EN.md) (or [`_FR`](docs/self-hosting_FR.md)) for a production deployment
(Docker Compose, `composer create-project`, updates, external database…).

## Tech stack

| Layer | Technologies |
|---|---|
| Backend | PHP 8.5, Laravel 13, Octane (FrankenPHP), PostgreSQL + pgvector, Sanctum, [laravel-actions](https://github.com/lorisleiva/laravel-actions) |
| Frontend | Inertia v3, React 19, TypeScript, Tailwind CSS v4, shadcn/ui, TipTap |
| Testing | Pest — Unit, Feature and Browser (Playwright E2E) suites |

## Driving TaskR from AI agents (MCP)

1. Create a token in **Settings → API tokens** (shown only once).
2. Point your agent at `POST /mcp` with `Authorization: Bearer <token>`.

The server exposes ~50 tools (workspaces, projects, issues, comments, time tracking, knowledge base…). Start with `list_workspaces`, then pass the `workspace_id` to every other tool.

Example client configuration (OpenCode):

```json
{
  "mcp": {
    "servers": {
      "taskr": {
        "type": "remote",
        "url": "https://taskr.example.com/mcp",
        "oauth": false,
        "headers": { "Authorization": "Bearer {env:TASKR_MCP_TOKEN}" }
      }
    }
  }
}
```

## Testing

```bash
composer test        # Pest: Unit + Feature suites
composer test:e2e    # Browser suite (Playwright, desktop + mobile viewports)
```

This project follows a mandatory TDD workflow — see [`AGENTS.md`](AGENTS.md) and [`.opencode/skills/tdd`](.opencode/skills/tdd).

## Documentation & conventions

- Functional user guide, written for non-technical users: [English](docs/user-guide_EN.md) · [Français](docs/user-guide_FR.md).
- Installation (local development): [English](docs/installation_EN.md) · [Français](docs/installation_FR.md).
- Self-hosting (production deployment): [English](docs/self-hosting_EN.md) · [Français](docs/self-hosting_FR.md).
- [`AGENTS.md`](AGENTS.md) — project conventions (mandatory TDD, `issue-dev` workflow for every issue).

## Repository

[github.com/hadrien-shifumi/TaskR](https://github.com/hadrien-shifumi/TaskR)
