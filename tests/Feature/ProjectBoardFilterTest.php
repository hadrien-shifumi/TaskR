<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\SetAssignees;
use App\Actions\Issues\SetIssueParent;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\ProjectRole;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProjectBoardFilterTest extends TestCase
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

        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        $statuses = [];
        foreach ($workspace->statuses as $status) {
            $statuses[$status->category->value] = $status;
        }

        return [$owner, $workspace, $project, $statuses];
    }

    private function addWorkspaceMember(Workspace $workspace): User
    {
        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        return $member;
    }

    private function addProjectMember(Project $project, User $user, ProjectRole $role = ProjectRole::Member): void
    {
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'role' => $role->value,
        ]);
    }

    private function createIssue(Project $project, array $statuses, string $category, string $title, ?User $assignee = null): void
    {
        $issue = CreateIssue::run(app(WorkspaceContext::class)->getOrFail(), [
            'project_id' => $project->id,
            'title' => $title,
            'status_id' => $statuses[$category]->id,
        ]);

        if ($assignee !== null) {
            SetAssignees::run($issue, [$assignee->id]);
        }
    }

    // ── Assignee filter ───────────────────────────────────────────────

    public function test_board_filter_by_assignee_shows_only_their_issues(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        $alice = $this->addWorkspaceMember($workspace);
        $bob = $this->addWorkspaceMember($workspace);

        $this->createIssue($project, $statuses, 'backlog', 'Alice task', $alice);
        $this->createIssue($project, $statuses, 'backlog', 'Bob task', $bob);
        $this->createIssue($project, $statuses, 'backlog', 'Unassigned task');

        $this->actingAs($owner)
            ->get(route('projects.board', [$project->id, 'assignee' => $alice->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('columns.0.issues', 1)
                ->where('columns.0.issues.0.title', 'Alice task')
                ->where('filters.assignee', (string) $alice->id));
    }

    public function test_board_filter_composes_with_parents_only(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        $alice = $this->addWorkspaceMember($workspace);

        // Parent issue assigned to Alice
        $parent = CreateIssue::run(app(WorkspaceContext::class)->getOrFail(), [
            'project_id' => $project->id,
            'title' => 'Parent task',
            'status_id' => $statuses['backlog']->id,
        ]);
        SetAssignees::run($parent, [$alice->id]);

        // Child issue assigned to Alice
        $child = CreateIssue::run(app(WorkspaceContext::class)->getOrFail(), [
            'project_id' => $project->id,
            'title' => 'Child task',
            'status_id' => $statuses['backlog']->id,
        ]);
        SetAssignees::run($child, [$alice->id]);
        SetIssueParent::run($child, $parent->id);

        // Without parents filter: both issues
        $this->actingAs($owner)
            ->get(route('projects.board', [$project->id, 'assignee' => $alice->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('columns.0.issues', 2));

        // With parents filter: only parent
        $this->actingAs($owner)
            ->get(route('projects.board', [$project->id, 'assignee' => $alice->id, 'parents' => '1']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('parentsOnly', true)
                ->has('columns.0.issues', 1)
                ->where('columns.0.issues.0.title', 'Parent task'));
    }

    public function test_board_without_assignee_filter_shows_all_issues(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        $alice = $this->addWorkspaceMember($workspace);

        $this->createIssue($project, $statuses, 'backlog', 'Alice task', $alice);
        $this->createIssue($project, $statuses, 'backlog', 'Unassigned task');

        $this->actingAs($owner)
            ->get(route('projects.board', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('columns.0.issues', 2)
                ->where('filters', []));
    }

    // ── People list (roster ∪ assignees) ──────────────────────────────

    public function test_board_exposes_project_people_roster_union_assignees(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        $alice = $this->addWorkspaceMember($workspace);
        $bob = $this->addWorkspaceMember($workspace);
        $carol = $this->addWorkspaceMember($workspace); // workspace member, NOT in roster

        // Alice in roster
        $this->addProjectMember($project, $alice, ProjectRole::Member);
        // Bob assigned to an issue but NOT in roster
        $this->createIssue($project, $statuses, 'backlog', 'Bob task', $bob);
        // Carol is neither in roster nor assigned

        $this->actingAs($owner)
            ->get(route('projects.board', $project->id))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($owner, $alice, $bob) {
                $page->has('projectPeople', 3); // owner (auto-onboarded) + alice (roster) + bob (assignee)
                $people = collect($page->toArray()['props']['projectPeople']);
                $ids = $people->pluck('id')->all();
                expect($ids)->toContain($owner->id, $alice->id, $bob->id);
            });
    }

    public function test_board_project_people_excludes_workspace_members_not_on_project(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        $alice = $this->addWorkspaceMember($workspace);
        $bob = $this->addWorkspaceMember($workspace); // workspace member, NOT on project

        // Only Alice is on the project (roster)
        $this->addProjectMember($project, $alice, ProjectRole::Member);

        $this->actingAs($owner)
            ->get(route('projects.board', $project->id))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($owner, $alice) {
                $page->has('projectPeople', 2); // owner + alice only
                $people = collect($page->toArray()['props']['projectPeople']);
                $ids = $people->pluck('id')->all();
                expect($ids)->toContain($owner->id, $alice->id);
            });
    }

    public function test_board_project_people_includes_current_user_id_for_me_option(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();

        $this->actingAs($owner)
            ->get(route('projects.board', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('currentUserId', $owner->id));
    }

    // ── Cross-workspace isolation ─────────────────────────────────────

    public function test_board_filter_access_is_denied_cross_workspace(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();

        $intruder = User::factory()->create();
        CreateWorkspace::run($intruder, ['name' => 'Theirs']);

        $this->actingAs($intruder)
            ->get(route('projects.board', [$project->id, 'assignee' => $owner->id]))
            ->assertNotFound();
    }
}
