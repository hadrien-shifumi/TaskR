# Workspace Multi-Tenancy

How isolation works end to end. Do not bypass any layer; each one covers a gap the others cannot.

## Request flow

1. `bootstrap/app.php` registers `ResolveCurrentWorkspace` under the `workspace` alias and prepends it **before** `SubstituteBindings` — the global scope must know the workspace before route model binding runs, or scoped models 404.
2. `ResolveCurrentWorkspace`: authenticated user only. Resolves from `session('workspace_id')` while the membership is still valid, otherwise falls back to the user's first workspace by name (null when member of none), stores the choice back in session.
3. `WorkspaceContext` is a singleton holding `?Workspace`. Access:
   - `get(): ?Workspace` — nullable, for validation rules.
   - `getOrFail(): Workspace` — 404s when no workspace; use in `asController()` of page actions.
   - `id(): ?string` — for `Rule::exists` scoping.
   - `isMember(User $user): bool` — for `authorize()`.

## Model layer

- `BaseModel` (abstract): `HasUlids`. All business entities extend it. `User` stays an int-PK framework model.
- `BelongsToWorkspace` trait:
  - adds the `WorkspaceScope` global scope;
  - auto-fills `workspace_id` on `creating()` from `WorkspaceContext` — actions never set it manually;
  - provides `withoutWorkspaceScope()` static.
- `WorkspaceScope`: when no current workspace, applies `whereRaw('0 = 1')` — nothing leaks, queries return empty instead of erroring.

Legitimate `withoutWorkspaceScope()` uses (from the codebase): creating records before/without context (`CreateWorkspace` seeding default statuses, `InviteMember` creating an invitation outside a membership check) and test assertions that must count across workspaces. If you reach for it elsewhere, justify why the global scope is wrong for that call.

## Validation scoping (the second wall)

Binding + global scope make cross-workspace reads 404, but writes with attacker-supplied IDs need scoped rules. Every relational ID in `rules()`:

```php
'project_id' => ['required', 'ulid', Rule::exists('projects', 'id')->where('workspace_id', $workspace?->id)],
'assignees.*' => [Rule::exists('memberships', 'user_id')->where('workspace_id', $workspace?->id)],
```

Pivot/reference tables carry `workspace_id` too (`memberships`, `labels`), so membership and label rules scope the same way.

## Frontend contract

`HandleInertiaRequests::share()` always shares: `flash.status`, `auth.user {id, name, email}`, `workspace {id, name, role}`, `workspaces [{id, name, role}]`. Consume these via `usePage().props`; do not add per-page duplicates.

## Invariants to preserve

- Deleting a parent issue nulls children (`nullOnDelete` FK); deleting a status or project in use is refused (`restrictOnDelete` FK + 409 responses).
- Status moves are unconstrained (any workspace status reachable) — do not add transition rules unless asked.
- Labels are unique per workspace (scoped validation on `name`).
- Tests must re-set `WorkspaceContext` (`app(WorkspaceContext::class)->set($workspace)`) when switching workspaces mid-test.
