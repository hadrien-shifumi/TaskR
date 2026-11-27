<?php

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\TimeTracking\CreateTimeEntry;
use App\Actions\TimeTracking\SetIssueEstimate;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature');
uses(TestCase::class, RefreshDatabase::class)->in('Browser');

/**
 * Browser suite guard: a live Vite dev server leaves a public/hot file, and the
 * in-process HTTP server then serves the dev assets instead of public/build —
 * unbundled modules where import.meta.env.PROD is false, so build-only
 * behaviour (the PWA service worker registration) silently disappears and the
 * suite fails behind opaque 5s timeouts. Fail fast with an actionable message
 * instead. Covered by tests/Unit/ViteHotFileGuardTest.php.
 *
 * @param  string|null  $hotPath  Override for tests; defaults to public/hot.
 */
function ensureBuiltAssetsForBrowserSuite(?string $hotPath = null): void
{
    $hotPath ??= public_path('hot');

    if (! file_exists($hotPath)) {
        return;
    }

    Assert::fail(
        'A Vite dev server is running (public/hot exists): the Browser suite must run against the built assets (npm run build). '
        .'A dev server serves unbundled assets where import.meta.env.PROD is false, silently disabling build-only behaviour '
        .'such as the service worker registration. Stop the dev server or delete public/hot, then re-run.',
    );
}

// Every browser test starts from the built assets or fails immediately.
// NB: hooks must be scoped through uses()->in()->beforeEach() — a plain
// beforeEach(...)->in() silently registers an unscoped hook with a bogus
// higher-order proxy, it does NOT filter by directory.
uses()->in('Browser')->beforeEach(function (): void {
    ensureBuiltAssetsForBrowserSuite();
});

/**
 * Shared Browser selector: any row of the issues list (list, table and
 * saved-view renders alike). Declared once here — two test files used to
 * declare it locally, and the duplicate global constant aborted parallel
 * (ParaTest) runs, which load every test file of a worker in one process
 * after Laravel's error handler is armed.
 */
const ISSUE_ROWS = '[data-test^="issue-row-"]';

/**
 * Browser fixture: an owner with a workspace (optionally a project), mirroring
 * the `setupWorkspace()` pattern of the feature suites. Binds the workspace
 * context in the test process so domain actions run as objects and relation
 * queries resolve, re-binding it is the caller's job after `visit()` calls.
 *
 * @param  array{0: User, 1: Workspace}  ... without project
 * @param  array{0: User, 1: Workspace, 2: Project}  ... with project
 * @return array{0: User, 1: Workspace, 2?: Project}
 */
function setupBrowserWorkspace(bool $withProject = false): array
{
    $owner = User::factory()->create();
    $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
    app(WorkspaceContext::class)->set($workspace);

    if (! $withProject) {
        return [$owner, $workspace];
    }

    $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

    return [$owner, $workspace, $project];
}

/**
 * Browser fixture seeding a project for the project-health scenarios:
 * 3 backlog, 2 in progress (one overdue), 4 done, 1 cancelled → 40% complete
 * (done only over the total); 10h estimated / 8h logged on open issues → 80%
 * consumption over open work (D1's 100h/100h stay excluded); target date
 * passed → late.
 */
function seedProjectWithHealth(Workspace $workspace, User $owner): Project
{
    app(WorkspaceContext::class)->set($workspace);

    $project = CreateProject::run($workspace, $owner, [
        'name' => 'Refonte site vitrine',
        'target_date' => now()->subDay()->toDateString(),
    ]);

    $statuses = Status::withoutWorkspaceScope()
        ->where('workspace_id', $workspace->id)
        ->get()
        ->keyBy(fn (Status $status) => $status->category->value);

    $issue = fn (string $title, Status $status, array $extra = []) => CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => $title,
        'status_id' => $status->id,
        ...$extra,
    ]);

    $backlog = [
        $issue('B1', $statuses['backlog']),
        $issue('B2', $statuses['backlog']),
        $issue('B3', $statuses['backlog']),
    ];

    $inProgress = [
        $issue('IP1', $statuses['in_progress'], ['due_date' => now()->subWeek()->toDateString()]),
        $issue('IP2', $statuses['in_progress']),
    ];

    $done = [
        $issue('D1', $statuses['done']),
        $issue('D2', $statuses['done']),
        $issue('D3', $statuses['done']),
        $issue('D4', $statuses['done']),
    ];

    $issue('C1', $statuses['cancelled']);

    // Consumption ratio covers open work only: 4h + 6h estimated and
    // 4h + 4h logged on backlog/in_progress issues → 80%. D1 carries
    // 100h estimated / 100h logged and must stay excluded (done).
    SetIssueEstimate::run($done[0], 100.0);
    CreateTimeEntry::run($done[0], $owner, ['hours' => 100]);
    SetIssueEstimate::run($backlog[0], 4.0);
    CreateTimeEntry::run($backlog[0], $owner, ['hours' => 4]);
    SetIssueEstimate::run($inProgress[1], 6.0);
    CreateTimeEntry::run($inProgress[1], $owner, ['hours' => 4]);

    return $project;
}
