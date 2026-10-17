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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestScopePilotageTest extends TestCase
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
     * A workspace member promoted project manager on the given project.
     */
    private function projectManager(Workspace $workspace, Project $project): User
    {
        $manager = $this->addWorkspaceMember($workspace);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $manager->id,
            'role' => ProjectRole::Manager->value,
        ]);

        return $manager;
    }

    // ── Pilotage Owner-only des lignes de roster guest ────────────────

    public function test_workspace_owner_can_add_a_guest_to_the_project_roster(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $guest = $this->addWorkspaceMember($workspace, 'guest');

        $this->actingAs($owner)
            ->post(route('projects.members.store', $project->id), [
                'user_id' => $guest->id,
                'role' => 'member',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $guest->id,
            'role' => 'member',
        ]);
    }

    public function test_project_manager_cannot_add_a_guest_to_the_project_roster(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $manager = $this->projectManager($workspace, $project);
        $guest = $this->addWorkspaceMember($workspace, 'guest');

        $this->actingAs($manager)
            ->post(route('projects.members.store', $project->id), [
                'user_id' => $guest->id,
                'role' => 'member',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('project_members', [
            'project_id' => $project->id,
            'user_id' => $guest->id,
        ]);
    }

    public function test_project_owner_cannot_change_a_guest_roster_role(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $guest = $this->addWorkspaceMember($workspace, 'guest');

        // A project owner who is not the workspace owner cannot pilot a guest.
        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Autre projet']);
        $projectOwner = $this->addWorkspaceMember($workspace);
        ProjectMember::create([
            'project_id' => $otherProject->id,
            'user_id' => $projectOwner->id,
            'role' => ProjectRole::Owner->value,
        ]);
        ProjectMember::create([
            'project_id' => $otherProject->id,
            'user_id' => $guest->id,
            'role' => ProjectRole::Member->value,
        ]);

        $this->actingAs($projectOwner)
            ->patch(route('projects.members.update', [$otherProject->id, $guest->id]), [
                'role' => 'manager',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $otherProject->id,
            'user_id' => $guest->id,
            'role' => 'member',
        ]);
    }

    public function test_project_manager_cannot_remove_a_guest_from_the_roster(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $manager = $this->projectManager($workspace, $project);
        $guest = $this->addWorkspaceMember($workspace, 'guest');
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $guest->id,
            'role' => ProjectRole::Member->value,
        ]);
        $secondProject = CreateProject::run($workspace, $owner, ['name' => 'Deuxieme']);
        ProjectMember::create([
            'project_id' => $secondProject->id,
            'user_id' => $guest->id,
            'role' => ProjectRole::Member->value,
        ]);

        $this->actingAs($manager)
            ->delete(route('projects.members.destroy', [$project->id, $guest->id]))
            ->assertForbidden();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $guest->id,
        ]);
    }

    // ── Règles métier sur le périmètre des guests ─────────────────────

    public function test_workspace_owner_can_remove_a_guest_with_remaining_scope(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $guest = $this->addWorkspaceMember($workspace, 'guest');
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $guest->id,
            'role' => ProjectRole::Member->value,
        ]);
        $secondProject = CreateProject::run($workspace, $owner, ['name' => 'Deuxieme']);
        ProjectMember::create([
            'project_id' => $secondProject->id,
            'user_id' => $guest->id,
            'role' => ProjectRole::Member->value,
        ]);

        $this->actingAs($owner)
            ->delete(route('projects.members.destroy', [$project->id, $guest->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('project_members', [
            'project_id' => $project->id,
            'user_id' => $guest->id,
        ]);
        $this->assertDatabaseHas('project_members', [
            'project_id' => $secondProject->id,
            'user_id' => $guest->id,
        ]);
    }

    public function test_workspace_owner_can_change_a_guest_roster_role(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $guest = $this->addWorkspaceMember($workspace, 'guest');
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $guest->id,
            'role' => ProjectRole::Member->value,
        ]);

        $this->actingAs($owner)
            ->patch(route('projects.members.update', [$project->id, $guest->id]), [
                'role' => 'manager',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $guest->id,
            'role' => 'manager',
        ]);
    }

    public function test_workspace_owner_cannot_transfer_project_ownership_to_a_guest(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $guest = $this->addWorkspaceMember($workspace, 'guest');
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $guest->id,
            'role' => ProjectRole::Member->value,
        ]);

        $this->actingAs($owner)
            ->patch(route('projects.members.update', [$project->id, $guest->id]), [
                'role' => 'owner',
            ])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $guest->id,
            'role' => 'member',
        ]);
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
    }

    public function test_removing_a_guest_from_their_last_project_is_refused(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $guest = $this->addWorkspaceMember($workspace, 'guest');
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $guest->id,
            'role' => ProjectRole::Member->value,
        ]);

        $this->actingAs($owner)
            ->delete(route('projects.members.destroy', [$project->id, $guest->id]))
            ->assertSessionHasErrors('user_id');

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $guest->id,
        ]);
    }

    // ── Nettoyage au retrait du workspace ─────────────────────────────

    public function test_removing_a_member_from_the_workspace_cleans_their_roster_rows(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $guest = $this->addWorkspaceMember($workspace, 'guest');
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $guest->id,
            'role' => ProjectRole::Member->value,
        ]);

        $this->actingAs($owner)
            ->delete(route('workspaces.members.destroy', [$workspace->id, $guest->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('project_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $guest->id,
        ]);
    }
}
