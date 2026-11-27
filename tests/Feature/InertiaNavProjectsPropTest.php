<?php

namespace Tests\Feature;

use App\Actions\Projects\ArchiveProject;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The sidebar renders a sub-list of the workspace's projects under the
 * "Projects" nav entry, straight from the lazy shared `navProjects`
 * prop. Each test pins the payload contract: shape, ordering, active
 * projects only, and the account's perimeter.
 */
class InertiaNavProjectsPropTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function setupWorkspace(): array
    {
        $owner = User::factory()->create(['name' => 'Owner', 'handle' => 'owner']);
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace];
    }

    private function addUser(Workspace $workspace, string $role, string $handle, string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'handle' => $handle]);
        Membership::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'role' => $role,
        ]);

        return $user;
    }

    public function test_the_shared_nav_projects_prop_lists_active_projects_ordered_by_name(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $zeta = CreateProject::run($workspace, $owner, ['name' => 'Zeta Site']);
        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha App']);
        $dead = CreateProject::run($workspace, $owner, ['name' => 'Archived Thing']);
        ArchiveProject::run($dead, $owner);

        $this->actingAs($owner)
            ->withSession(['workspace_id' => $workspace->id])
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('navProjects', 2)
                // Name order, whatever the creation order was.
                ->where('navProjects.0.id', $alpha->id)
                ->where('navProjects.0.name', 'Alpha App')
                ->where('navProjects.1.id', $zeta->id)
                ->where('navProjects.1.name', 'Zeta Site')
                // The nav needs nothing more than the pair.
                ->missing('navProjects.0.description')
                ->missing('navProjects.0.health')
                // Archived projects stay out of the sidebar.
                ->missing('navProjects.2'));
    }

    public function test_the_shared_nav_projects_prop_follows_the_account_scope(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $rosterProject = CreateProject::run($workspace, $owner, ['name' => 'Roster One']);
        CreateProject::run($workspace, $owner, ['name' => 'Hidden Two']);
        $gina = $this->addUser($workspace, 'guest', 'gina', 'Gina');
        $rosterProject->projectMembers()->create(['user_id' => $gina->id, 'role' => 'member']);

        // Another workspace of the owner must never leak into this one's nav.
        // Project creation auto-fills workspace_id from the WorkspaceContext,
        // so the context points at the foreign workspace for this call.
        $other = CreateWorkspace::run($owner, ['name' => 'Other WS']);
        app(WorkspaceContext::class)->set($other);
        CreateProject::run($other, $owner, ['name' => 'Foreign Project']);
        app(WorkspaceContext::class)->set($workspace);

        // Full member: every project of the current workspace, nothing foreign.
        $this->actingAs($owner)
            ->withSession(['workspace_id' => $workspace->id])
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('navProjects', 2)
                ->where('navProjects.0.name', 'Hidden Two')
                ->where('navProjects.1.name', 'Roster One'));

        // Guest: only the projects of their roster.
        $this->actingAs($gina)
            ->withSession(['workspace_id' => $workspace->id])
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('navProjects', 1)
                ->where('navProjects.0.id', $rosterProject->id)
                ->where('navProjects.0.name', 'Roster One'));
    }
}
