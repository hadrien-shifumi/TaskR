<?php

namespace Tests\Feature;

use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\ProjectRole;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProjectMemberTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace, 2: Project}
     */
    private function setupWorkspaceWithProject(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        return [$owner, $workspace, $project];
    }

    /**
     * Add a member to the workspace (not the project roster).
     */
    private function addWorkspaceMember(Workspace $workspace, string $role = 'member'): User
    {
        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => $role,
        ]);

        return $member;
    }

    /**
     * Give a workspace member a roster row on the project.
     */
    private function addProjectMember(Project $project, User $user, ProjectRole $role = ProjectRole::Member): void
    {
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'role' => $role->value,
        ]);
    }

    // ── Enum ProjectRole ──────────────────────────────────────────────

    public function test_project_role_enum_has_four_cases(): void
    {
        $this->assertSame('owner', ProjectRole::Owner->value);
        $this->assertSame('manager', ProjectRole::Manager->value);
        $this->assertSame('member', ProjectRole::Member->value);
        $this->assertSame('guest', ProjectRole::Guest->value);
        $this->assertCount(4, ProjectRole::cases());
    }

    public function test_project_role_rank_orders_the_n_plus_1_hierarchy(): void
    {
        $this->assertSame(3, ProjectRole::Owner->rank());
        $this->assertSame(2, ProjectRole::Manager->rank());
        $this->assertSame(1, ProjectRole::Member->rank());
        $this->assertSame(0, ProjectRole::Guest->rank());

        // Strict superiority only: never a pair, never an inferior.
        $this->assertTrue(ProjectRole::Owner->outranks(ProjectRole::Manager));
        $this->assertTrue(ProjectRole::Manager->outranks(ProjectRole::Member));
        $this->assertTrue(ProjectRole::Member->outranks(ProjectRole::Guest));
        $this->assertFalse(ProjectRole::Owner->outranks(ProjectRole::Owner));
        $this->assertFalse(ProjectRole::Manager->outranks(ProjectRole::Manager));
        $this->assertFalse(ProjectRole::Guest->outranks(ProjectRole::Owner));
    }

    // ── CreateProject auto-onboard ────────────────────────────────────

    public function test_project_creator_becomes_project_owner(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
    }

    public function test_project_role_of_returns_project_role(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();

        $this->assertSame(ProjectRole::Owner, $project->roleOf($owner));
    }

    public function test_project_role_of_returns_null_for_non_member(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $outsider = User::factory()->create();

        $this->assertNull($project->roleOf($outsider));
    }

    // ── Project helpers ───────────────────────────────────────────────

    public function test_project_is_manager_returns_true_for_owner(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();

        $this->assertTrue($project->isManager($owner));
    }

    public function test_project_is_manager_returns_true_for_manager(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $manager = $this->addWorkspaceMember($workspace);

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $manager->id,
            'role' => ProjectRole::Manager->value,
        ]);

        $this->assertTrue($project->isManager($manager));
    }

    public function test_project_is_manager_returns_false_for_regular_member(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Member->value,
        ]);

        $this->assertFalse($project->isManager($member));
    }

    public function test_project_is_manager_returns_false_for_guest_role(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Guest->value,
        ]);

        $this->assertFalse($project->isManager($member));
    }

    public function test_project_can_manage_returns_true_for_workspace_owner(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();

        // Workspace owner is super-manager even without project role
        $this->assertTrue($project->canManage($owner));
    }

    public function test_project_can_manage_returns_true_for_project_owner(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();

        // Create a second project and make a workspace member its owner
        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Other']);
        $projectOwner = $this->addWorkspaceMember($workspace);
        ProjectMember::create([
            'project_id' => $otherProject->id,
            'user_id' => $projectOwner->id,
            'role' => ProjectRole::Owner->value,
        ]);

        // Project owner can manage their project
        $this->assertTrue($otherProject->canManage($projectOwner));
        // But cannot manage a different project (they are not owner/manager there)
        $this->assertFalse($project->canManage($projectOwner));
        // Workspace owner can manage ANY project (super-manager)
        $this->assertTrue($project->canManage($owner));
        $this->assertTrue($otherProject->canManage($owner));
    }

    public function test_project_can_manage_returns_true_for_project_manager(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $manager = $this->addWorkspaceMember($workspace);

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $manager->id,
            'role' => ProjectRole::Manager->value,
        ]);

        $this->assertTrue($project->canManage($manager));
    }

    public function test_project_can_manage_returns_false_for_regular_member(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);

        // Add to project as regular member
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Member->value,
        ]);

        $this->assertFalse($project->canManage($member));
    }

    // ── Project members relation ──────────────────────────────────────

    public function test_project_members_relation_returns_members_with_role(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Member->value,
        ]);

        $members = $project->members()->get();

        $this->assertCount(2, $members);
        $this->assertTrue($members->contains($owner));
        $this->assertTrue($members->contains($member));
    }

    // ── AddProjectMember action ───────────────────────────────────────

    public function test_add_project_member_adds_workspace_member_to_roster(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);

        $this->actingAs($owner)
            ->post(route('projects.members.store', $project->id), [
                'user_id' => $member->id,
                'role' => 'member',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);
    }

    public function test_add_project_member_defaults_role_to_member(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);

        $this->actingAs($owner)
            ->post(route('projects.members.store', $project->id), [
                'user_id' => $member->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);
    }

    public function test_add_project_member_rejects_non_workspace_member(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $outsider = User::factory()->create();

        $this->actingAs($owner)
            ->post(route('projects.members.store', $project->id), [
                'user_id' => $outsider->id,
                'role' => 'member',
            ])
            ->assertSessionHasErrors('user_id');
    }

    public function test_add_project_member_rejects_second_owner(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);

        $this->actingAs($owner)
            ->post(route('projects.members.store', $project->id), [
                'user_id' => $member->id,
                'role' => 'owner',
            ])
            ->assertSessionHasErrors();
    }

    public function test_add_project_member_accepts_the_guest_role(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);

        $this->actingAs($owner)
            ->post(route('projects.members.store', $project->id), [
                'user_id' => $member->id,
                'role' => 'guest',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => 'guest',
        ]);
    }

    public function test_add_project_member_is_denied_for_regular_member(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Member->value,
        ]);
        $another = $this->addWorkspaceMember($workspace);

        $this->actingAs($member)
            ->post(route('projects.members.store', $project->id), [
                'user_id' => $another->id,
                'role' => 'member',
            ])
            ->assertForbidden();
    }

    public function test_add_project_member_is_denied_cross_workspace(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();

        $intruder = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($intruder, ['name' => 'Theirs']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $otherMember = $this->addWorkspaceMember($otherWorkspace);

        $this->actingAs($intruder)
            ->post(route('projects.members.store', $project->id), [
                'user_id' => $otherMember->id,
                'role' => 'member',
            ])
            ->assertNotFound();
    }

    // ── RemoveProjectMember action ────────────────────────────────────

    public function test_remove_project_member_removes_from_roster(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Member->value,
        ]);

        $this->actingAs($owner)
            ->delete(route('projects.members.destroy', [$project->id, $member->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('project_members', [
            'project_id' => $project->id,
            'user_id' => $member->id,
        ]);
    }

    public function test_remove_project_member_cannot_remove_owner(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $manager = $this->addWorkspaceMember($workspace);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $manager->id,
            'role' => ProjectRole::Manager->value,
        ]);

        $this->actingAs($owner)
            ->delete(route('projects.members.destroy', [$project->id, $owner->id]))
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
    }

    public function test_remove_project_member_is_denied_for_regular_member(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Member->value,
        ]);
        $another = $this->addWorkspaceMember($workspace);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $another->id,
            'role' => ProjectRole::Member->value,
        ]);

        $this->actingAs($member)
            ->delete(route('projects.members.destroy', [$project->id, $another->id]))
            ->assertForbidden();
    }

    // ── UpdateProjectMemberRole action ────────────────────────────────

    public function test_update_project_member_role_promotes_to_manager(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Member->value,
        ]);

        $this->actingAs($owner)
            ->patch(route('projects.members.update', [$project->id, $member->id]), [
                'role' => 'manager',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => 'manager',
        ]);
    }

    public function test_update_project_member_role_accepts_the_guest_role_both_ways(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Member->value,
        ]);

        $this->actingAs($owner)
            ->patch(route('projects.members.update', [$project->id, $member->id]), [
                'role' => 'guest',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => 'guest',
        ]);

        $this->actingAs($owner)
            ->patch(route('projects.members.update', [$project->id, $member->id]), [
                'role' => 'member',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);
    }

    public function test_update_project_member_role_transfers_ownership(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Manager->value,
        ]);

        // Transfer ownership: member becomes owner, previous owner becomes member
        $this->actingAs($owner)
            ->patch(route('projects.members.update', [$project->id, $member->id]), [
                'role' => 'owner',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => 'owner',
        ]);
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'role' => 'member',
        ]);
    }

    public function test_update_project_member_role_is_denied_for_manager(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $manager = $this->addWorkspaceMember($workspace);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $manager->id,
            'role' => ProjectRole::Manager->value,
        ]);
        $member = $this->addWorkspaceMember($workspace);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Member->value,
        ]);

        // Manager cannot change roles (only owner can)
        $this->actingAs($manager)
            ->patch(route('projects.members.update', [$project->id, $member->id]), [
                'role' => 'manager',
            ])
            ->assertForbidden();
    }

    // ── N+1 : changement de rôle (D9) ─────────────────────────────────

    public function test_manager_can_promote_and_demote_between_member_and_guest(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $manager = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $manager, ProjectRole::Manager);
        $member = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $member);

        // Down to guest, back to member: both directions allowed.
        $this->actingAs($manager)
            ->patch(route('projects.members.update', [$project->id, $member->id]), [
                'role' => 'guest',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => 'guest',
        ]);

        $this->actingAs($manager)
            ->patch(route('projects.members.update', [$project->id, $member->id]), [
                'role' => 'member',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);
    }

    public function test_manager_cannot_change_the_role_of_another_manager(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $manager = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $manager, ProjectRole::Manager);
        $peer = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $peer, ProjectRole::Manager);

        $this->actingAs($manager)
            ->patch(route('projects.members.update', [$project->id, $peer->id]), [
                'role' => 'member',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $peer->id,
            'role' => 'manager',
        ]);
    }

    public function test_manager_cannot_change_the_role_of_the_project_owner(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $manager = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $manager, ProjectRole::Manager);

        $this->actingAs($manager)
            ->patch(route('projects.members.update', [$project->id, $owner->id]), [
                'role' => 'member',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
    }

    public function test_regular_member_cannot_change_roles(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $member);
        $guest = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $guest, ProjectRole::Guest);

        // A member never acts, not even on a strictly lower line.
        $this->actingAs($member)
            ->patch(route('projects.members.update', [$project->id, $guest->id]), [
                'role' => 'member',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $guest->id,
            'role' => 'guest',
        ]);
    }

    public function test_project_guest_cannot_change_roles(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $guest = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $guest, ProjectRole::Guest);
        $member = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $member);

        $this->actingAs($guest)
            ->patch(route('projects.members.update', [$project->id, $member->id]), [
                'role' => 'guest',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);
    }

    public function test_workspace_owner_bypasses_and_changes_any_role(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $manager = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $manager, ProjectRole::Manager);

        // D11: the workspace owner acts on every line, even a manager.
        $this->actingAs($owner)
            ->patch(route('projects.members.update', [$project->id, $manager->id]), [
                'role' => 'guest',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $manager->id,
            'role' => 'guest',
        ]);
    }

    public function test_manager_cannot_transfer_ownership(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $manager = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $manager, ProjectRole::Manager);
        $member = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $member);

        $this->actingAs($manager)
            ->patch(route('projects.members.update', [$project->id, $member->id]), [
                'role' => 'owner',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
    }

    public function test_project_owner_can_transfer_ownership(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();

        // A project owner who is not the workspace owner transfers too:
        // the workspace owner hands the project over first.
        $projectOwner = $this->addWorkspaceMember($workspace);
        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Autre projet']);
        $this->addProjectMember($otherProject, $projectOwner, ProjectRole::Manager);

        $this->actingAs($owner)
            ->patch(route('projects.members.update', [$otherProject->id, $projectOwner->id]), [
                'role' => 'owner',
            ])
            ->assertRedirect();

        $member = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($otherProject, $member, ProjectRole::Manager);

        $this->actingAs($projectOwner)
            ->patch(route('projects.members.update', [$otherProject->id, $member->id]), [
                'role' => 'owner',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $otherProject->id,
            'user_id' => $member->id,
            'role' => 'owner',
        ]);
        $this->assertDatabaseHas('project_members', [
            'project_id' => $otherProject->id,
            'user_id' => $projectOwner->id,
            'role' => 'member',
        ]);
    }

    public function test_promoted_manager_is_untouchable_by_peers_but_demotable_by_owners(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();

        // A project owner (not the workspace owner) promotes a member:
        // the workspace owner hands the project over first.
        $projectOwner = $this->addWorkspaceMember($workspace);
        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Autre projet']);
        $this->addProjectMember($otherProject, $projectOwner, ProjectRole::Manager);

        $this->actingAs($owner)
            ->patch(route('projects.members.update', [$otherProject->id, $projectOwner->id]), [
                'role' => 'owner',
            ])
            ->assertRedirect();

        $promoted = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($otherProject, $promoted);
        $peer = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($otherProject, $peer, ProjectRole::Manager);

        $this->actingAs($projectOwner)
            ->patch(route('projects.members.update', [$otherProject->id, $promoted->id]), [
                'role' => 'manager',
            ])
            ->assertRedirect();

        // The new manager outranks nobody above them: a peer manager
        // cannot touch the promoted line (D9).
        $this->actingAs($peer)
            ->patch(route('projects.members.update', [$otherProject->id, $promoted->id]), [
                'role' => 'member',
            ])
            ->assertForbidden();

        // The promoting project owner and the workspace owner still can.
        $this->actingAs($projectOwner)
            ->patch(route('projects.members.update', [$otherProject->id, $promoted->id]), [
                'role' => 'member',
            ])
            ->assertRedirect();

        $this->actingAs($owner)
            ->patch(route('projects.members.update', [$otherProject->id, $promoted->id]), [
                'role' => 'manager',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $otherProject->id,
            'user_id' => $promoted->id,
            'role' => 'manager',
        ]);
    }

    public function test_transferred_owner_becomes_manageable_again(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();

        // Ownership transfer: the previous owner lands on a plain member
        // line, back under the hierarchy.
        $newOwner = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $newOwner, ProjectRole::Manager);

        $this->actingAs($owner)
            ->patch(route('projects.members.update', [$project->id, $newOwner->id]), [
                'role' => 'owner',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'role' => 'member',
        ]);

        // The new owner outranks the demoted one and can move their line.
        $this->actingAs($newOwner)
            ->patch(route('projects.members.update', [$project->id, $owner->id]), [
                'role' => 'guest',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'role' => 'guest',
        ]);
    }

    // ── N+1 : ajout (D10) ────────────────────────────────────────────

    public function test_manager_can_add_a_member_and_a_guest(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $manager = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $manager, ProjectRole::Manager);
        $member = $this->addWorkspaceMember($workspace);
        $guest = $this->addWorkspaceMember($workspace);

        $this->actingAs($manager)
            ->post(route('projects.members.store', $project->id), [
                'user_id' => $member->id,
                'role' => 'member',
            ])
            ->assertRedirect();

        $this->actingAs($manager)
            ->post(route('projects.members.store', $project->id), [
                'user_id' => $guest->id,
                'role' => 'guest',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $guest->id,
            'role' => 'guest',
        ]);
    }

    public function test_manager_cannot_add_a_manager(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $manager = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $manager, ProjectRole::Manager);
        $peer = $this->addWorkspaceMember($workspace);

        // Onboarding a peer role would create an equal: refused.
        $this->actingAs($manager)
            ->post(route('projects.members.store', $project->id), [
                'user_id' => $peer->id,
                'role' => 'manager',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('project_members', [
            'project_id' => $project->id,
            'user_id' => $peer->id,
        ]);
    }

    public function test_project_owner_can_add_a_manager(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();

        $projectOwner = $this->addWorkspaceMember($workspace);
        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Autre projet']);
        $this->addProjectMember($otherProject, $projectOwner, ProjectRole::Manager);

        // Hand the project over so the actor owns it legitimately.
        $this->actingAs($owner)
            ->patch(route('projects.members.update', [$otherProject->id, $projectOwner->id]), [
                'role' => 'owner',
            ])
            ->assertRedirect();

        $manager = $this->addWorkspaceMember($workspace);

        $this->actingAs($projectOwner)
            ->post(route('projects.members.store', $otherProject->id), [
                'user_id' => $manager->id,
                'role' => 'manager',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $otherProject->id,
            'user_id' => $manager->id,
            'role' => 'manager',
        ]);
    }

    public function test_project_guest_cannot_add_anyone(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $guest = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $guest, ProjectRole::Guest);
        $member = $this->addWorkspaceMember($workspace);

        // Even a strictly lower role: a guest never pilots the roster.
        $this->actingAs($guest)
            ->post(route('projects.members.store', $project->id), [
                'user_id' => $member->id,
                'role' => 'guest',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('project_members', [
            'project_id' => $project->id,
            'user_id' => $member->id,
        ]);
    }

    // ── N+1 : retrait (D10) ──────────────────────────────────────────

    public function test_manager_can_remove_a_member(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $manager = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $manager, ProjectRole::Manager);
        $member = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $member);

        $this->actingAs($manager)
            ->delete(route('projects.members.destroy', [$project->id, $member->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('project_members', [
            'project_id' => $project->id,
            'user_id' => $member->id,
        ]);
    }

    public function test_manager_cannot_remove_another_manager(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $manager = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $manager, ProjectRole::Manager);
        $peer = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $peer, ProjectRole::Manager);

        $this->actingAs($manager)
            ->delete(route('projects.members.destroy', [$project->id, $peer->id]))
            ->assertForbidden();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $peer->id,
            'role' => 'manager',
        ]);
    }

    public function test_manager_cannot_remove_the_project_owner(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $manager = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $manager, ProjectRole::Manager);

        $this->actingAs($manager)
            ->delete(route('projects.members.destroy', [$project->id, $owner->id]))
            ->assertForbidden();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
    }

    public function test_workspace_owner_can_remove_a_manager(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $manager = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $manager, ProjectRole::Manager);

        // D11: the workspace owner bypasses the hierarchy everywhere.
        $this->actingAs($owner)
            ->delete(route('projects.members.destroy', [$project->id, $manager->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('project_members', [
            'project_id' => $project->id,
            'user_id' => $manager->id,
        ]);
    }

    // ── ShowProjectMembers (list) ─────────────────────────────────────

    public function test_show_project_members_lists_roster(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Member->value,
        ]);

        $this->actingAs($owner)
            ->get(route('projects.members.index', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Members')
                ->has('members', 2)
                ->where('members.0.id', $owner->id)
                ->where('members.0.role', 'owner')
                ->where('members.1.id', $member->id)
                ->where('members.1.role', 'member'));
    }

    public function test_show_project_members_is_accessible_to_regular_member(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Member->value,
        ]);

        $this->actingAs($member)
            ->get(route('projects.members.index', $project->id))
            ->assertOk();
    }

    public function test_show_project_members_exposes_rank_scoped_pilotage(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $manager = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $manager, ProjectRole::Manager);
        $peer = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $peer, ProjectRole::Manager);
        $member = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $member);

        // A manager pilots strictly lower roles only: the assignable
        // list and the per-row flags follow the rank, not canManage.
        $this->actingAs($manager)
            ->get(route('projects.members.index', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('assignableRoles', ['member', 'guest'])
                ->where('members.0.canManageRow', false)  // owner
                ->where('members.1.canManageRow', false)    // self, peer manager
                ->where('members.2.canManageRow', false)    // peer manager
                ->where('members.3.canManageRow', true));    // member
    }

    public function test_show_project_members_exposes_the_full_pilotage_to_the_workspace_owner(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $manager = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $manager, ProjectRole::Manager);

        // D11: the workspace owner bypasses — every non-owner line is
        // piloted and every role below owner is assignable.
        $this->actingAs($owner)
            ->get(route('projects.members.index', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('assignableRoles', ['manager', 'member', 'guest'])
                ->where('members.0.canManageRow', true)
                ->where('members.1.canManageRow', true));
    }

    public function test_show_project_members_exposes_no_pilotage_to_a_regular_member(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $member);
        $guest = $this->addWorkspaceMember($workspace);
        $this->addProjectMember($project, $guest, ProjectRole::Guest);

        // A member sees the roster but pilots nothing, not even a
        // strictly lower line.
        $this->actingAs($member)
            ->get(route('projects.members.index', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('assignableRoles', [])
                ->where('canManage', false)
                ->where('members.2.canManageRow', false));
    }

    // ── Data migration for existing projects ──────────────────────────

    public function test_existing_projects_get_workspace_owner_as_project_owner(): void
    {
        // Create workspace and project directly (simulating pre-migration state)
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        // Create a project directly via Eloquent (bypassing CreateProject action,
        // so no auto-onboard happens — simulating a project created before the feature)
        $project = Project::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Legacy project',
        ]);

        // Verify no project_members yet
        $this->assertDatabaseMissing('project_members', [
            'project_id' => $project->id,
        ]);

        // Run the data migration logic directly
        $migration = include database_path('migrations/2026_10_16_000000_migrate_existing_projects_to_project_members.php');
        $migration->up();

        // Workspace owner should now be project owner
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
    }

    // ── Unique constraint ─────────────────────────────────────────────

    public function test_project_member_unique_constraint_prevents_duplicates(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $member = $this->addWorkspaceMember($workspace);

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Member->value,
        ]);

        $this->expectException(QueryException::class);

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Manager->value,
        ]);
    }
}
