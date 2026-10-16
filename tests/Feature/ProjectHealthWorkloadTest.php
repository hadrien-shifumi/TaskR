<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\SetAssignees;
use App\Actions\Projects\CreateProject;
use App\Actions\TimeTracking\CreateTimeEntry;
use App\Actions\TimeTracking\SetIssueEstimate;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\ProjectRole;
use App\Models\Issue;
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

class ProjectHealthWorkloadTest extends TestCase
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

    private function createIssue(Project $project, array $statuses, string $category, string $title = 'Issue', ?User $assignee = null): Issue
    {
        $issue = CreateIssue::run(app(WorkspaceContext::class)->getOrFail(), [
            'project_id' => $project->id,
            'title' => $title,
            'status_id' => $statuses[$category]->id,
        ]);

        if ($assignee !== null) {
            SetAssignees::run($issue, [$assignee->id]);
        }

        return $issue;
    }

    // ── Member (non-manager) sees only their workload ─────────────────

    public function test_member_sees_only_their_workload_no_global_kpis(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $member, ProjectRole::Member);

        // Create issues: some assigned to member, some to owner
        $this->createIssue($project, $statuses, 'in_progress', 'My task', $member);
        $this->createIssue($project, $statuses, 'backlog', 'Other task', $owner);
        $this->createIssue($project, $statuses, 'done', 'Done task', $member);

        $this->actingAs($member)
            ->get(route('projects.health', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Health')
                // Member should NOT see global KPIs
                ->where('health', null)
                // Member SHOULD see their own workload
                ->where('myWorkload.openIssues', 1)
                ->where('myWorkload.totalIssues', 2)
                ->where('canSeeGlobalKpis', false));
    }

    public function test_member_workload_includes_estimates_and_logged_hours(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $member, ProjectRole::Member);

        $issue = $this->createIssue($project, $statuses, 'in_progress', 'My task', $member);
        SetIssueEstimate::run($issue, 8.0);
        CreateTimeEntry::run($issue, $member, ['hours' => 3.0]);

        $this->actingAs($member)
            ->get(route('projects.health', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('myWorkload.estimatedHours', 8)
                ->where('myWorkload.loggedHours', 3));
    }

    public function test_member_workload_counts_overdue_issues(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $member, ProjectRole::Member);

        CreateIssue::run(app(WorkspaceContext::class)->getOrFail(), [
            'project_id' => $project->id,
            'title' => 'Overdue task',
            'status_id' => $statuses['in_progress']->id,
            'due_date' => now()->subDay()->toDateString(),
            'assignees' => [$member->id],
        ]);

        $this->actingAs($member)
            ->get(route('projects.health', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('myWorkload.overdueIssues', 1));
    }

    // ── Owner/Manager sees global KPIs + team workload ────────────────

    public function test_project_owner_sees_global_kpis_and_team_workload(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $member, ProjectRole::Member);

        $this->createIssue($project, $statuses, 'backlog', 'Task 1', $member);
        $this->createIssue($project, $statuses, 'in_progress', 'Task 2', $owner);
        $this->createIssue($project, $statuses, 'done', 'Task 3');

        $this->actingAs($owner)
            ->get(route('projects.health', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Health')
                // Owner SHOULD see global KPIs
                ->where('health.totalIssues', 3)
                ->where('canSeeGlobalKpis', true)
                // Owner SHOULD see team workload
                ->has('teamWorkload', 2));
    }

    public function test_project_manager_sees_global_kpis_and_team_workload(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        $manager = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $manager, ProjectRole::Manager);

        $this->createIssue($project, $statuses, 'backlog', 'Task 1');

        $this->actingAs($manager)
            ->get(route('projects.health', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('health.totalIssues', 1)
                ->where('canSeeGlobalKpis', true)
                ->has('teamWorkload', 2));
    }

    public function test_workspace_owner_sees_global_kpis_as_super_manager(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();

        // Create a second project where owner is NOT the project owner
        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Other']);
        $projectOwner = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($otherProject, $projectOwner, ProjectRole::Owner);

        $this->createIssue($otherProject, $statuses, 'backlog', 'Task 1');

        // Workspace owner can see global KPIs even on projects they don't own
        $this->actingAs($owner)
            ->get(route('projects.health', $otherProject->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('health.totalIssues', 1)
                ->where('canSeeGlobalKpis', true));
    }

    // ── Team workload details ─────────────────────────────────────────

    public function test_team_workload_lists_all_roster_members(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        $manager = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $manager, ProjectRole::Manager);
        $member = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $member, ProjectRole::Member);

        $this->createIssue($project, $statuses, 'in_progress', 'Task for manager', $manager);
        $this->createIssue($project, $statuses, 'backlog', 'Task for member', $member);

        $this->actingAs($owner)
            ->get(route('projects.health', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('teamWorkload', 3) // owner + manager + member
                ->where('teamWorkload.0.userId', $owner->id)
                ->where('teamWorkload.0.openIssues', 0)
                ->where('teamWorkload.1.userId', $manager->id)
                ->where('teamWorkload.1.openIssues', 1)
                ->where('teamWorkload.2.userId', $member->id)
                ->where('teamWorkload.2.openIssues', 1));
    }

    public function test_team_workload_includes_hours_per_person(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $member, ProjectRole::Member);

        $issue = $this->createIssue($project, $statuses, 'in_progress', 'Task', $member);
        SetIssueEstimate::run($issue, 10.0);
        CreateTimeEntry::run($issue, $member, ['hours' => 4.0]);

        $this->actingAs($owner)
            ->get(route('projects.health', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('teamWorkload', 2)
                ->where('teamWorkload.1.estimatedHours', 10)
                ->where('teamWorkload.1.loggedHours', 4));
    }

    // ── Cross-workspace isolation ─────────────────────────────────────

    public function test_health_access_is_denied_cross_workspace(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();

        $intruder = User::factory()->create();
        CreateWorkspace::run($intruder, ['name' => 'Theirs']);

        $this->actingAs($intruder)
            ->get(route('projects.health', $project->id))
            ->assertNotFound();
    }

    // ── Member not in roster still sees their workload ────────────────

    public function test_member_not_in_roster_sees_their_workload(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);
        // Note: member is NOT added to the project roster

        $this->createIssue($project, $statuses, 'in_progress', 'Assigned to me', $member);

        $this->actingAs($member)
            ->get(route('projects.health', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('health', null)
                ->where('myWorkload.openIssues', 1)
                ->where('canSeeGlobalKpis', false));
    }
}
