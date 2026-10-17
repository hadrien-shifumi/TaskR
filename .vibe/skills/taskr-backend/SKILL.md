---
name: Taskr Backend
description: Conventions for taskr backend code — Laravel actions as controllers, responders, workspace multi-tenancy, ULID models, scoped validation, migrations, and feature tests. Use for any PHP change under app/, database/, routes/, or tests/.
---

# Taskr Backend

Stack: Laravel 13, PHP 8.3+, `lorisleiva/laravel-actions` v2, Inertia (server side), SQLite in tests / PostgreSQL in production, PHPUnit 12. No API resources, no policies, no form requests, no events. Actions are the only controller layer.

## Architecture map

```
app/
├── Actions/<Domain>/<VerbEntity>.php   # HTTP controller + domain logic (AsController, often AsObject)
├── Responders/<Domain>/                # PageResponder (Inertia::render) + RedirectResponder (redirect + flash)
├── Models/                             # BaseModel (ULID) + Concerns/BelongsToWorkspace + Scopes/WorkspaceScope
├── Enums/                              # Priority, StatusCategory, WorkspaceRole (string-backed, TitleCase)
├── Support/WorkspaceContext.php        # Request-scoped current workspace singleton
└── Http/Middleware/                    # ResolveCurrentWorkspace ('workspace'), HandleInertiaRequests
routes/web.php                          # Route::...('/path', ActionClass::class)->name(...)
```

Domains: `Auth`, `Comments`, `Issues`, `Labels`, `Projects`, `Statuses`, `System`, `Workspaces`.

## Hard rules

1. **Routes point to Action classes.** Never create a controller class. Every route gets a `name()`. Updates use `Route::match(['put', 'patch'], ...)`. Nested resources use `->scopeBindings()`. Workspace pages sit inside `Route::middleware(['auth', 'workspace'])`.
2. **Action anatomy is fixed:** `authorize()` → `rules()` → `handle()` → `asController()`. `handle()` takes explicit domain arguments (`Workspace $workspace`, models, validated array) and never touches the request. `asController()` stays thin: resolve context, call `$this->handle(...)`, delegate to a responder. See [references/action-anatomy.md](references/action-anatomy.md).
3. **`AsObject` only when another call site or test invokes the action as an object** (`CreateIssue::run(...)`). Pure HTTP actions omit it.
4. **Responders own the HTTP response.** Actions never call `Inertia::render`, `redirect()`, or `back()` directly. Two kinds: `*PageResponder::toResponse()` renders `Inertia::render('Domain/Name', $data)`; `*RedirectResponder` methods (`created`, `updated`, `deleted`) redirect to a named route with `->with('status', '<Entity> <verb>.')`.
5. **Presentation happens in the Action**, as plain arrays with camelCase keys for the frontend (`dueDate`, `statusName`). Load relations with `with([...])`, map in a private `present()` method. Never pass Eloquent models or collections to Inertia.
6. **Workspace isolation is not optional.** Every business model extends `BaseModel`, uses `BelongsToWorkspace`, and has a `workspace_id` ULID FK. The global `WorkspaceScope` filters every query; cross-workspace IDs must 404 naturally via binding, and validation must scope every relational ID: `Rule::exists('projects', 'id')->where('workspace_id', $workspace?->id)`. See [references/workspace-tenancy.md](references/workspace-tenancy.md).
7. **Authorization lives in `authorize()`** using `WorkspaceContext`: `isMember($user)` for member actions, `hasOwner($user)` for owner-only actions (invite, delete workspace). No policies, no gates.
8. **Migrations:** ULID primary keys (`$table->ulid('id')->primary()`), `workspace_id` FK `cascadeOnDelete`, `restrictOnDelete` for reference data in use (statuses), composite indexes leading with `workspace_id`, driver-conditional CHECK constraints (PostgreSQL only — SQLite tests cannot `ALTER TABLE ADD CONSTRAINT`). Self-referencing FKs are added after table creation.
9. **Multi-step writes wrap in `DB::transaction`** (see `CreateWorkspace`).
10. **Tests use the shared setup pattern:** `setupWorkspace()` helper (User factory + `CreateWorkspace::run` + `app(WorkspaceContext::class)->set(...)`), arrange via actions run as objects, assert via `actingAs(...)->post/get` + `assertInertia` / `assertDatabaseHas`. Every new workspace-scoped resource needs a cross-workspace isolation test. See [references/testing.md](references/testing.md).

## Style

- PHPDoc blocks with array shape types (`@param array<string, mixed> $data`), relation return types (`@return BelongsTo<Project, $this>`).
- Constructor property promotion, explicit return types, curly braces always.
- Route/model authorization failures surface as 404 (scope) or 422 (validation) or 403 (authorize) — keep that mapping intact.

## Verification

- `vendor/bin/pint --dirty --format agent` after any PHP edit.
- `php artisan test --compact <path>` for the narrowest covering test file; rerun after each change.
