<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Labels\CreateLabel;
use App\Actions\Projects\CreateProject;
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

class IssuesKanbanViewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace, 2: Project, 3: array<string, Status>}
     */
    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        $statuses = [];
        foreach ($workspace->statuses as $status) {
            $statuses[$status->category->value] = $status;
        }

        return [$owner, $workspace, $project, $statuses];
    }

    /**
     * The boards ride a deferred prop: the payload assertions ask for a
     * partial reload requesting them, exactly like the client does after
     * the first paint (view and filters ride along, like the client's own
     * filter partial reloads).
     *
     * @param  list<string>  $only
     * @return array<string, string>
     */
    private function partial(array $only = ['projectBoards', 'view', 'filters']): array
    {
        return [
            'X-Inertia-Partial-Component' => 'Issues/Index',
            'X-Inertia-Partial-Data' => implode(',', $only),
        ];
    }

    public function test_list_view_is_the_default_when_no_view_param(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspace();

        CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Une issue',
            'status_id' => $statuses['backlog']->id,
        ]);

        $this->actingAs($owner)
            ->get('/issues')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Issues/Index')
                ->where('view', 'list')
                ->has('issues', 1)
                ->where('issues.0.title', 'Une issue')
                ->missing('projectBoards'));
    }

    public function test_kanban_view_returns_project_boards_grouped_by_status(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspace();

        CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'B1',
            'status_id' => $statuses['backlog']->id,
        ]);
        CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'IP1',
            'status_id' => $statuses['in_progress']->id,
        ]);

        $this->actingAs($owner)
            ->get('/issues?view=kanban', $this->partial())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Issues/Index')
                ->where('view', 'kanban')
                ->has('projectBoards', 1)
                ->where('projectBoards.0.project.id', $project->id)
                ->where('projectBoards.0.project.name', 'Site vitrine')
                ->has('projectBoards.0.columns', 4)
                ->where('projectBoards.0.columns.0.category', 'backlog')
                ->where('projectBoards.0.columns.0.issuesCount', 1)
                ->where('projectBoards.0.columns.0.issues.0.title', 'B1')
                ->where('projectBoards.0.columns.1.category', 'in_progress')
                ->where('projectBoards.0.columns.1.issuesCount', 1)
                ->where('projectBoards.0.columns.1.issues.0.title', 'IP1')
                ->missing('issues'));
    }

    public function test_kanban_view_includes_all_workspace_statuses_in_order(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->get('/issues?view=kanban', $this->partial())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('projectBoards.0.columns', 4)
                ->where('projectBoards.0.columns.0.name', 'Backlog')
                ->where('projectBoards.0.columns.0.category', 'backlog')
                ->where('projectBoards.0.columns.1.name', 'In Progress')
                ->where('projectBoards.0.columns.1.category', 'in_progress')
                ->where('projectBoards.0.columns.2.name', 'Done')
                ->where('projectBoards.0.columns.2.category', 'done')
                ->where('projectBoards.0.columns.3.name', 'Cancelled')
                ->where('projectBoards.0.columns.3.category', 'cancelled'));
    }

    public function test_kanban_view_groups_issues_by_project(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspace();
        $other = CreateProject::run($workspace, $owner, ['name' => 'Autre projet']);

        CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Dans le premier',
            'status_id' => $statuses['backlog']->id,
        ]);
        CreateIssue::run($workspace, [
            'project_id' => $other->id,
            'title' => 'Dans le second',
            'status_id' => $statuses['backlog']->id,
        ]);

        $this->actingAs($owner)
            ->get('/issues?view=kanban', $this->partial())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('projectBoards', 2)
                ->where('projectBoards.0.project.name', 'Autre projet')
                ->where('projectBoards.0.columns.0.issues.0.title', 'Dans le second')
                ->where('projectBoards.1.project.name', 'Site vitrine')
                ->where('projectBoards.1.columns.0.issues.0.title', 'Dans le premier'));
    }

    public function test_kanban_view_respects_project_filter(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspace();
        $other = CreateProject::run($workspace, $owner, ['name' => 'Autre projet']);

        CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Premier',
            'status_id' => $statuses['backlog']->id,
        ]);
        CreateIssue::run($workspace, [
            'project_id' => $other->id,
            'title' => 'Second',
            'status_id' => $statuses['backlog']->id,
        ]);

        $this->actingAs($owner)
            ->get("/issues?view=kanban&project={$project->id}", $this->partial())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('projectBoards', 1)
                ->where('projectBoards.0.project.id', $project->id)
                ->where('projectBoards.0.columns.0.issues.0.title', 'Premier'));
    }

    public function test_kanban_view_respects_category_filter(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspace();

        CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'En cours',
            'status_id' => $statuses['in_progress']->id,
        ]);
        CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'En backlog',
            'status_id' => $statuses['backlog']->id,
        ]);

        $this->actingAs($owner)
            ->get('/issues?view=kanban&category=in_progress', $this->partial())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('projectBoards.0.columns.0.issuesCount', 0)
                ->where('projectBoards.0.columns.1.issuesCount', 1)
                ->where('projectBoards.0.columns.1.issues.0.title', 'En cours'));
    }

    public function test_kanban_view_respects_overdue_filter(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspace();

        CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'En retard',
            'status_id' => $statuses['backlog']->id,
            'due_date' => '2020-01-01',
        ]);
        CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Pas en retard',
            'status_id' => $statuses['backlog']->id,
        ]);

        $this->actingAs($owner)
            ->get('/issues?view=kanban&overdue=1', $this->partial())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('projectBoards.0.columns.0.issuesCount', 1)
                ->where('projectBoards.0.columns.0.issues.0.title', 'En retard')
                ->where('projectBoards.0.columns.0.issues.0.overdue', true));
    }

    public function test_kanban_view_excludes_archived_projects(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspace();
        $archived = CreateProject::run($workspace, $owner, ['name' => 'Archivé']);
        $archived->forceFill(['archived_at' => now()])->save();

        CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Actif',
            'status_id' => $statuses['backlog']->id,
        ]);
        CreateIssue::run($workspace, [
            'project_id' => $archived->id,
            'title' => 'Archivé',
            'status_id' => $statuses['backlog']->id,
        ]);

        $this->actingAs($owner)
            ->get('/issues?view=kanban', $this->partial())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('projectBoards', 1)
                ->where('projectBoards.0.project.id', $project->id));
    }

    public function test_kanban_view_cross_workspace_isolation(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspace();

        CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Mon issue',
            'status_id' => $statuses['backlog']->id,
        ]);

        // Créer un autre workspace avec une issue
        $intruder = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($intruder, ['name' => 'Theirs']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $otherProject = CreateProject::run($otherWorkspace, $intruder, ['name' => 'Foreign']);
        CreateIssue::run($otherWorkspace, [
            'project_id' => $otherProject->id,
            'title' => 'Foreign issue',
            'status_id' => $otherWorkspace->statuses()->first()->id,
        ]);

        // Revenir au workspace original
        app(WorkspaceContext::class)->set($workspace);

        $this->actingAs($owner)
            ->get('/issues?view=kanban', $this->partial())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('projectBoards', 1)
                ->where('projectBoards.0.columns.0.issuesCount', 1)
                ->where('projectBoards.0.columns.0.issues.0.title', 'Mon issue'));
    }

    public function test_kanban_view_cards_carry_presentation_fields(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspace();

        $label = CreateLabel::run($workspace, ['name' => 'front', 'color' => 'blue']);
        $member = User::factory()->create(['name' => 'Jean Dupont']);
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $issue = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Carte complète',
            'status_id' => $statuses['in_progress']->id,
            'priority' => 'urgent',
            'due_date' => '2020-01-01',
        ]);
        $issue->assignees()->attach($member);
        $issue->labels()->attach($label);

        $this->actingAs($owner)
            ->get('/issues?view=kanban', $this->partial())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('projectBoards.0.columns.1.issues.0.id', $issue->id)
                ->where('projectBoards.0.columns.1.issues.0.title', 'Carte complète')
                ->where('projectBoards.0.columns.1.issues.0.priority', 'urgent')
                ->where('projectBoards.0.columns.1.issues.0.dueDate', '2020-01-01T00:00:00.000000Z')
                ->where('projectBoards.0.columns.1.issues.0.overdue', true)
                ->where('projectBoards.0.columns.1.issues.0.assignees.0.id', $member->id)
                ->where('projectBoards.0.columns.1.issues.0.assignees.0.name', 'Jean Dupont')
                ->where('projectBoards.0.columns.1.issues.0.labels.0.id', $label->id)
                ->where('projectBoards.0.columns.1.issues.0.labels.0.name', 'front')
                ->where('projectBoards.0.columns.1.issues.0.labels.0.color', 'blue'));
    }

    public function test_kanban_view_boards_are_ordered_by_project_name(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspace();
        $zebra = CreateProject::run($workspace, $owner, ['name' => 'Zebra']);
        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);

        $this->actingAs($owner)
            ->get('/issues?view=kanban', $this->partial())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('projectBoards', 3)
                ->where('projectBoards.0.project.name', 'Alpha')
                ->where('projectBoards.1.project.name', 'Site vitrine')
                ->where('projectBoards.2.project.name', 'Zebra'));
    }

    public function test_kanban_view_includes_view_in_filters(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->get('/issues?view=kanban', $this->partial())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.view', 'kanban'));
    }

    public function test_kanban_empty_state_returns_boards_with_empty_columns(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->get('/issues?view=kanban', $this->partial())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('projectBoards', 1)
                ->where('projectBoards.0.project.id', $project->id)
                ->has('projectBoards.0.columns', 4)
                ->where('projectBoards.0.columns.0.issuesCount', 0)
                ->has('projectBoards.0.columns.0.issues', 0)
                ->where('projectBoards.0.columns.1.issuesCount', 0)
                ->where('projectBoards.0.columns.2.issuesCount', 0)
                ->where('projectBoards.0.columns.3.issuesCount', 0));
    }

    public function test_kanban_view_respects_assignee_filter(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspace();

        $member = User::factory()->create(['name' => 'Marie']);
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $mine = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Assignée à Marie',
            'status_id' => $statuses['backlog']->id,
        ]);
        $mine->assignees()->attach($member);

        CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Non assignée',
            'status_id' => $statuses['backlog']->id,
        ]);

        $this->actingAs($owner)
            ->get("/issues?view=kanban&assignee={$member->id}", $this->partial())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('projectBoards.0.columns.0.issuesCount', 1)
                ->where('projectBoards.0.columns.0.issues.0.title', 'Assignée à Marie'));
    }

    public function test_kanban_view_respects_search_filter(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspace();

        CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Webhook retry',
            'status_id' => $statuses['backlog']->id,
        ]);
        CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Login page',
            'status_id' => $statuses['backlog']->id,
        ]);

        $this->actingAs($owner)
            ->get('/issues?view=kanban&q=webhook', $this->partial())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('projectBoards.0.columns.0.issuesCount', 1)
                ->where('projectBoards.0.columns.0.issues.0.title', 'Webhook retry'));
    }

    public function test_kanban_view_ignores_sort_and_pagination(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspace();

        for ($i = 1; $i <= 51; $i++) {
            CreateIssue::run($workspace, [
                'project_id' => $project->id,
                'title' => sprintf('Ticket %02d', $i),
                'status_id' => $statuses['backlog']->id,
            ]);
        }

        // The kanban is a full load: page/sort params are ignored and every
        // issue lands on its board column.
        $this->actingAs($owner)
            ->get('/issues?view=kanban&page=2&sort=priority&dir=asc', $this->partial())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Issues/Index')
                ->missing('issues')
                ->missing('page')
                ->missing('perPage')
                ->has('projectBoards', 1)
                ->where('projectBoards.0.columns.0.issuesCount', 51)
                ->has('projectBoards.0.columns.0.issues', 51));
    }
}
