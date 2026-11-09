<?php

namespace Tests\Feature;

use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class GuestInvitationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace, 2: Project, 3: Project}
     */
    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $beta = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        return [$owner, $workspace, $alpha, $beta];
    }

    // ── Création de l'invitation guest ────────────────────────────────

    public function test_owner_can_invite_a_guest_with_a_project_scope(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();

        $response = $this->actingAs($owner)
            ->post(route('workspaces.invite', $workspace), [
                'email' => 'client@example.com',
                'role' => 'guest',
                'project_ids' => [$alpha->id, $beta->id],
            ]);

        $response->assertRedirect();
        $invitation = Invitation::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('email', 'client@example.com')
            ->firstOrFail();

        $this->assertSame(WorkspaceRole::Guest, $invitation->role);
        $this->assertSame(
            [$alpha->id, $beta->id],
            $invitation->projects()->orderBy('name')->pluck('projects.id')->all(),
        );
    }

    public function test_inviting_a_guest_without_projects_is_refused(): void
    {
        [$owner, $workspace, $alpha] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->post(route('workspaces.invite', $workspace), [
                'email' => 'client@example.com',
                'role' => 'guest',
                'project_ids' => [],
            ])
            ->assertInvalid('project_ids');

        $this->assertDatabaseMissing('invitations', [
            'workspace_id' => $workspace->id,
            'email' => 'client@example.com',
        ]);
    }

    public function test_inviting_a_guest_with_a_foreign_project_is_refused(): void
    {
        [$owner, $workspace, $alpha] = $this->setupWorkspace();

        // CreateProject resolves the workspace from WorkspaceContext, so
        // switch it while seeding the other workspace's project.
        [, $otherWorkspace] = $this->setupWorkspaceOwnerOnly();
        app(WorkspaceContext::class)->set($otherWorkspace);
        $foreign = CreateProject::run($otherWorkspace, $owner, ['name' => 'Foreign']);
        app(WorkspaceContext::class)->set($workspace);

        $this->actingAs($owner)
            ->post(route('workspaces.invite', $workspace), [
                'email' => 'client@example.com',
                'role' => 'guest',
                'project_ids' => [$foreign->id],
            ])
            ->assertInvalid('project_ids.0');

        $this->assertDatabaseMissing('invitations', [
            'workspace_id' => $workspace->id,
            'email' => 'client@example.com',
        ]);
    }

    public function test_member_invitation_can_carry_projects(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();

        // Since members can be scoped to a project perimeter, a member
        // invitation carries its announced scope like a guest one.
        $this->actingAs($owner)
            ->post(route('workspaces.invite', $workspace), [
                'email' => 'peer@example.com',
                'role' => 'member',
                'project_ids' => [$alpha->id, $beta->id],
            ])
            ->assertRedirect();

        $invitation = Invitation::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('email', 'peer@example.com')
            ->firstOrFail();

        $this->assertSame(WorkspaceRole::Member, $invitation->role);
        $this->assertSame(
            [$alpha->id, $beta->id],
            $invitation->projects()->orderBy('name')->pluck('projects.id')->all(),
        );
    }

    public function test_member_cannot_invite_a_guest(): void
    {
        [$owner, $workspace, $alpha] = $this->setupWorkspace();

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $this->actingAs($member)
            ->post(route('workspaces.invite', $workspace), [
                'email' => 'client@example.com',
                'role' => 'guest',
                'project_ids' => [$alpha->id],
            ])
            ->assertForbidden();
    }

    // ── Acceptation ───────────────────────────────────────────────────

    public function test_connected_user_accepting_a_guest_invitation_joins_with_scope(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();
        $invitation = $this->guestInvitation($workspace, $alpha, $beta);

        $invitee = User::factory()->create(['email' => 'client@example.com']);

        $response = $this->actingAs($invitee)
            ->get(route('invitations.accept', $invitation->token));

        $response->assertRedirect(route('home'));

        // The request resets the request-scoped WorkspaceContext: re-bind it
        // before reading scoped models.
        app(WorkspaceContext::class)->set($workspace);

        $membership = Membership::where('user_id', $invitee->id)
            ->where('workspace_id', $workspace->id)
            ->firstOrFail();
        $this->assertSame(WorkspaceRole::Guest, $membership->role);

        $roster = ProjectMember::where('user_id', $invitee->id)->get();
        $this->assertSame(
            [$alpha->id, $beta->id],
            $roster->pluck('project_id')->sort()->values()->all(),
        );
        $this->assertTrue($roster->every(fn ($row) => $row->role === ProjectRole::Member));

        $this->assertSame($workspace->id, session('workspace_id'));
    }

    public function test_login_resumed_acceptance_creates_the_guest_scope(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();
        $invitation = $this->guestInvitation($workspace, $alpha);

        $invitee = User::factory()->create([
            'email' => 'client@example.com',
            'password' => Hash::make('secret123'),
        ]);

        // Landing: not logged in yet.
        $this->get(route('invitations.accept', $invitation->token))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/AcceptInvitation')
                ->where('invitation.role', 'guest'));

        // Login with the acceptance URL as return target.
        $this->post(route('login.attempt'), [
            'email' => 'client@example.com',
            'password' => 'secret123',
            'redirect' => route('invitations.accept', $invitation->token),
        ]);

        // The acceptance is replayed after login.
        $this->assertAuthenticatedAs($invitee);
        $this->get(route('invitations.accept', $invitation->token))->assertRedirect(route('home'));

        // The request resets the request-scoped WorkspaceContext: re-bind it
        // before reading scoped models.
        app(WorkspaceContext::class)->set($workspace);

        $membership = Membership::where('user_id', $invitee->id)
            ->where('workspace_id', $workspace->id)
            ->firstOrFail();
        $this->assertSame(WorkspaceRole::Guest, $membership->role);
        $this->assertSame(
            [$alpha->id],
            ProjectMember::where('user_id', $invitee->id)->pluck('project_id')->all(),
        );
    }

    public function test_invitation_landing_shows_role_and_scope_projects(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();
        $invitation = $this->guestInvitation($workspace, $alpha, $beta);

        $this->get(route('invitations.accept', $invitation->token))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/AcceptInvitation')
                ->where('invitation.role', 'guest')
                ->where('invitation.projects.0', 'Alpha')
                ->where('invitation.projects.1', 'Beta'));
    }

    public function test_resending_a_guest_invitation_keeps_role_and_scope(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();
        $invitation = $this->guestInvitation($workspace, $alpha, $beta);
        $oldToken = $invitation->token;

        $this->actingAs($owner)
            ->post(route('workspaces.invitations.resend', [$workspace->id, $invitation->id]));

        $invitation->refresh();
        $this->assertNotSame($oldToken, $invitation->token);
        $this->assertSame(WorkspaceRole::Guest, $invitation->role);
        $this->assertSame(
            [$alpha->id, $beta->id],
            $invitation->projects()->orderBy('name')->pluck('projects.id')->all(),
        );
    }

    // ── Page Members ─────────────────────────────────────────────────

    public function test_members_page_lists_guest_invitations_with_role_and_projects(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();
        $invitation = $this->guestInvitation($workspace, $alpha, $beta);

        $this->actingAs($owner)
            ->get(route('settings.members', $workspace))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('invitations', 1)
                ->where('invitations.0.id', $invitation->id)
                ->where('invitations.0.role', 'guest')
                ->where('invitations.0.projects.0', 'Alpha')
                ->where('invitations.0.projects.1', 'Beta')
                ->where('projects.0.name', 'Alpha')
                ->where('projects.1.name', 'Beta'));
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function guestInvitation(Workspace $workspace, Project ...$projects): Invitation
    {
        $invitation = Invitation::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'email' => 'client@example.com',
            'role' => WorkspaceRole::Guest->value,
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);

        $invitation->attachScope(array_map(fn ($p) => $p->id, $projects));

        return $invitation;
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function setupWorkspaceOwnerOnly(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'Other']);

        return [$owner, $workspace];
    }
}
