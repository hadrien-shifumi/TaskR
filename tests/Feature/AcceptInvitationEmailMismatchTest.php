<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\WorkspaceRole;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AcceptInvitationEmailMismatchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function workspaceWithOwner(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'Studio Nova']);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace];
    }

    private function invitation(Workspace $workspace, string $email = 'client@example.com'): Invitation
    {
        return Invitation::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'email' => $email,
            'role' => 'guest',
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);
    }

    #[Test]
    public function test_acceptance_with_the_matching_email_still_joins_the_workspace(): void
    {
        [$owner, $workspace] = $this->workspaceWithOwner();
        $invitation = Invitation::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'email' => 'peer@example.com',
            'role' => 'member',
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);
        $invitee = User::factory()->create(['email' => 'peer@example.com']);

        $this->actingAs($invitee)
            ->get(route('invitations.accept', $invitation->token))
            ->assertRedirect(route('home'));

        app(WorkspaceContext::class)->set($workspace);

        $membership = Membership::where('user_id', $invitee->id)
            ->where('workspace_id', $workspace->id)
            ->firstOrFail();
        $this->assertSame(WorkspaceRole::Member, $membership->role);
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertSame($workspace->id, session('workspace_id'));
    }

    #[Test]
    public function test_acceptance_with_a_different_email_shows_the_error_page_and_creates_no_access(): void
    {
        [$owner, $workspace] = $this->workspaceWithOwner();
        $invitation = $this->invitation($workspace);
        $outsider = User::factory()->create(['email' => 'someone.else@example.com']);

        $this->actingAs($outsider)
            ->get(route('invitations.accept', $invitation->token))
            ->assertStatus(403)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/InvitationEmailMismatch')
                ->where('invitation.email', 'c***@example.com'));

        app(WorkspaceContext::class)->set($workspace);

        $this->assertDatabaseMissing('memberships', [
            'user_id' => $outsider->id,
            'workspace_id' => $workspace->id,
        ]);
        $this->assertNull($invitation->fresh()->accepted_at);
        $this->assertNull(session('workspace_id'));
    }

    #[Test]
    public function test_email_comparison_ignores_case(): void
    {
        [$owner, $workspace] = $this->workspaceWithOwner();
        $invitation = $this->invitation($workspace, 'Client@Example.com');
        $invitee = User::factory()->create(['email' => 'client@example.com']);

        $this->actingAs($invitee)
            ->get(route('invitations.accept', $invitation->token))
            ->assertRedirect(route('home'));

        app(WorkspaceContext::class)->set($workspace);

        $this->assertDatabaseHas('memberships', [
            'user_id' => $invitee->id,
            'workspace_id' => $workspace->id,
        ]);
    }

    #[Test]
    public function test_an_existing_member_with_a_different_email_is_blocked(): void
    {
        [$owner, $workspace] = $this->workspaceWithOwner();

        $member = User::factory()->create(['email' => 'member@example.com']);
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $invitation = $this->invitation($workspace, 'client@example.com');

        $this->actingAs($member)
            ->get(route('invitations.accept', $invitation->token))
            ->assertStatus(403)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/InvitationEmailMismatch')
                ->where('invitation.email', 'c***@example.com'));

        app(WorkspaceContext::class)->set($workspace);

        // The existing membership is untouched, no guest scope rows were
        // added and the invitation stays pending.
        $this->assertSame(1, Membership::query()
            ->where('user_id', $member->id)
            ->where('workspace_id', $workspace->id)
            ->count());
        $this->assertSame(0, ProjectMember::query()->where('user_id', $member->id)->count());
        $this->assertNull($invitation->fresh()->accepted_at);
    }

    #[Test]
    public function test_login_resumed_acceptance_with_a_different_account_is_blocked(): void
    {
        [$owner, $workspace] = $this->workspaceWithOwner();
        $invitation = $this->invitation($workspace, 'client@example.com');

        $other = User::factory()->create([
            'email' => 'other@example.com',
            'password' => Hash::make('secret123'),
        ]);

        // Login with the acceptance URL as return target, like the landing
        // page does.
        $this->post(route('login.attempt'), [
            'email' => 'other@example.com',
            'password' => 'secret123',
            'redirect' => route('invitations.accept', $invitation->token),
        ]);
        $this->assertAuthenticatedAs($other);

        // The replayed acceptance must be refused: wrong account.
        $this->get(route('invitations.accept', $invitation->token))
            ->assertStatus(403)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/InvitationEmailMismatch')
                ->where('invitation.email', 'c***@example.com'));

        app(WorkspaceContext::class)->set($workspace);

        $this->assertDatabaseMissing('memberships', [
            'user_id' => $other->id,
            'workspace_id' => $workspace->id,
        ]);
        $this->assertNull(session('workspace_id'));
    }

    #[Test]
    public function test_an_expired_invitation_still_returns_gone_regardless_of_the_email(): void
    {
        [$owner, $workspace] = $this->workspaceWithOwner();
        $invitation = $this->invitation($workspace);
        $invitation->forceFill(['expires_at' => now()->subDay()])->save();
        $outsider = User::factory()->create(['email' => 'someone.else@example.com']);

        // The expiry check wins over the email mismatch: 410, not the
        // mismatch page.
        $this->actingAs($outsider)
            ->get(route('invitations.accept', $invitation->token))
            ->assertStatus(410);
    }
}
