# Backend Testing Conventions

PHPUnit 12, `RefreshDatabase`, SQLite. Read the global `testing-best-practices` skill for general design; this file is the taskr-specific harness.

## Skeleton

```php
namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspace;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThingTest extends TestCase
{
    use RefreshDatabase;

    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);
        $project = CreateProject::run($workspace, ['name' => 'Site vitrine']);

        return [$owner, $workspace, $project];
    }
}
```

Conventions:

- `setupWorkspace()` returns `[$owner, $workspace, $project, ...$members]`; destructure what a test needs.
- **Arrange via actions run as objects** (`CreateIssue::run(...)`) — never `Model::create` for entities with domain defaults; membership pivots (`Membership::create`) are the exception.
- **Act + assert via HTTP** (`$this->actingAs($owner)->post(...)`) so validation, authorization, and scope all execute.
- Assertions: `assertRedirect()` for mutations, `assertOk()` + `assertInertia(fn (AssertableInertia $page) => $page->has(...)->where(...))` for pages, `assertDatabaseHas`, `assertSessionHasErrors('field')` for validation, `assertStatus(409)` for guarded deletes, `assertStatus(422)` for domain rule rejections.
- Fixture data (French titles like 'Page de profil') mirrors the existing suites; match the domain language of the file you extend.
- To count or assert outside the current workspace: `Model::withoutGlobalScope(WorkspaceScope::class)` or `->withoutGlobalScope(WorkspaceScope::class)` on a relation query.

## Required coverage for a new workspace-scoped resource

1. Minimal create with defaults (asserts fallbacks: priority `none`, first status, etc.).
2. Full create with every relation (assignees, labels, ...).
3. **Cross-workspace isolation**: foreign user gets `assertNotFound()` on GET and PATCH. Non-negotiable.
4. Validation rejection on the scoped rule (`assertSessionHasErrors` or 404 for foreign IDs).
5. Guarded delete when referenced (409) where applicable.
6. List filtering for each query param the index action reads.

## Running

```sh
php artisan test --compact tests/Feature/IssueTest.php   # narrowest file
php artisan test --compact --filter=test_cycle_is_rejected
```

Rerun after each change to a test or the code it covers.
