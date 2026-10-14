<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Projects\GetProjectHealth;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Membership;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProjectBoardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace, 2: Project, 3: array<string, Status>}
     */
    private function setupProjectWithStatuses(string $projectName = 'Site vitrine'): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, ['name' => $projectName]);

        $statuses = [];
        foreach ($workspace->statuses as $status) {
            $statuses[$status->category->value] = $status;
        }

        return [$owner, $workspace, $project, $statuses];
    }

    private function createIssue(Project $project, Status $status, string $title = 'Issue'): void
    {
        CreateIssue::run(app(WorkspaceContext::class)->getOrFail(), [
            'project_id' => $project->id,
            'title' => $title,
            'status_id' => $status->id,
        ]);
    }

    public function test_board_renders_one_column_per_workspace_status_in_order(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $this->actingAs($owner)
            ->get(route('projects.board', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Board')
                ->has('columns', 4)
                ->where('columns.0.name', 'Backlog')
                ->where('columns.0.category', 'backlog')
                ->where('columns.1.name', 'In Progress')
                ->where('columns.1.category', 'in_progress')
                ->where('columns.2.name', 'Done')
                ->where('columns.2.category', 'done')
                ->where('columns.3.name', 'Cancelled')
                ->where('columns.3.category', 'cancelled'));
    }

    public function test_board_keeps_empty_columns_with_an_empty_issue_list(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $this->actingAs($owner)
            ->get(route('projects.board', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('columns.0.id', $statuses['backlog']->id)
                ->has('columns.0.issues', 0));
    }

    public function test_board_groups_project_issues_by_status(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $this->createIssue($project, $statuses['backlog'], 'B1');
        $this->createIssue($project, $statuses['backlog'], 'B2');
        $this->createIssue($project, $statuses['in_progress'], 'IP1');
        $this->createIssue($project, $statuses['done'], 'D1');

        $this->actingAs($owner)
            ->get(route('projects.board', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('columns.0.issues', 2)
                ->where('columns.0.issues.0.title', 'B1')
                ->where('columns.0.issues.1.title', 'B2')
                ->has('columns.1.issues', 1)
                ->where('columns.1.issues.0.title', 'IP1')
                ->has('columns.2.issues', 1)
                ->where('columns.2.issues.0.title', 'D1')
                ->has('columns.3.issues', 0));
    }

    public function test_board_cards_carry_presentation_fields(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $issue = CreateIssue::run(app(WorkspaceContext::class)->getOrFail(), [
            'project_id' => $project->id,
            'title' => 'Webhooks',
            'status_id' => $statuses['in_progress']->id,
            'priority' => 'urgent',
            'due_date' => now()->subDay()->toDateString(),
        ]);
        $issue->assignees()->attach($owner);

        $this->actingAs($owner)
            ->get(route('projects.board', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('columns.1.issues.0.id', $issue->id)
                ->where('columns.1.issues.0.title', 'Webhooks')
                ->where('columns.1.issues.0.priority', 'urgent')
                ->where('columns.1.issues.0.dueDate', now()->subDay()->toDateString())
                ->where('columns.1.issues.0.overdue', true)
                ->where('columns.1.issues.0.assignees.0.id', $owner->id)
                ->where('columns.1.issues.0.assignees.0.name', $owner->name));
    }

    public function test_board_excludes_issues_from_other_projects(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();
        $other = CreateProject::run($workspace, ['name' => 'Autre projet']);
        $this->createIssue($other, $statuses['backlog'], 'Elsewhere');

        $this->actingAs($owner)
            ->get(route('projects.board', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('columns.0.issues', 0));
    }

    public function test_board_access_is_denied_cross_workspace(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $intruder = User::factory()->create();
        CreateWorkspace::run($intruder, ['name' => 'Theirs']);

        $this->actingAs($intruder)
            ->get(route('projects.board', $project->id))
            ->assertNotFound();
    }

    public function test_member_can_view_the_board(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();
        $this->createIssue($project, $statuses['backlog'], 'B1');

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $this->actingAs($member)
            ->get(route('projects.board', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('columns.0.issues', 1));
    }

    public function test_board_includes_the_project_summary(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $this->actingAs($owner)
            ->get(route('projects.board', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('project.id', $project->id)
                ->where('project.name', 'Site vitrine'));
    }

    public function test_board_counts_match_project_health(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $this->createIssue($project, $statuses['backlog'], 'B1');
        $this->createIssue($project, $statuses['backlog'], 'B2');
        $this->createIssue($project, $statuses['in_progress'], 'IP1');
        $this->createIssue($project, $statuses['done'], 'D1');
        $this->createIssue($project, $statuses['cancelled'], 'C1');

        $health = GetProjectHealth::run($project);
        $board = $this->actingAs($owner)
            ->get(route('projects.board', $project->id))
            ->assertOk();

        $page = $board->inertiaPage();
        $columns = collect($page['props']['columns']);

        $this->assertSame(
            $health->issuesByCategory['backlog'],
            $columns->firstWhere('category', 'backlog')['issuesCount'],
        );
        $this->assertSame(
            $health->issuesByCategory['in_progress'],
            $columns->firstWhere('category', 'in_progress')['issuesCount'],
        );
        $this->assertSame(
            $health->issuesByCategory['done'],
            $columns->firstWhere('category', 'done')['issuesCount'],
        );
        $this->assertSame(
            $health->issuesByCategory['cancelled'],
            $columns->firstWhere('category', 'cancelled')['issuesCount'],
        );
    }

    public function test_moving_a_card_uses_the_existing_move_route(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $issue = CreateIssue::run(app(WorkspaceContext::class)->getOrFail(), [
            'project_id' => $project->id,
            'title' => 'Webhooks',
            'status_id' => $statuses['backlog']->id,
        ]);

        $this->actingAs($owner)
            ->post(route('issues.move', $issue->id), ['status_id' => $statuses['done']->id])
            ->assertRedirect();

        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'status_id' => $statuses['done']->id,
        ]);
    }
}
