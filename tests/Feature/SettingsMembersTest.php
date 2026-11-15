<?php

namespace Tests\Feature;

use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Actions\Workspaces\InviteMember;
use App\Actions\Workspaces\TransferWorkspaceOwnership;
use App\Actions\Workspaces\UpdateMemberWorkspaceRole;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;
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
        return $this->addUserWithRole($workspace, 'member');
    }

    private function addUserWithRole(Workspace $workspace, string $role): User
    {
        $user = User::factory()->create();
        Membership::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'role' => $role,
        ]);

        return $user;
    }

    /**
     * The workspace keeps exactly one owner row.
     */
    private function assertSingleOwner(Workspace $workspace, User $owner): void
    {
        $this->assertSame(1, Membership::query()
            ->where('workspace_id', $workspace->id)
            ->where('role', 'owner')
            ->count());
        $this->assertDatabaseHas('memberships', [
            'workspace_id' => $workspace->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
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

    // ── Changement de rôle workspace (D8 : owner seul, Member ↔ Guest) ──

    public function test_owner_can_demote_a_member_to_guest(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addMember($workspace);

        $this->actingAs($owner)
            ->patch(route('workspaces.members.update', [$workspace->id, $member->id]), ['role' => 'guest'])
            ->assertRedirect(route('settings.members'));

        $this->assertDatabaseHas('memberships', [
            'workspace_id' => $workspace->id,
            'user_id' => $member->id,
            'role' => 'guest',
        ]);
    }

    public function test_owner_can_promote_a_guest_to_member(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $guest = $this->addUserWithRole($workspace, 'guest');

        $this->actingAs($owner)
            ->patch(route('workspaces.members.update', [$workspace->id, $guest->id]), ['role' => 'member'])
            ->assertRedirect(route('settings.members'));

        $this->assertDatabaseHas('memberships', [
            'workspace_id' => $workspace->id,
            'user_id' => $guest->id,
            'role' => 'member',
        ]);
    }

    public function test_member_cannot_change_a_workspace_role(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addMember($workspace);
        $other = $this->addMember($workspace);

        $this->actingAs($member)
            ->patch(route('workspaces.members.update', [$workspace->id, $other->id]), ['role' => 'guest'])
            ->assertForbidden();

        $this->assertDatabaseHas('memberships', [
            'workspace_id' => $workspace->id,
            'user_id' => $other->id,
            'role' => 'member',
        ]);
    }

    public function test_guest_cannot_change_a_workspace_role(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addMember($workspace);
        $guest = $this->addUserWithRole($workspace, 'guest');

        $this->actingAs($guest)
            ->patch(route('workspaces.members.update', [$workspace->id, $member->id]), ['role' => 'guest'])
            ->assertForbidden();

        $this->assertDatabaseHas('memberships', [
            'workspace_id' => $workspace->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);
    }

    public function test_the_owner_role_cannot_be_changed_through_the_role_update(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        // The owner seat never rides the member/guest toggle: it moves
        // only through the explicit ownership transfer.
        $this->actingAs($owner)
            ->patch(route('workspaces.members.update', [$workspace->id, $owner->id]), ['role' => 'guest'])
            ->assertForbidden();

        $this->assertSingleOwner($workspace, $owner);
    }

    public function test_role_update_rejects_the_owner_role(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addMember($workspace);

        $this->actingAs($owner)
            ->patch(route('workspaces.members.update', [$workspace->id, $member->id]), ['role' => 'owner'])
            ->assertInvalid('role');

        $this->assertDatabaseHas('memberships', [
            'workspace_id' => $workspace->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);
    }

    public function test_role_update_of_a_non_member_is_not_found(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $stranger = User::factory()->create();

        $this->actingAs($owner)
            ->patch(route('workspaces.members.update', [$workspace->id, $stranger->id]), ['role' => 'guest'])
            ->assertNotFound();
    }

    public function test_role_change_leaves_the_project_teams_untouched(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $first = CreateProject::run($workspace, $owner, ['name' => 'Client']);
        $second = CreateProject::run($workspace, $owner, ['name' => 'Internal']);
        $member = $this->addMember($workspace);

        $first->projectMembers()->create(['user_id' => $member->id, 'role' => 'manager']);
        $second->projectMembers()->create(['user_id' => $member->id, 'role' => 'guest']);

        $context = app(WorkspaceContext::class);
        $accessibleBefore = $context->accessibleProjectIds($member);

        // D7: the workspace role and the project rosters are orthogonal —
        // a demotion never rewrites a team line.
        $this->actingAs($owner)
            ->patch(route('workspaces.members.update', [$workspace->id, $member->id]), ['role' => 'guest'])
            ->assertRedirect(route('settings.members'));

        $this->assertDatabaseHas('project_members', [
            'workspace_id' => $workspace->id,
            'project_id' => $first->id,
            'user_id' => $member->id,
            'role' => 'manager',
        ]);
        $this->assertDatabaseHas('project_members', [
            'workspace_id' => $workspace->id,
            'project_id' => $second->id,
            'user_id' => $member->id,
            'role' => 'guest',
        ]);

        // A guest ws Manager of a project keeps exercising the role
        // (D7): the roster-driven scope is unchanged.
        $context->flushAccessibleProjectScope($member);
        $this->assertSame($accessibleBefore, $context->accessibleProjectIds($member));

        // And the round trip back to member neither.
        $this->actingAs($owner)
            ->patch(route('workspaces.members.update', [$workspace->id, $member->id]), ['role' => 'member'])
            ->assertRedirect(route('settings.members'));

        $this->assertDatabaseHas('project_members', [
            'workspace_id' => $workspace->id,
            'project_id' => $first->id,
            'user_id' => $member->id,
            'role' => 'manager',
        ]);
    }

    public function test_demoted_member_loses_the_full_member_surfaces(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addMember($workspace);

        $this->actingAs($member)
            ->get(route('settings.members'))
            ->assertOk();

        $this->actingAs($owner)
            ->patch(route('workspaces.members.update', [$workspace->id, $member->id]), ['role' => 'guest'])
            ->assertRedirect(route('settings.members'));

        $this->actingAs($member)
            ->get(route('settings.members'))
            ->assertForbidden();

        $this->actingAs($member)
            ->get(route('settings.tokens'))
            ->assertForbidden();
    }

    public function test_role_change_serves_the_fresh_role_within_the_same_request(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addMember($workspace);

        // Memoize the pre-change role on the instance, like a request
        // that authorized on it would.
        $this->assertSame('member', $workspace->roleOf($member)->value);

        UpdateMemberWorkspaceRole::run($workspace, $member, ['role' => 'guest'], $owner);

        $this->assertSame('guest', $workspace->roleOf($member)->value);
    }

    public function test_role_change_is_recorded_in_the_activity_journal(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addMember($workspace);

        $this->actingAs($owner)
            ->patch(route('workspaces.members.update', [$workspace->id, $member->id]), ['role' => 'guest'])
            ->assertRedirect(route('settings.members'));

        $event = AuditEvent::query()
            ->where('type', AuditEventType::MembershipRoleChanged)
            ->firstOrFail();

        $this->assertSame($workspace->id, $event->workspace_id);
        $this->assertSame($owner->id, $event->actor_id);
        // A workspace-level event carries no project subject.
        $this->assertNull($event->issue_id);
        $this->assertNull($event->project_id);
        $this->assertNull($event->knowledge_page_id);
        $this->assertNull($event->milestone_id);
        $this->assertSame([
            'member' => ['id' => $member->id, 'name' => $member->name],
            'role' => ['before' => 'member', 'after' => 'guest'],
        ], $event->changes);
    }

    // ── Transfert d'ownership (D8 : action explicite, un seul owner) ────

    public function test_owner_can_transfer_ownership_to_a_member(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addMember($workspace);

        $this->actingAs($owner)
            ->post(route('workspaces.members.transfer-ownership', [$workspace->id, $member->id]))
            ->assertRedirect(route('settings.members'));

        $this->assertDatabaseHas('memberships', [
            'workspace_id' => $workspace->id,
            'user_id' => $owner->id,
            'role' => 'member',
        ]);
        $this->assertSingleOwner($workspace, $member);
    }

    public function test_the_previous_owner_becomes_a_plain_member(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addMember($workspace);
        $other = $this->addMember($workspace);

        $this->actingAs($owner)
            ->post(route('workspaces.members.transfer-ownership', [$workspace->id, $member->id]))
            ->assertRedirect(route('settings.members'));

        // The new owner exercises the owner-only actions; the previous
        // owner, now a plain member, no longer does — but keeps the
        // full-member surfaces.
        $this->actingAs($member)
            ->patch(route('workspaces.members.update', [$workspace->id, $other->id]), ['role' => 'guest'])
            ->assertRedirect(route('settings.members'));

        $this->actingAs($owner)
            ->patch(route('workspaces.members.update', [$workspace->id, $other->id]), ['role' => 'member'])
            ->assertForbidden();

        $this->actingAs($owner)
            ->get(route('settings.members'))
            ->assertOk();
    }

    public function test_owner_cannot_transfer_ownership_to_themselves(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->post(route('workspaces.members.transfer-ownership', [$workspace->id, $owner->id]))
            ->assertForbidden();

        $this->assertSingleOwner($workspace, $owner);
    }

    public function test_member_cannot_transfer_ownership(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addMember($workspace);
        $other = $this->addMember($workspace);

        $this->actingAs($member)
            ->post(route('workspaces.members.transfer-ownership', [$workspace->id, $other->id]))
            ->assertForbidden();

        $this->assertSingleOwner($workspace, $owner);
    }

    public function test_ownership_transfer_to_a_non_member_is_not_found(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $stranger = User::factory()->create();

        $this->actingAs($owner)
            ->post(route('workspaces.members.transfer-ownership', [$workspace->id, $stranger->id]))
            ->assertNotFound();

        $this->assertSingleOwner($workspace, $owner);
    }

    public function test_a_previous_owner_cannot_transfer_again(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addMember($workspace);
        $other = $this->addMember($workspace);

        TransferWorkspaceOwnership::run($workspace, $member, $owner);

        // The handle re-checks the acting row under the lock: an owner
        // dethroned by a completed transfer must not dethrone the
        // fresh one — never two owners, never zero.
        $this->expectException(HttpException::class);
        TransferWorkspaceOwnership::run($workspace, $other, $owner);

        $this->assertSingleOwner($workspace, $member);
    }

    public function test_ownership_transfer_is_recorded_in_the_activity_journal(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addMember($workspace);

        $this->actingAs($owner)
            ->post(route('workspaces.members.transfer-ownership', [$workspace->id, $member->id]))
            ->assertRedirect(route('settings.members'));

        $event = AuditEvent::query()
            ->where('type', AuditEventType::WorkspaceOwnershipTransferred)
            ->firstOrFail();

        $this->assertSame($workspace->id, $event->workspace_id);
        $this->assertSame($owner->id, $event->actor_id);
        $this->assertNull($event->issue_id);
        $this->assertNull($event->project_id);
        $this->assertNull($event->knowledge_page_id);
        $this->assertNull($event->milestone_id);
        $this->assertSame([
            'owner' => [
                'before' => ['id' => $owner->id, 'name' => $owner->name],
                'after' => ['id' => $member->id, 'name' => $member->name],
            ],
        ], $event->changes);
    }

    public function test_ownership_transfer_serves_fresh_roles_and_scopes_within_the_same_request(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addMember($workspace);
        $context = app(WorkspaceContext::class);

        // Memoize both sides like a request would: the owner resolves
        // unrestricted, the member resolves its roster (empty here).
        $this->assertTrue($workspace->hasOwner($owner));
        $this->assertNull($context->accessibleProjectIds($owner));
        $this->assertSame([], $context->accessibleProjectIds($member));

        TransferWorkspaceOwnership::run($workspace, $member, $owner);

        $this->assertFalse($workspace->hasOwner($owner));
        $this->assertTrue($workspace->hasOwner($member));
        // The previous owner is roster-driven now; the new one is
        // unrestricted — no stale memo survives the swap.
        $this->assertSame([], $context->accessibleProjectIds($owner));
        $this->assertNull($context->accessibleProjectIds($member));
    }
}
