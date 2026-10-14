<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspace;
use App\Actions\Workspaces\InviteMember;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SettingsMembersTest extends TestCase
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

    private function addMember(Workspace $workspace): User
    {
        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        return $member;
    }

    public function test_members_page_lists_members_with_role_and_joined_date(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addMember($workspace);

        $joinedAt = Membership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $member->id)
            ->firstOrFail()
            ->created_at
            ->toISOString();

        $this->actingAs($owner)
            ->get(route('settings.members'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/Members')
                ->has('members', 2)
                ->where('members.0.id', $owner->id)
                ->where('members.0.name', $owner->name)
                ->where('members.0.email', $owner->email)
                ->where('members.0.role', 'owner')
                ->where('members.1.id', $member->id)
                ->where('members.1.role', 'member')
                ->where('members.1.joinedAt', $joinedAt));
    }

    public function test_members_page_lists_pending_invitations(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        InviteMember::run($workspace, ['email' => 'bob@example.com']);

        $this->actingAs($owner)
            ->get(route('settings.members'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('invitations', 1)
                ->where('invitations.0.email', 'bob@example.com')
                ->where('invitations.0.status', 'sent'));
    }

    public function test_accepted_invitations_are_not_listed_as_pending(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $invitation = InviteMember::run($workspace, ['email' => 'bob@example.com']);
        $invitation->update(['accepted_at' => now()]);

        $this->actingAs($owner)
            ->get(route('settings.members'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('invitations', 0));
    }

    public function test_member_can_view_the_members_page(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addMember($workspace);

        $this->actingAs($member)
            ->get(route('settings.members'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/Members')
                ->has('members', 2));
    }

    public function test_owner_can_remove_a_member(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addMember($workspace);

        $this->actingAs($owner)
            ->delete(route('workspaces.members.destroy', [$workspace->id, $member->id]))
            ->assertRedirect(route('settings.members'));

        $this->assertDatabaseMissing('memberships', [
            'workspace_id' => $workspace->id,
            'user_id' => $member->id,
        ]);
    }

    public function test_member_cannot_remove_a_member(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addMember($workspace);
        $other = $this->addMember($workspace);

        $this->actingAs($member)
            ->delete(route('workspaces.members.destroy', [$workspace->id, $other->id]))
            ->assertForbidden();
    }

    public function test_owner_cannot_remove_themselves(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->delete(route('workspaces.members.destroy', [$workspace->id, $owner->id]))
            ->assertForbidden();

        $this->assertDatabaseHas('memberships', [
            'workspace_id' => $workspace->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
    }

    public function test_removing_a_non_member_is_not_found(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $stranger = User::factory()->create();

        $this->actingAs($owner)
            ->delete(route('workspaces.members.destroy', [$workspace->id, $stranger->id]))
            ->assertNotFound();
    }
}
