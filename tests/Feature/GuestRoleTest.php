<?php

namespace Tests\Feature;

use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\WorkspaceRole;
use App\Models\Membership;
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

    public function test_guest_can_navigate_project_board(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $guest = $this->addUser($workspace, 'guest');
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

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
