<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class HomeDashboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace, 2: Project, 3: array<string, Status>}
     */
    private function setupWorkspaceWithProject(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, ['name' => 'Site vitrine']);

        $statuses = [];
        foreach ($workspace->statuses as $status) {
            $statuses[$status->category->value] = $status;
        }

        return [$owner, $workspace, $project, $statuses];
    }

    private function createIssue(Project $project, Status $status, string $title, array $extra = []): void
    {
        CreateIssue::run(app(WorkspaceContext::class)->getOrFail(), [
            'project_id' => $project->id,
            'title' => $title,
            'status_id' => $status->id,
            ...$extra,
        ]);
    }

    public function test_home_shows_workspace_issue_counters(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();

        // 2 open (backlog + in progress), 1 done, 1 cancelled, 1 overdue open.
        $this->createIssue($project, $statuses['backlog'], 'B1');
        $this->createIssue($project, $statuses['in_progress'], 'IP1', ['due_date' => now()->subDay()->toDateString()]);
        $this->createIssue($project, $statuses['done'], 'D1');
        $this->createIssue($project, $statuses['cancelled'], 'C1');

        $this->actingAs($owner)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Home')
                ->where('stats.totalIssues', 4)
                ->where('stats.openIssues', 2)
                ->where('stats.overdueIssues', 1));
    }

    public function test_home_shows_project_counters(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        CreateProject::run($workspace, ['name' => 'Archivé']);

        $this->actingAs($owner)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stats.activeProjects', 2)
                ->where('stats.archivedProjects', 0));
    }

    public function test_home_lists_active_projects_with_health(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        $this->createIssue($project, $statuses['done'], 'D1');
        $this->createIssue($project, $statuses['backlog'], 'B1');

        $this->actingAs($owner)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('projects', 1)
                ->where('projects.0.id', $project->id)
                ->where('projects.0.name', 'Site vitrine')
                ->where('projects.0.health.totalIssues', 2)
                ->where('projects.0.health.completionPercentage', 50));
    }

    public function test_home_lists_my_assigned_issues(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        $this->createIssue($project, $statuses['in_progress'], 'Mine');
        $issue = \App\Models\Issue::query()->where('title', 'Mine')->firstOrFail();
        $issue->assignees()->attach($owner);

        $this->actingAs($owner)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('myIssues', 1)
                ->where('myIssues.0.id', $issue->id)
                ->where('myIssues.0.title', 'Mine')
                ->where('myIssues.0.statusName', 'In Progress'));
    }
}
