<?php

namespace Tests\Feature;

use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\AcceptInvitation;
use App\Actions\Workspaces\CreateWorkspace;
use App\Actions\Workspaces\InviteMember;
use App\Actions\Workspaces\RemoveMember;
use App\Enums\WorkspaceRole;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\InvitationProject;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    // ── Réinvitation (invitation déjà existante pour cet email) ────────

    public function test_reinviting_a_removed_member_reactivates_their_accepted_invitation(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $bob = User::factory()->create(['email' => 'bob@example.com']);
        $invitation = InviteMember::run($workspace, ['email' => 'bob@example.com']);
        AcceptInvitation::run($invitation, $bob);
        RemoveMember::run($workspace, $bob, $owner);
        $oldToken = $invitation->fresh()->token;

        // The prod bug: this used to 500 on the (workspace_id, email)
        // unique constraint because the accepted invitation row survives
        // the member removal.
        $response = $this->actingAs($owner)
            ->post(route('workspaces.invite', $workspace), ['email' => 'bob@example.com'])
            ->assertRedirect();

        // Same row reactivated, not a duplicate.
        $this->assertSame(1, Invitation::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('email', 'bob@example.com')
            ->count());

        $fresh = $invitation->fresh();
        $this->assertNull($fresh->accepted_at);
        $this->assertNotSame($oldToken, $fresh->token);
        $this->assertTrue($fresh->expires_at->isFuture());
        $this->assertStringContainsString(
            "/invitations/{$fresh->token}/accept",
            (string) $response->getSession()->get('status'),
        );

        // The previous acceptance link no longer resolves.
        $this->get("/invitations/{$oldToken}/accept")->assertNotFound();
    }

    public function test_reinviting_a_pending_invitation_reactivates_it_instead_of_duplicating(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $invitation = InviteMember::run($workspace, ['email' => 'bob@example.com']);
        $oldToken = $invitation->token;

        $this->actingAs($owner)
            ->post(route('workspaces.invite', $workspace), ['email' => 'bob@example.com'])
            ->assertRedirect();

        $this->assertSame(1, Invitation::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('email', 'bob@example.com')
            ->count());
        $this->assertNotSame($oldToken, $invitation->fresh()->token);
    }

    public function test_reinviting_updates_role_and_project_scope(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $beta = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $this->actingAs($owner)
            ->post(route('workspaces.invite', $workspace), [
                'email' => 'bob@example.com',
                'role' => 'guest',
                'project_ids' => [$alpha->id],
            ])
            ->assertRedirect();

        $this->actingAs($owner)
            ->post(route('workspaces.invite', $workspace), [
                'email' => 'bob@example.com',
                'role' => 'member',
                'project_ids' => [$beta->id],
            ])
            ->assertRedirect();

        app(WorkspaceContext::class)->set($workspace);
        $invitation = Invitation::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('email', 'bob@example.com')
            ->firstOrFail();

        $this->assertSame(WorkspaceRole::Member, $invitation->role);
        $this->assertSame([$beta->id], $invitation->projects()->pluck('projects.id')->all());
        $this->assertSame(1, InvitationProject::query()->where('invitation_id', $invitation->id)->count());
    }

    public function test_reinviting_is_scoped_to_the_current_workspace(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Other']);
        $foreign = InviteMember::run($otherWorkspace, ['email' => 'bob@example.com']);
        $foreign->update(['accepted_at' => now()]);
        $foreignToken = $foreign->token;

        $this->actingAs($owner)
            ->post(route('workspaces.invite', $workspace), ['email' => 'bob@example.com'])
            ->assertRedirect();

        // A fresh invitation here; the foreign one is untouched.
        $local = Invitation::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('email', 'bob@example.com')
            ->firstOrFail();
        $this->assertNotSame($foreign->id, $local->id);
        $this->assertNull($local->accepted_at);

        $this->assertSame($foreignToken, $foreign->fresh()->token);
        $this->assertNotNull($foreign->fresh()->accepted_at);
    }

    public function test_inviting_a_current_member_is_refused(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addMember($workspace);

        $this->actingAs($owner)
            ->post(route('workspaces.invite', $workspace), ['email' => $member->email])
            ->assertInvalid('email');

        $this->assertDatabaseMissing('invitations', [
            'workspace_id' => $workspace->id,
            'email' => $member->email,
        ]);
    }

    public function test_inviting_a_current_member_keeps_their_accepted_invitation_untouched(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $invitation = InviteMember::run($workspace, ['email' => 'bob@example.com']);
        $invitation->update(['accepted_at' => now()]);
        $bob = User::factory()->create(['email' => 'bob@example.com']);
        Membership::create([
            'user_id' => $bob->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);
        $oldToken = $invitation->token;

        $this->actingAs($owner)
            ->post(route('workspaces.invite', $workspace), ['email' => 'bob@example.com'])
            ->assertInvalid('email');

        $fresh = $invitation->fresh();
        $this->assertSame($oldToken, $fresh->token);
        $this->assertNotNull($fresh->accepted_at);
    }

    public function test_run_reactivates_an_existing_invitation_instead_of_throwing(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $first = InviteMember::run($workspace, ['email' => 'bob@example.com']);
        $first->update(['accepted_at' => now()]);

        // Object call site (MCP tools): same row back, no unique-constraint crash.
        $second = InviteMember::run($workspace, ['email' => 'bob@example.com']);

        $this->assertSame($first->id, $second->id);
        $this->assertNull($second->accepted_at);
        $this->assertSame(1, Invitation::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('email', 'bob@example.com')
            ->count());
    }

    public function test_run_refuses_an_email_that_is_already_a_member(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addMember($workspace);

        $this->expectException(ValidationException::class);

        InviteMember::run($workspace, ['email' => $member->email]);
    }

    // ── E-mail d'invitation ──────────────────────────────────────────────

    public function test_inviting_sends_an_invitation_email_with_the_acceptance_link(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);

        Mail::fake();
        $this->actingAs($owner)
            ->post(route('workspaces.invite', $workspace), [
                'email' => 'bob@example.com',
                'role' => 'guest',
                'project_ids' => [$alpha->id],
            ])
            ->assertRedirect();

        $invitation = Invitation::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('email', 'bob@example.com')
            ->firstOrFail();

        Mail::assertSent(InvitationMail::class, fn (InvitationMail $mail) => $mail->hasTo('bob@example.com')
            && str_contains($mail->acceptUrl, $invitation->token)
            && $mail->workspaceName === 'WS'
            && $mail->role === 'guest'
            && $mail->projectNames === ['Alpha']);
    }

    public function test_reinviting_sends_the_email_again_with_the_fresh_link(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $bob = User::factory()->create(['email' => 'bob@example.com']);
        $invitation = InviteMember::run($workspace, ['email' => 'bob@example.com']);
        AcceptInvitation::run($invitation, $bob);
        RemoveMember::run($workspace, $bob, $owner);

        Mail::fake();
        $this->actingAs($owner)
            ->post(route('workspaces.invite', $workspace), ['email' => 'bob@example.com'])
            ->assertRedirect();

        $freshToken = $invitation->fresh()->token;
        Mail::assertSent(InvitationMail::class, fn (InvitationMail $mail) => $mail->hasTo('bob@example.com')
            && str_contains($mail->acceptUrl, $freshToken));
    }

    public function test_resending_a_pending_invitation_sends_the_email_again(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $invitation = InviteMember::run($workspace, ['email' => 'bob@example.com']);

        Mail::fake();
        $this->actingAs($owner)
            ->post(route('workspaces.invitations.resend', [$workspace->id, $invitation->id]))
            ->assertRedirect();

        Mail::assertSent(InvitationMail::class, fn (InvitationMail $mail) => $mail->hasTo('bob@example.com')
            && str_contains($mail->acceptUrl, $invitation->fresh()->token));
    }

    public function test_inviting_an_already_member_sends_no_email(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addMember($workspace);

        Mail::fake();
        $this->actingAs($owner)
            ->post(route('workspaces.invite', $workspace), ['email' => $member->email])
            ->assertInvalid('email');

        Mail::assertNotSent(InvitationMail::class);
    }
}
