<?php

namespace Tests\Feature;

use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Actions\Workspaces\InviteMember;
use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\InvitationProject;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * D16: an invitation announces a workspace role AND one project role per
 * chosen project — applied at acceptance, never implicitly widened. The
 * inviter is always the workspace owner (statu quo), so no N+1 rank
 * constraint applies at invitation time: any non-owner project role may
 * be announced.
 */
class InvitationProjectRolesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace, 2: Project, 3: Project, 4: Project}
     */
    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $beta = CreateProject::run($workspace, $owner, ['name' => 'Beta']);
        $gamma = CreateProject::run($workspace, $owner, ['name' => 'Gamma']);

        return [$owner, $workspace, $alpha, $beta, $gamma];
    }

    // ── Émission : un rôle par projet ────────────────────────────────

    public function test_owner_can_invite_with_a_role_per_project(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->post(route('workspaces.invite', $workspace), [
                'email' => 'peer@example.com',
                'role' => 'member',
                'projects' => [
                    ['project_id' => $alpha->id, 'role' => 'manager'],
                    ['project_id' => $beta->id, 'role' => 'guest'],
                ],
            ])
            ->assertRedirect();

        $invitation = Invitation::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('email', 'peer@example.com')
            ->firstOrFail();

        $this->assertSame(ProjectRole::Manager, InvitationProject::query()
            ->where('invitation_id', $invitation->id)
            ->where('project_id', $alpha->id)
            ->value('role'));
        $this->assertSame(ProjectRole::Guest, InvitationProject::query()
            ->where('invitation_id', $invitation->id)
            ->where('project_id', $beta->id)
            ->value('role'));
    }

    public function test_projects_without_roles_default_to_member(): void
    {
        [$owner, $workspace, $alpha] = $this->setupWorkspace();

        // The legacy shape (project_ids, MCP callers alike) stays valid:
        // every announced project lands as a plain member.
        $this->actingAs($owner)
            ->post(route('workspaces.invite', $workspace), [
                'email' => 'peer@example.com',
                'role' => 'member',
                'projects' => [['project_id' => $alpha->id]],
            ])
            ->assertRedirect();

        $invitation = Invitation::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('email', 'peer@example.com')
            ->firstOrFail();

        $this->assertSame(ProjectRole::Member, InvitationProject::query()
            ->where('invitation_id', $invitation->id)
            ->where('project_id', $alpha->id)
            ->value('role'));
    }

    public function test_the_owner_project_role_cannot_be_granted_through_an_invitation(): void
    {
        [$owner, $workspace, $alpha] = $this->setupWorkspace();

        // Project ownership never rides an invitation: it transfers
        // through the explicit action, after the invitee arrived.
        $this->actingAs($owner)
            ->post(route('workspaces.invite', $workspace), [
                'email' => 'peer@example.com',
                'role' => 'member',
                'projects' => [['project_id' => $alpha->id, 'role' => 'owner']],
            ])
            ->assertInvalid('projects.0.role');

        $this->assertDatabaseMissing('invitations', [
            'workspace_id' => $workspace->id,
            'email' => 'peer@example.com',
        ]);
    }

    public function test_an_unknown_project_role_is_refused(): void
    {
        [$owner, $workspace, $alpha] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->post(route('workspaces.invite', $workspace), [
                'email' => 'peer@example.com',
                'role' => 'member',
                'projects' => [['project_id' => $alpha->id, 'role' => 'bogus']],
            ])
            ->assertInvalid('projects.0.role');
    }

    public function test_projects_must_belong_to_the_inviting_workspace(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Other']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $foreign = CreateProject::run($otherWorkspace, $otherOwner, ['name' => 'Foreign']);
        app(WorkspaceContext::class)->set($workspace);

        $this->actingAs($owner)
            ->post(route('workspaces.invite', $workspace), [
                'email' => 'peer@example.com',
                'role' => 'member',
                'projects' => [['project_id' => $foreign->id, 'role' => 'manager']],
            ])
            ->assertInvalid('projects.0.project_id');

        $this->assertDatabaseMissing('invitations', [
            'workspace_id' => $workspace->id,
            'email' => 'peer@example.com',
        ]);
    }

    public function test_a_guest_invitation_still_requires_at_least_one_project(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->post(route('workspaces.invite', $workspace), [
                'email' => 'client@example.com',
                'role' => 'guest',
                'projects' => [],
            ])
            ->assertInvalid('projects');

        $this->assertDatabaseMissing('invitations', [
            'workspace_id' => $workspace->id,
            'email' => 'client@example.com',
        ]);
    }

    // ── Acceptation : les rôles annoncés deviennent les lignes d'équipe ──

    public function test_acceptance_joins_the_teams_with_the_announced_roles(): void
    {
        [$owner, $workspace, $alpha, $beta, $gamma] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->post(route('workspaces.invite', $workspace), [
                'email' => 'peer@example.com',
                'role' => 'member',
                'projects' => [
                    ['project_id' => $alpha->id, 'role' => 'manager'],
                    ['project_id' => $beta->id, 'role' => 'guest'],
                ],
            ])
            ->assertRedirect();

        $invitation = Invitation::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('email', 'peer@example.com')
            ->firstOrFail();

        $invitee = User::factory()->create(['email' => 'peer@example.com']);
        $this->actingAs($invitee)
            ->get(route('invitations.accept', $invitation->token))
            ->assertRedirect(route('home'));

        app(WorkspaceContext::class)->set($workspace);

        $this->assertSame(ProjectRole::Manager, ProjectMember::query()
            ->where('project_id', $alpha->id)
            ->where('user_id', $invitee->id)
            ->value('role'));
        $this->assertSame(ProjectRole::Guest, ProjectMember::query()
            ->where('project_id', $beta->id)
            ->where('user_id', $invitee->id)
            ->value('role'));

        // No implicit access: the untouched project stays invisible.
        $this->assertNull(ProjectMember::query()
            ->where('project_id', $gamma->id)
            ->where('user_id', $invitee->id)
            ->value('role'));

        $scope = app(WorkspaceContext::class)->accessibleProjectIds($invitee);
        sort($scope);
        $expected = [$alpha->id, $beta->id];
        sort($expected);
        $this->assertSame($expected, $scope);
    }

    public function test_acceptance_never_overwrites_an_existing_team_line(): void
    {
        [$owner, $workspace, $alpha] = $this->setupWorkspace();

        $member = User::factory()->create(['email' => 'peer@example.com']);
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);
        $alpha->projectMembers()->create(['user_id' => $member->id, 'role' => 'guest']);

        // The invitation predates the membership: a current member can
        // no longer be invited, but an outstanding link still replays.
        $invitation = Invitation::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'email' => 'peer@example.com',
            'role' => WorkspaceRole::Member->value,
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);
        $invitation->attachProjects([
            ['project_id' => $alpha->id, 'role' => ProjectRole::Manager->value],
        ]);

        $this->actingAs($member)
            ->get(route('invitations.accept', $invitation->token))
            ->assertRedirect(route('home'));

        // The hand-granted line survives the announced role — firstOrCreate.
        $this->assertSame(ProjectRole::Guest, ProjectMember::query()
            ->where('project_id', $alpha->id)
            ->where('user_id', $member->id)
            ->value('role'));
    }

    public function test_guest_acceptance_applies_the_announced_roles(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->post(route('workspaces.invite', $workspace), [
                'email' => 'client@example.com',
                'role' => 'guest',
                'projects' => [
                    ['project_id' => $alpha->id, 'role' => 'manager'],
                    ['project_id' => $beta->id, 'role' => 'guest'],
                ],
            ])
            ->assertRedirect();

        $invitation = Invitation::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('email', 'client@example.com')
            ->firstOrFail();

        $invitee = User::factory()->create(['email' => 'client@example.com']);
        $this->actingAs($invitee)
            ->get(route('invitations.accept', $invitation->token))
            ->assertRedirect(route('home'));

        app(WorkspaceContext::class)->set($workspace);

        $membership = Membership::where('user_id', $invitee->id)
            ->where('workspace_id', $workspace->id)
            ->firstOrFail();
        $this->assertSame(WorkspaceRole::Guest, $membership->role);

        $this->assertSame(ProjectRole::Manager, ProjectMember::query()
            ->where('project_id', $alpha->id)
            ->where('user_id', $invitee->id)
            ->value('role'));
        $this->assertSame(ProjectRole::Guest, ProjectMember::query()
            ->where('project_id', $beta->id)
            ->where('user_id', $invitee->id)
            ->value('role'));
    }

    // ── Réinvitation : les projets et rôles annoncés se resynchronisent ──

    public function test_reinviting_resynchronizes_the_announced_projects_and_roles(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->post(route('workspaces.invite', $workspace), [
                'email' => 'peer@example.com',
                'role' => 'member',
                'projects' => [['project_id' => $alpha->id, 'role' => 'manager']],
            ])
            ->assertRedirect();

        $this->actingAs($owner)
            ->post(route('workspaces.invite', $workspace), [
                'email' => 'peer@example.com',
                'role' => 'member',
                'projects' => [
                    ['project_id' => $alpha->id, 'role' => 'guest'],
                    ['project_id' => $beta->id, 'role' => 'member'],
                ],
            ])
            ->assertRedirect();

        $invitation = Invitation::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('email', 'peer@example.com')
            ->firstOrFail();

        // The latest invitation wins: same perimeter, fresh roles.
        $this->assertSame(2, InvitationProject::query()
            ->where('invitation_id', $invitation->id)
            ->count());
        $this->assertSame(ProjectRole::Guest, InvitationProject::query()
            ->where('invitation_id', $invitation->id)
            ->where('project_id', $alpha->id)
            ->value('role'));

        $invitee = User::factory()->create(['email' => 'peer@example.com']);
        $this->actingAs($invitee)
            ->get(route('invitations.accept', $invitation->token))
            ->assertRedirect(route('home'));

        app(WorkspaceContext::class)->set($workspace);

        $this->assertSame(ProjectRole::Guest, ProjectMember::query()
            ->where('project_id', $alpha->id)
            ->where('user_id', $invitee->id)
            ->value('role'));
        $this->assertSame(ProjectRole::Member, ProjectMember::query()
            ->where('project_id', $beta->id)
            ->where('user_id', $invitee->id)
            ->value('role'));
    }

    // ── Surfaces : page Membres, page d'acceptation, e-mail ────────────

    public function test_members_page_lists_the_invitations_with_project_roles(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->post(route('workspaces.invite', $workspace), [
                'email' => 'peer@example.com',
                'role' => 'member',
                'projects' => [
                    ['project_id' => $alpha->id, 'role' => 'manager'],
                    ['project_id' => $beta->id, 'role' => 'guest'],
                ],
            ])
            ->assertRedirect();

        $this->actingAs($owner)
            ->get(route('settings.members'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('invitations', 1)
                ->where('invitations.0.projects.0.name', 'Alpha')
                ->where('invitations.0.projects.0.role', 'manager')
                ->where('invitations.0.projects.1.name', 'Beta')
                ->where('invitations.0.projects.1.role', 'guest'));
    }

    public function test_invitation_landing_shows_the_project_roles(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();

        // Object call: the landing below must render for an anonymous
        // visitor, so no authenticated request runs in this test.
        $invitation = InviteMember::run($workspace, [
            'email' => 'peer@example.com',
            'role' => 'member',
            'projects' => [
                ['project_id' => $alpha->id, 'role' => 'manager'],
                ['project_id' => $beta->id, 'role' => 'guest'],
            ],
        ]);

        $this->get(route('invitations.accept', $invitation->token))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/AcceptInvitation')
                ->where('invitation.projects.0.name', 'Alpha')
                ->where('invitation.projects.0.role', 'manager')
                ->where('invitation.projects.1.name', 'Beta')
                ->where('invitation.projects.1.role', 'guest'));
    }

    public function test_invitation_mail_lists_the_projects_with_their_roles(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();

        Mail::fake();

        $this->actingAs($owner)
            ->post(route('workspaces.invite', $workspace), [
                'email' => 'peer@example.com',
                'role' => 'member',
                'projects' => [
                    ['project_id' => $alpha->id, 'role' => 'manager'],
                    ['project_id' => $beta->id, 'role' => 'guest'],
                ],
            ])
            ->assertRedirect();

        $expected = [
            'Alpha ('.__('mail.invitation.project_roles.manager').')',
            'Beta ('.__('mail.invitation.project_roles.guest').')',
        ];

        Mail::assertSent(InvitationMail::class, fn (InvitationMail $mail): bool => $mail->projectNames === $expected);
    }
}
