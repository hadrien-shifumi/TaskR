<?php

namespace Tests\Feature;

use App\Actions\Audit\RecordActivity;
use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\SetAssignees;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\AuditEventType;
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

class ProjectMemberActivityTest extends TestCase
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

    private function createIssue(Workspace $workspace, Project $project, array $statuses, string $category, string $title, ?string $priority = null, ?User $assignee = null): Issue
    {
        $issue = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => $title,
            'status_id' => $statuses[$category]->id,
            ...($priority !== null ? ['priority' => $priority] : []),
        ]);

        if ($assignee !== null) {
            SetAssignees::run($issue, [$assignee->id]);
        }

        return $issue;
    }

    // ── Access control ──────────────────────────────────────────────────

    public function test_member_activity_page_requires_authentication(): void
    {
        [$owner, , $project] = $this->setupWorkspaceWithProject();

        $this->get(route('projects.memberActivity', ['project' => $project->id, 'member' => $owner->id]))
            ->assertRedirect(route('login'));
    }

    public function test_member_activity_page_is_denied_for_a_simple_member(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $member);

        $this->actingAs($member)
            ->get(route('projects.memberActivity', ['project' => $project->id, 'member' => $member->id]))
            ->assertForbidden();
    }

    public function test_member_activity_page_is_denied_for_a_guest(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $member);

        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);

        $this->actingAs($guest)
            ->get(route('projects.memberActivity', ['project' => $project->id, 'member' => $member->id]))
            ->assertForbidden();
    }

    public function test_member_activity_page_is_not_found_for_member_outside_the_project(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        $outsider = $this->addWorkspaceMember($workspace);

        $this->actingAs($owner)
            ->get(route('projects.memberActivity', ['project' => $project->id, 'member' => $outsider->id]))
            ->assertNotFound();
    }

    // ── Member activity payload ─────────────────────────────────────────

    public function test_member_activity_page_returns_the_members_open_assigned_issues(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $member);

        $inProgress = $this->createIssue($workspace, $project, $statuses, 'in_progress', 'Travail en cours', 'high', $member);
        $backlog = $this->createIssue($workspace, $project, $statuses, 'backlog', 'Idée', null, $member);
        $this->createIssue($workspace, $project, $statuses, 'done', 'Terminé', null, $member);
        $this->createIssue($workspace, $project, $statuses, 'in_progress', 'Chercheur', null, $owner);

        $this->actingAs($owner)
            ->get(route('projects.memberActivity', ['project' => $project->id, 'member' => $member->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/MemberActivity')
                ->where('member.id', $member->id)
                ->where('member.name', $member->name)
                ->has('assignedIssues', 2)
                ->where('assignedIssues.0.id', $inProgress->id)
                ->where('assignedIssues.0.title', 'Travail en cours')
                ->where('assignedIssues.0.statusName', 'In Progress')
                ->where('assignedIssues.0.statusCategory', 'in_progress')
                ->where('assignedIssues.0.priority', 'high')
                ->where('assignedIssues.1.id', $backlog->id)
            );
    }

    public function test_member_activity_page_returns_the_member_events_paginated(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $member);

        $issue = $this->createIssue($workspace, $project, $statuses, 'in_progress', 'Page de profil');

        $statusChanged = RecordActivity::run(
            AuditEventType::IssueStatusChanged,
            $issue,
            $member,
            ['status' => ['before' => 'Backlog', 'after' => 'In Progress']],
        );

        $commentCreated = RecordActivity::run(
            AuditEventType::CommentCreated,
            $issue,
            $member,
            ['body' => ['before' => null, 'after' => 'Retour du collègue']],
        );

        $this->actingAs($owner)
            ->get(route('projects.memberActivity', ['project' => $project->id, 'member' => $member->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/MemberActivity')
                ->has('events', 2)
                ->where('events.0.type', 'comment.created')
                ->where('events.0.actor.id', $member->id)
                ->where('events.1.type', 'issue.status_changed')
                ->where('pagination.currentPage', 1)
                ->where('pagination.lastPage', 1)
                ->where('pagination.perPage', 20)
                ->where('pagination.total', 2));
    }

    public function test_member_activity_page_invalid_page_falls_back_to_the_first_page(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $member);

        $issue = $this->createIssue($workspace, $project, $statuses, 'in_progress', 'Page de profil');

        RecordActivity::run(AuditEventType::CommentCreated, $issue, $member, [
            'body' => ['before' => null, 'after' => 'Retour du collègue'],
        ]);

        foreach (['abc', '-3', '0'] as $rawPage) {
            $this->actingAs($owner)
                ->get(route('projects.memberActivity', ['project' => $project->id, 'member' => $member->id, 'page' => $rawPage]))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('Projects/MemberActivity')
                    ->has('events', 1)
                    ->where('pagination.currentPage', 1));
        }
    }
}
