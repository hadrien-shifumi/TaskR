<?php

namespace Tests\Feature;

use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestRoleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace];
    }

    private function addUser(Workspace $workspace, string $role): User
    {
        $user = User::factory()->create();
        Membership::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'role' => $role,
        ]);

        return $user;
    }

    public function test_guest_membership_is_stored_and_cast(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $guest = $this->addUser($workspace, 'guest');

        $membership = Membership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $guest->id)
            ->firstOrFail();

        $this->assertSame(WorkspaceRole::Guest, $membership->role);
        $this->assertSame(WorkspaceRole::Guest, $workspace->roleOf($guest));
    }

    public function test_is_contributor_follows_the_project_role_only(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        // The workspace owner is a contributor everywhere, without a
        // team row (god mode, D3).
        $this->assertTrue($project->isContributor($owner));

        // Every roster line except Guest contributes, whatever the
        // workspace role (orthogonality, D7).
        foreach ([ProjectRole::Owner, ProjectRole::Manager, ProjectRole::Member] as $role) {
            $workspaceGuest = $this->addUser($workspace, 'guest');
            ProjectMember::create([
                'project_id' => $project->id,
                'user_id' => $workspaceGuest->id,
                'role' => $role->value,
            ]);

            $this->assertTrue($project->isContributor($workspaceGuest), $role->value);
        }

        // The roster role Guest never contributes, even for a full
        // workspace member.
        $member = $this->addUser($workspace, 'member');
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Guest->value,
        ]);
        $this->assertFalse($project->isContributor($member));

        // No team row, no contribution — even for a workspace member.
        $teamed = $this->addUser($workspace, 'member');
        $this->assertFalse($project->isContributor($teamed));
    }

    public function test_guest_can_navigate_project_board(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $guest = $this->addUser($workspace, 'guest');
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        // The board is only reachable within the guest's scope: onboard
        // them onto the project roster first.
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $guest->id,
            'role' => 'member',
        ]);

        $this->actingAs($guest)
            ->get(route('projects.board', $project))
            ->assertOk();
    }

    public function test_guest_cannot_open_members_page(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addUser($workspace, 'member');
        $guest = $this->addUser($workspace, 'guest');

        $this->actingAs($member)
            ->get(route('settings.members'))
            ->assertOk();

        $this->actingAs($guest)
            ->get(route('settings.members'))
            ->assertForbidden();
    }

    public function test_guest_cannot_create_labels(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addUser($workspace, 'member');
        $guest = $this->addUser($workspace, 'guest');

        $this->actingAs($guest)
            ->post(route('labels.store'), ['name' => 'Urgent'])
            ->assertForbidden();

        $this->actingAs($member)
            ->post(route('labels.store'), ['name' => 'Urgent'])
            ->assertRedirect();
    }

    public function test_guest_cannot_manage_api_tokens(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addUser($workspace, 'member');
        $guest = $this->addUser($workspace, 'guest');

        $guestToken = $guest->createToken('Ancien jeton');

        $this->actingAs($guest)
            ->get(route('settings.tokens'))
            ->assertForbidden();

        $this->actingAs($guest)
            ->post(route('tokens.store'), ['name' => 'Agent'])
            ->assertForbidden();

        $this->actingAs($guest)
            ->delete(route('tokens.destroy', $guestToken->accessToken->id))
            ->assertForbidden();

        $this->actingAs($member)
            ->get(route('settings.tokens'))
            ->assertOk();

        $this->actingAs($member)
            ->post(route('tokens.store'), ['name' => 'Agent'])
            ->assertRedirect();
    }
}
