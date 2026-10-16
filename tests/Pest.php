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
use Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature');
uses(TestCase::class, RefreshDatabase::class)->in('Browser');

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
 * (done only over the total); 10h estimated, 8h logged → 80% consumption;
 * target date passed → late.
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

    $issue('B1', $statuses['backlog']);
    $issue('B2', $statuses['backlog']);
    $issue('B3', $statuses['backlog']);

    $issue('IP1', $statuses['in_progress'], ['due_date' => now()->subWeek()->toDateString()]);
    $issue('IP2', $statuses['in_progress']);

    $done = [
        $issue('D1', $statuses['done']),
        $issue('D2', $statuses['done']),
        $issue('D3', $statuses['done']),
        $issue('D4', $statuses['done']),
    ];

    $issue('C1', $statuses['cancelled']);

    // 4h + 6h estimated, 4h + 4h logged → 80% consumption.
    SetIssueEstimate::run($done[0], 4.0);
    CreateTimeEntry::run($done[0], $owner, ['hours' => 4]);
    SetIssueEstimate::run($done[1], 6.0);
    CreateTimeEntry::run($done[1], $owner, ['hours' => 4]);

    return $project;
}
