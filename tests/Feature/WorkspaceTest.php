<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_cannot_invite(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        $member = User::factory()->create();
        $workspace->memberships()->create([
            'user_id' => $member->getKey(),
            'role' => 'member',
        ]);

        $this->actingAs($member)
            ->post("/workspaces/{$workspace->id}/invitations", ['email' => 'someone@test.dev'])
            ->assertForbidden();
    }

    public function test_owner_can_invite(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        $response = $this->actingAs($owner)
            ->post("/workspaces/{$workspace->id}/invitations", ['email' => 'someone@test.dev']);

        $response->assertRedirect();
        $this->assertDatabaseHas('invitations', [
            'workspace_id' => $workspace->id,
            'email' => 'someone@test.dev',
        ]);
    }

    public function test_invitation_acceptance_adds_member(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        $member = User::factory()->create(['email' => 'member@test.dev']);

        $this->actingAs($owner)->post("/workspaces/{$workspace->id}/invitations", ['email' => 'member@test.dev']);

        $invitation = $workspace->fresh()->invitations()->firstOrFail();

        $this->actingAs($member)
            ->get("/invitations/{$invitation->token}/accept")
            ->assertRedirect();

        $this->assertDatabaseHas('memberships', [
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);
    }

    public function test_workspace_scoped_queries_only_see_current_workspace(): void
    {
        $ownerA = User::factory()->create();
        $workspaceA = CreateWorkspace::run($ownerA, ['name' => 'A']);
        $ownerB = User::factory()->create();
        $workspaceB = CreateWorkspace::run($ownerB, ['name' => 'B']);

        app(WorkspaceContext::class)->set($workspaceA);

        $visible = Status::count();
        $this->assertSame(4, $visible);

        app(WorkspaceContext::class)->set($workspaceB);
        $this->assertSame(4, Status::count());

        app(WorkspaceContext::class)->set(null);
        $this->assertSame(0, Status::count());
    }

    public function test_switch_workspace_requires_membership(): void
    {
        $owner = User::factory()->create();
        CreateWorkspace::run($owner, ['name' => 'Mine']);

        $intruder = User::factory()->create();
        $other = CreateWorkspace::run($intruder, ['name' => 'Theirs']);

        $this->actingAs($owner)
            ->post("/workspaces/{$other->id}/switch")
            ->assertForbidden();
    }

    public function test_authenticated_user_can_create_a_workspace(): void
    {
        $owner = User::factory()->create();
        CreateWorkspace::run($owner, ['name' => 'Mine']);

        $response = $this->actingAs($owner)->post('/workspaces', ['name' => 'New workspace']);

        $workspace = Workspace::where('name', 'New workspace')->firstOrFail();
        $response->assertRedirect(route('home'));
        $response->assertSessionHas('workspace_id', $workspace->id);

        $this->assertDatabaseHas('memberships', [
            'user_id' => $owner->id,
            'workspace_id' => $workspace->id,
            'role' => 'owner',
        ]);
        $this->assertSame(4, Status::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->count());
    }

    public function test_workspace_creation_requires_a_name(): void
    {
        $owner = User::factory()->create();
        CreateWorkspace::run($owner, ['name' => 'Mine']);

        $this->actingAs($owner)
            ->post('/workspaces', ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Workspace::count());
    }

    public function test_guest_cannot_create_a_workspace(): void
    {
        $this->post('/workspaces', ['name' => 'Nope'])->assertRedirect('/login');
    }
}
