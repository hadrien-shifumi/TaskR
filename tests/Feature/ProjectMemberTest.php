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

    // ── Enum ProjectRole ──────────────────────────────────────────────

    public function test_project_role_enum_has_three_cases(): void
    {
        $this->assertSame('owner', ProjectRole::Owner->value);
        $this->assertSame('manager', ProjectRole::Manager->value);
        $this->assertSame('member', ProjectRole::Member->value);
        $this->assertCount(3, ProjectRole::cases());
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
