# Action Anatomy

Canonical skeletons extracted from the codebase. Copy the closest one and adapt.

## Mutating action (create)

```php
<?php

namespace App\Actions\Issues;

use App\Models\Issue;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Issues\IssueRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class CreateIssue
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        return $this->currentUserIsMember();
    }

    public function rules(): array
    {
        $workspace = $this->currentWorkspace();

        return [
            'project_id' => ['required', 'ulid', Rule::exists('projects', 'id')->where('workspace_id', $workspace?->id)],
            'title' => ['required', 'string', 'max:500'],
            // every relational rule is workspace-scoped
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Workspace $workspace, array $data): Issue
    {
        $issue = Issue::create([...]); // workspace_id auto-filled by BelongsToWorkspace

        return $issue->refresh();
    }

    public function asController(ActionRequest $request, IssueRedirectResponder $responder): RedirectResponse
    {
        $issue = $this->handle($this->currentWorkspace(), $request->validated());

        return $responder->created($issue);
    }

    protected function currentUserIsMember(): bool
    {
        $user = request()->user();

        return $user instanceof User && app(WorkspaceContext::class)->isMember($user);
    }

    protected function currentWorkspace(): ?Workspace
    {
        return app(WorkspaceContext::class)->get();
    }
}
```

Notes:

- `authorize()` may inline the check (`MoveIssue`) or use the protected helpers (`CreateIssue`). Both exist; keep helpers when several methods need them.
- Owner-only variant (see `InviteMember`): `$workspace->hasOwner($user)` where `$workspace = request()->route('workspace')`.
- Cycle/consistency rules that depend on persisted state belong in `handle()` and throw `ValidationException::withMessages(...)` (see `SetIssueParent`), not in `rules()`.

## List page action

```php
public function handle(Workspace $workspace, array $filters = []): array
{
    $query = Issue::query()
        ->with(['project', 'status', 'labels', 'assignees'])
        ->orderByDesc('created_at');

    $this->applyFilters($query, $filters); // private method, one `if (! empty($filters['x']))` per filter

    $issues = $query->get()->map(fn (Issue $issue) => $this->present($issue))->values()->all();

    return [
        'issues' => $issues,
        'filters' => array_filter($filters, fn ($value) => $value !== null && $value !== ''),
        'projects' => $workspace->projects()->active()->orderBy('name')->get(['id', 'name']),
        // option lists for the page's selects
    ];
}

public function asController(Request $request, IssuesPageResponder $responder): Response
{
    $workspace = app(WorkspaceContext::class)->getOrFail();

    $data = $this->handle($workspace, [
        'project' => $request->query('project'),
        // explicit allowlist of query params — never pass the whole query bag
    ]);

    return $responder->toResponse($request, $data);
}
```

`present()` returns a flat camelCase array: scalars, `?->toISOString()` for dates, computed booleans (`overdue`), and nested `{id, name}` refs for relations.

## Responders

Page responder:

```php
class IssuesPageResponder
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function toResponse(Request $request, array $data): Response
    {
        return Inertia::render('Issues/Index', $data)->toResponse($request);
    }
}
```

Redirect responder — one method per outcome, flash key is always `status`:

```php
class IssueRedirectResponder
{
    public function created(Issue $issue): RedirectResponse
    {
        return redirect()->route('issues.show', $issue)->with('status', 'Issue created.');
    }
}
```

## Route registration

```php
Route::middleware(['auth', 'workspace'])->group(function (): void {
    Route::get('/issues', ShowIssuesPage::class)->name('issues.index');
    Route::post('/issues', CreateIssue::class)->name('issues.store');
    Route::match(['put', 'patch'], '/issues/{issue}', UpdateIssue::class)->name('issues.update');
    Route::delete('/issues/{issue}', DeleteIssue::class)->name('issues.destroy');
    Route::post('/issues/{issue}/move', MoveIssue::class)->name('issues.move'); // sub-actions get their own verb route
});
```
