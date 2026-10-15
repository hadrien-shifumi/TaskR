<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspace;
use App\Actions\Workspaces\InviteMember;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class InvitationActionsTest extends TestCase
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

    public function test_owner_can_resend_a_pending_invitation(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $invitation = InviteMember::run($workspace, ['email' => 'bob@example.com']);

        $response = $this->actingAs($owner)
            ->post(route('workspaces.invitations.resend', [$workspace->id, $invitation->id]))
            ->assertRedirect(route('settings.members'));

        // Same dogfood contract as the invite flow: the fresh acceptance
        // link is exposed through the flash, there is no emailing yet.
        $status = $response->getSession()->get('status');
        $this->assertIsString($status);
        $this->assertStringContainsString('bob@example.com', $status);
        $this->assertStringContainsString("/invitations/{$invitation->fresh()->token}/accept", $status);
    }

    public function test_resend_regenerates_the_token(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $invitation = InviteMember::run($workspace, ['email' => 'bob@example.com']);
        $oldToken = $invitation->token;

        $this->actingAs($owner)
            ->post(route('workspaces.invitations.resend', [$workspace->id, $invitation->id]))
            ->assertRedirect(route('settings.members'));

        $fresh = $invitation->fresh();
        $this->assertNotSame($oldToken, $fresh->token);
        $this->assertDatabaseHas('invitations', [
            'id' => $invitation->id,
            'token' => $fresh->token,
        ]);
        $this->assertDatabaseMissing('invitations', ['token' => $oldToken]);
    }

    public function test_resend_invalidates_the_previous_acceptance_link(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $invitation = InviteMember::run($workspace, ['email' => 'bob@example.com']);
        $oldToken = $invitation->token;

        $this->actingAs($owner)
            ->post(route('workspaces.invitations.resend', [$workspace->id, $invitation->id]));

        // The public acceptance route addresses invitations by token: the
        // old link no longer resolves.
        $this->get("/invitations/{$oldToken}/accept")->assertNotFound();
    }

    public function test_resend_extends_an_expired_invitation(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $invitation = InviteMember::run($workspace, ['email' => 'bob@example.com']);
        $invitation->update(['expires_at' => now()->subDay()]);

        $this->actingAs($owner)
            ->post(route('workspaces.invitations.resend', [$workspace->id, $invitation->id]))
            ->assertRedirect(route('settings.members'));

        $this->assertTrue($invitation->fresh()->expires_at->isFuture());

        // The members page lists it as sent again, not expired.
        $this->actingAs($owner)
            ->get(route('settings.members'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('invitations.0.status', 'sent'));
    }

    public function test_member_cannot_resend_an_invitation(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addMember($workspace);
        $invitation = InviteMember::run($workspace, ['email' => 'bob@example.com']);
        $oldToken = $invitation->token;

        $this->actingAs($member)
            ->post(route('workspaces.invitations.resend', [$workspace->id, $invitation->id]))
            ->assertForbidden();

        $this->assertSame($oldToken, $invitation->fresh()->token);
    }

    public function test_resending_an_unknown_invitation_is_not_found(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->post(route('workspaces.invitations.resend', [$workspace->id, (string) Str::ulid()]))
            ->assertNotFound();
    }

    public function test_resending_an_invitation_from_another_workspace_is_not_found(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Other']);
        $foreign = InviteMember::run($otherWorkspace, ['email' => 'eve@example.com']);

        $this->actingAs($owner)
            ->post(route('workspaces.invitations.resend', [$workspace->id, $foreign->id]))
            ->assertNotFound();

        $this->assertDatabaseHas('invitations', [
            'id' => $foreign->id,
            'token' => $foreign->token,
        ]);
    }

    public function test_resending_an_accepted_invitation_is_refused(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $invitation = InviteMember::run($workspace, ['email' => 'bob@example.com']);
        $invitation->update(['accepted_at' => now()]);
        $oldToken = $invitation->token;

        $this->actingAs($owner)
            ->post(route('workspaces.invitations.resend', [$workspace->id, $invitation->id]))
            ->assertConflict();

        $this->assertSame($oldToken, $invitation->fresh()->token);
    }

    public function test_guest_cannot_resend_an_invitation(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $invitation = InviteMember::run($workspace, ['email' => 'bob@example.com']);

        $this->post(route('workspaces.invitations.resend', [$workspace->id, $invitation->id]))
            ->assertRedirect('/login');
    }

    public function test_owner_can_cancel_a_pending_invitation(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $invitation = InviteMember::run($workspace, ['email' => 'bob@example.com']);

        $response = $this->actingAs($owner)
            ->delete(route('workspaces.invitations.destroy', [$workspace->id, $invitation->id]))
            ->assertRedirect(route('settings.members'));

        $this->assertStringContainsString(
            'bob@example.com',
            (string) $response->getSession()->get('status'),
        );
        $this->assertDatabaseMissing('invitations', ['id' => $invitation->id]);
    }

    public function test_owner_can_cancel_an_expired_invitation(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $invitation = InviteMember::run($workspace, ['email' => 'bob@example.com']);
        $invitation->update(['expires_at' => now()->subDay()]);

        $this->actingAs($owner)
            ->delete(route('workspaces.invitations.destroy', [$workspace->id, $invitation->id]))
            ->assertRedirect(route('settings.members'));

        $this->assertDatabaseMissing('invitations', ['id' => $invitation->id]);
    }

    public function test_member_cannot_cancel_an_invitation(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addMember($workspace);
        $invitation = InviteMember::run($workspace, ['email' => 'bob@example.com']);

        $this->actingAs($member)
            ->delete(route('workspaces.invitations.destroy', [$workspace->id, $invitation->id]))
            ->assertForbidden();

        $this->assertDatabaseHas('invitations', ['id' => $invitation->id]);
    }

    public function test_cancelling_an_unknown_invitation_is_not_found(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->delete(route('workspaces.invitations.destroy', [$workspace->id, (string) Str::ulid()]))
            ->assertNotFound();
    }

    public function test_cancelling_an_invitation_from_another_workspace_is_not_found(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Other']);
        $foreign = InviteMember::run($otherWorkspace, ['email' => 'eve@example.com']);

        $this->actingAs($owner)
            ->delete(route('workspaces.invitations.destroy', [$workspace->id, $foreign->id]))
            ->assertNotFound();

        $this->assertDatabaseHas('invitations', ['id' => $foreign->id]);
    }

    public function test_cancelling_an_accepted_invitation_is_refused(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $invitation = InviteMember::run($workspace, ['email' => 'bob@example.com']);
        $invitation->update(['accepted_at' => now()]);

        $this->actingAs($owner)
            ->delete(route('workspaces.invitations.destroy', [$workspace->id, $invitation->id]))
            ->assertConflict();

        $this->assertDatabaseHas('invitations', ['id' => $invitation->id]);
    }
}
