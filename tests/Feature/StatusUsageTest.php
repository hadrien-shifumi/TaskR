<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Status;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class StatusUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_statuses_page_includes_issue_usage_counts(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, ['name' => 'Site vitrine']);
        $backlog = $workspace->statuses()->where('name', 'Backlog')->firstOrFail();
        $inProgress = $workspace->statuses()->where('name', 'In Progress')->firstOrFail();

        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'A', 'status_id' => $backlog->id]);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'B', 'status_id' => $backlog->id]);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'C', 'status_id' => $inProgress->id]);

        $this->actingAs($owner)
            ->get(route('settings.statuses'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('statuses.0.issuesCount', 2)
                ->where('statuses.1.issuesCount', 1)
                ->where('statuses.2.issuesCount', 0)
                ->where('statuses.3.issuesCount', 0));
    }
}
