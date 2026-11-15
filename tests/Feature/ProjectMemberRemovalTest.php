<?php

namespace Tests\Feature;

use App\Actions\Audit\RecordActivity;
use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\SetAssignees;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\AuditEventType;
use App\Enums\ProjectRole;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectMemberRemovalTest extends TestCase
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

    private function createIssue(Workspace $workspace, Project $project, array $statuses, string $title, ?User $assignee = null): Issue
    {
        $issue = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => $title,
            'status_id' => $statuses['backlog']->id,
        ]);

        if ($assignee !== null) {
            SetAssignees::run($issue, [$assignee->id]);
        }

        return $issue;
    }

    // ── D14 : le retrait libère les assignations ──────────────────────

    public function test_removing_a_member_frees_their_assignations_on_the_project(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $member);

        $issue = $this->createIssue($workspace, $project, $statuses, 'Refonte du header', $member);
        $this->createIssue($workspace, $project, $statuses, 'Correctif moteur', $member);

        $this->actingAs($owner)
            ->delete(route('projects.members.destroy', [$project->id, $member->id]))
            ->assertRedirect();

        // The roster row is gone and no issue of the project keeps the
        // removed teammate attached.
        $this->assertDatabaseMissing('project_members', [
            'project_id' => $project->id,
            'user_id' => $member->id,
        ]);
        $this->assertDatabaseMissing('issue_assignees', [
            'user_id' => $member->id,
        ]);
        $this->assertDatabaseHas('issues', ['id' => $issue->id]);
    }

    public function test_removing_a_member_keeps_the_other_assignees_attached(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $member);

        $issue = $this->createIssue($workspace, $project, $statuses, 'Refonte du header');
        SetAssignees::run($issue, [$member->id, $owner->id]);

        $this->actingAs($owner)
            ->delete(route('projects.members.destroy', [$project->id, $member->id]))
            ->assertRedirect();

        // Only the removed teammate is detached: the other assignees
        // keep their row on the shared issue.
        $this->assertDatabaseMissing('issue_assignees', [
            'issue_id' => $issue->id,
            'user_id' => $member->id,
        ]);
        $this->assertDatabaseHas('issue_assignees', [
            'issue_id' => $issue->id,
            'user_id' => $owner->id,
        ]);
    }

    public function test_removing_a_member_keeps_their_assignations_on_other_projects(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $member);

        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Application mobile']);
        $this->addProjectMember($otherProject, $member);
        $otherIssue = $this->createIssue($workspace, $otherProject, $statuses, 'Ecran de chargement', $member);

        $this->actingAs($owner)
            ->delete(route('projects.members.destroy', [$project->id, $member->id]))
            ->assertRedirect();

        // The liberation is project-scoped: the teammate stays assigned
        // where they keep a roster row.
        $this->assertDatabaseHas('issue_assignees', [
            'issue_id' => $otherIssue->id,
            'user_id' => $member->id,
        ]);
    }

    public function test_removing_a_member_keeps_issues_comments_and_activity(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $member);

        $issue = $this->createIssue($workspace, $project, $statuses, 'Refonte du header', $member);

        Comment::create([
            'issue_id' => $issue->id,
            'author_id' => $member->id,
            'body' => 'Je prends la banniere',
        ]);

        RecordActivity::run(AuditEventType::IssueAssigned, $issue, $owner, [
            'assignees_added' => [$member->id],
        ]);

        $this->actingAs($owner)
            ->delete(route('projects.members.destroy', [$project->id, $member->id]))
            ->assertRedirect();

        // D14: history is conserved — the removal only takes the roster
        // row and the project's assignation rows.
        $this->assertDatabaseHas('issues', ['id' => $issue->id]);
        $this->assertDatabaseHas('comments', [
            'issue_id' => $issue->id,
            'author_id' => $member->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'issue_id' => $issue->id,
            'type' => 'issue.assigned',
        ]);
    }
}
