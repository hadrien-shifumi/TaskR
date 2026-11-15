<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\SetAssignees;
use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\DigestFrequency;
use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Jobs\SendAccountDigest;
use App\Mail\DigestMail;
use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\CreateLabel;
use App\Mcp\Tools\GetIssue;
use App\Mcp\Tools\GetProjectHealth;
use App\Mcp\Tools\ListMembers;
use App\Mcp\Tools\ListProjects;
use App\Models\Invitation;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\NotificationPreference;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\MentionParser;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

/**
 * The project team is the single source of project access. These tests
 * pin the doctrine across the web surfaces, the notifications and the
 * agent tools: a member or a guest sees exactly the projects they hold
 * a roster line on, the workspace owner sees everything, and the
 * invitations onboard their announced projects as real teams.
 */
class MemberScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TestResponse::macro('payload', function (): array {
            /** @var TestResponse $this */
            return $this->response->toArray()['result']['structuredContent'] ?? [];
        });
    }

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

    /**
     * @return array{0: User, 1: Membership}
     */
    private function addMember(Workspace $workspace): array
    {
        $user = User::factory()->create();
        $membership = Membership::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        return [$user, $membership];
    }

    /**
     * Onboard a member onto the given project teams.
     *
     * @param  array<int, Project>  $projects
     */
    private function joinTeams(User $user, array $projects, string $role = 'member'): void
    {
        foreach ($projects as $project) {
            $project->projectMembers()->create(['user_id' => $user->id, 'role' => $role]);
        }
    }

    /**
     * @param  array<int, Project>  $projects
     */
    private function memberInvitation(Workspace $workspace, array $projects): Invitation
    {
        $invitation = Invitation::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'email' => 'peer@example.com',
            'role' => WorkspaceRole::Member->value,
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);

        $invitation->attachScope(array_map(fn (Project $project) => $project->id, $projects));

        return $invitation;
    }

    // ── Invitation d'un membre avec des projets annoncés ──────────────

    public function test_owner_can_invite_a_member_with_a_project_scope(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();

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

    public function test_member_invitation_without_projects_leaves_the_invitee_teamless(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->post(route('workspaces.invite', $workspace), [
                'email' => 'peer@example.com',
                'role' => 'member',
            ])
            ->assertRedirect();

        $invitation = Invitation::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('email', 'peer@example.com')
            ->firstOrFail();

        $this->assertSame([], $invitation->projects()->pluck('projects.id')->all());

        // No announced project, no team: the accepted member belongs to
        // the workspace but sees no project — the pivot of the rework.
        $invitee = User::factory()->create(['email' => 'peer@example.com']);
        $this->actingAs($invitee)
            ->get(route('invitations.accept', $invitation->token))
            ->assertRedirect(route('home'));

        app(WorkspaceContext::class)->set($workspace);

        $this->assertSame(0, ProjectMember::query()->where('user_id', $invitee->id)->count());
        $this->assertSame([], app(WorkspaceContext::class)->accessibleProjectIds($invitee));
    }

    // ── Acceptation ───────────────────────────────────────────────────

    public function test_accepting_a_member_invitation_with_projects_joins_the_announced_teams(): void
    {
        [$owner, $workspace, $alpha, $beta, $gamma] = $this->setupWorkspace();
        $invitation = $this->memberInvitation($workspace, [$alpha, $beta]);

        $invitee = User::factory()->create(['email' => 'peer@example.com']);

        $this->actingAs($invitee)
            ->get(route('invitations.accept', $invitation->token))
            ->assertRedirect(route('home'));

        app(WorkspaceContext::class)->set($workspace);

        $membership = Membership::where('user_id', $invitee->id)
            ->where('workspace_id', $workspace->id)
            ->firstOrFail();

        $this->assertSame(WorkspaceRole::Member, $membership->role);

        // The announced projects are the joined teams, with the plain
        // contributor role — and nothing else opens up.
        $this->assertSame(
            ProjectRole::Member,
            ProjectMember::query()->where('project_id', $alpha->id)->where('user_id', $invitee->id)->value('role'),
        );
        $this->assertSame(
            ProjectRole::Member,
            ProjectMember::query()->where('project_id', $beta->id)->where('user_id', $invitee->id)->value('role'),
        );
        $this->assertNull(
            ProjectMember::query()->where('project_id', $gamma->id)->where('user_id', $invitee->id)->value('role'),
        );

        $scope = app(WorkspaceContext::class)->accessibleProjectIds($invitee);
        sort($scope);
        $expected = [$alpha->id, $beta->id];
        sort($expected);
        $this->assertSame($expected, $scope);
    }

    public function test_acceptance_by_an_existing_member_joins_the_announced_teams(): void
    {
        [$owner, $workspace, $alpha] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);

        $invitation = Invitation::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'email' => $member->email,
            'role' => WorkspaceRole::Member->value,
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);
        $invitation->attachScope([$alpha->id]);

        $this->actingAs($member)
            ->get(route('invitations.accept', $invitation->token))
            ->assertRedirect(route('home'));

        app(WorkspaceContext::class)->set($workspace);

        // No duplicate membership, and the latest invitation's projects
        // are the joined teams.
        $this->assertSame(1, Membership::where('user_id', $member->id)->where('workspace_id', $workspace->id)->count());
        $this->assertSame(
            ProjectRole::Member,
            ProjectMember::query()->where('project_id', $alpha->id)->where('user_id', $member->id)->value('role'),
        );
    }

    public function test_acceptance_by_an_existing_member_preserves_their_manual_team_roles(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);
        $this->joinTeams($member, [$alpha], 'manager');

        $invitation = Invitation::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'email' => $member->email,
            'role' => WorkspaceRole::Member->value,
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);
        $invitation->attachScope([$alpha->id, $beta->id]);

        $this->actingAs($member)
            ->get(route('invitations.accept', $invitation->token))
            ->assertRedirect(route('home'));

        app(WorkspaceContext::class)->set($workspace);

        // A team role granted by hand is never downgraded by the acceptance.
        $this->assertSame(
            ProjectRole::Manager,
            ProjectMember::query()->where('project_id', $alpha->id)->where('user_id', $member->id)->value('role'),
        );
        $this->assertSame(
            ProjectRole::Member,
            ProjectMember::query()->where('project_id', $beta->id)->where('user_id', $member->id)->value('role'),
        );
    }

    public function test_acceptance_by_an_existing_guest_keeps_the_roster_perimeter(): void
    {
        [$owner, $workspace, $alpha] = $this->setupWorkspace();

        $guest = User::factory()->create();
        $guestMembership = Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);
        $this->joinTeams($guest, [$alpha]);

        $invitation = Invitation::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'email' => $guest->email,
            'role' => WorkspaceRole::Member->value,
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);
        $invitation->attachScope([$alpha->id]);

        $this->actingAs($guest)
            ->get(route('invitations.accept', $invitation->token))
            ->assertRedirect(route('home'));

        $guestMembership->refresh();

        // An already-member keeps their role — here a guest: their
        // perimeter stays roster-driven, nothing is rewritten.
        $this->assertSame(WorkspaceRole::Guest, $guestMembership->role);
        $this->assertSame(
            1,
            ProjectMember::query()->where('user_id', $guest->id)->count(),
        );
    }

    public function test_invitation_landing_shows_the_member_scope_projects(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();
        $invitation = $this->memberInvitation($workspace, [$alpha, $beta]);

        $this->get(route('invitations.accept', $invitation->token))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/AcceptInvitation')
                ->where('invitation.role', 'member')
                ->where('invitation.projects.0.name', 'Alpha')
                ->where('invitation.projects.0.role', 'member')
                ->where('invitation.projects.1.name', 'Beta')
                ->where('invitation.projects.1.role', 'member'));
    }

    // ── Retrait du workspace ─────────────────────────────────────────

    public function test_removing_a_member_cleans_their_roster_rows(): void
    {
        [$owner, $workspace, $alpha] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);
        $this->joinTeams($member, [$alpha]);

        $this->actingAs($owner)
            ->delete(route('workspaces.members.destroy', [$workspace->id, $member->id]))
            ->assertRedirect(route('settings.members'));

        $this->assertDatabaseMissing('project_members', [
            'project_id' => $alpha->id,
            'user_id' => $member->id,
        ]);
    }

    public function test_removing_a_member_releases_all_their_workspace_assignments(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);
        [$other] = $this->addMember($workspace);
        $this->joinTeams($member, [$alpha, $beta]);

        $issueAlpha = CreateIssue::run($workspace, ['project_id' => $alpha->id, 'title' => 'Dans Alpha']);
        $issueBeta = CreateIssue::run($workspace, ['project_id' => $beta->id, 'title' => 'Dans Beta']);
        SetAssignees::run($issueAlpha, [$member->id, $other->id]);
        SetAssignees::run($issueBeta, [$member->id]);

        $this->actingAs($owner)
            ->delete(route('workspaces.members.destroy', [$workspace->id, $member->id]))
            ->assertRedirect(route('settings.members'));

        // D15: the removal releases every assignment of the workspace —
        // all projects at once, in the same transaction as the rows.
        $this->assertSame(0, DB::table('issue_assignees')
            ->where('user_id', $member->id)
            ->whereIn('issue_id', [$issueAlpha->id, $issueBeta->id])
            ->count());

        // The issues stay, with their history — and the other member's
        // assignments are untouched.
        $this->assertDatabaseHas('issues', ['id' => $issueAlpha->id]);
        $this->assertDatabaseHas('issues', ['id' => $issueBeta->id]);
        $this->assertDatabaseHas('issue_assignees', [
            'issue_id' => $issueAlpha->id,
            'user_id' => $other->id,
        ]);
    }

    public function test_removing_a_member_keeps_their_assignments_in_other_workspaces(): void
    {
        [$owner, $workspace, $alpha] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);
        $this->joinTeams($member, [$alpha]);

        // A second workspace where the same person is assigned: the
        // cascade is scoped to the workspace the membership left.
        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Other']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $foreign = CreateProject::run($otherWorkspace, $otherOwner, ['name' => 'Foreign']);
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $otherWorkspace->id,
            'role' => 'member',
        ]);
        $foreign->projectMembers()->create(['user_id' => $member->id, 'role' => 'member']);
        $foreignIssue = CreateIssue::run($otherWorkspace, ['project_id' => $foreign->id, 'title' => 'Ailleurs']);
        SetAssignees::run($foreignIssue, [$member->id]);

        app(WorkspaceContext::class)->set($workspace);
        $this->actingAs($owner)
            ->delete(route('workspaces.members.destroy', [$workspace->id, $member->id]))
            ->assertRedirect(route('settings.members'));

        $this->assertDatabaseHas('issue_assignees', [
            'issue_id' => $foreignIssue->id,
            'user_id' => $member->id,
        ]);
    }

    // ── Page Membres : plus aucun champ de périmètre ──────────────────

    public function test_members_page_carries_no_perimeter_fields(): void
    {
        [$owner, $workspace, $alpha] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);
        $this->joinTeams($member, [$alpha]);

        $this->actingAs($owner)
            ->get(route('settings.members'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('members.1.id', $member->id)
                ->where('members.1.role', 'member')
                ->missing('members.1.isScoped')
                ->missing('members.1.scopeProjects'));
    }

    // ── Surfaces web : l'équipe pilote tout ───────────────────────────

    public function test_teamed_member_sees_only_their_team_projects(): void
    {
        [$owner, $workspace, $alpha, $beta, $gamma] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);
        $this->joinTeams($member, [$alpha]);

        $this->actingAs($member)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('projects.active', 1)
                ->where('projects.active.0.name', 'Alpha'));
    }

    public function test_teamed_member_gets_404_on_out_of_team_project_surfaces(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);
        $this->joinTeams($member, [$alpha]);

        // Off the team, the project behaves as non-existent — 404, never
        // a 403 that would confirm it exists.
        $this->actingAs($member)->get(route('projects.board', $beta->id))->assertNotFound();
        $this->actingAs($member)->get(route('projects.health', $beta->id))->assertNotFound();
        $this->actingAs($member)->get(route('projects.members.index', $beta->id))->assertNotFound();
        $this->actingAs($member)->get(route('projects.activity', $beta->id))->assertNotFound();

        // On the team: every surface answers.
        $this->actingAs($member)->get(route('projects.board', $alpha->id))->assertOk();
        $this->actingAs($member)->get(route('projects.health', $alpha->id))->assertOk();
    }

    public function test_teamed_member_issues_list_and_detail_honor_the_team(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);
        $this->joinTeams($member, [$alpha]);

        $issueIn = CreateIssue::run($workspace, ['project_id' => $alpha->id, 'title' => 'Ticket dedans']);
        $issueOut = CreateIssue::run($workspace, ['project_id' => $beta->id, 'title' => 'Ticket dehors']);

        $this->actingAs($member)
            ->get(route('issues.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 1)
                ->where('totalUnfiltered', 1)
                ->where('issues.0.title', 'Ticket dedans'));

        $this->actingAs($member)->get(route('issues.show', $issueIn->id))->assertOk();
        $this->actingAs($member)->get(route('issues.show', $issueOut->id))->assertNotFound();
    }

    public function test_teamed_member_cannot_create_issues_out_of_their_teams(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);
        $this->joinTeams($member, [$alpha]);

        $this->actingAs($member)
            ->post(route('issues.store'), [
                'project_id' => $beta->id,
                'title' => 'Ticket interdit',
            ])
            ->assertInvalid('project_id');

        $this->assertDatabaseMissing('issues', ['title' => 'Ticket interdit']);
    }

    public function test_teamed_member_keeps_common_surfaces(): void
    {
        Queue::fake();
        [$owner, $workspace, $alpha] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);
        $this->joinTeams($member, [$alpha]);

        CreateKnowledgePage::run($workspace, $owner, ['title' => 'Doc transverse', 'body' => 'Corps']);

        // A teamed member remains a full member: members page, home and
        // transverse knowledge all answer.
        $this->actingAs($member)->get(route('settings.members'))->assertOk();
        $this->actingAs($member)->get(route('home'))->assertOk();
        $this->actingAs($member)
            ->get(route('knowledge.index', ['project' => 'transverse']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('pages', 1)
                ->where('pages.0.title', 'Doc transverse'));
    }

    public function test_teamed_member_knowledge_project_page_out_of_team_is_404(): void
    {
        Queue::fake();
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);
        $this->joinTeams($member, [$alpha]);

        $pageIn = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Doc dedans', 'body' => 'Corps', 'project_id' => $alpha->id]);
        $pageOut = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Doc dehors', 'body' => 'Corps', 'project_id' => $beta->id]);

        $this->actingAs($member)->get(route('knowledge.show', $pageIn->id))->assertOk();
        $this->actingAs($member)->get(route('knowledge.show', $pageOut->id))->assertNotFound();
    }

    public function test_member_can_create_a_project_and_sees_it(): void
    {
        [$owner, $workspace, $alpha] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);
        $this->joinTeams($member, [$alpha]);

        // Creating a project is a member right: the creator joins its
        // team as owner, and a member is never blind to their own project.
        $created = CreateProject::run($workspace, $member, ['name' => 'Projet du membre']);

        $this->actingAs($member)->get(route('projects.board', $created->id))->assertOk();

        app(WorkspaceContext::class)->set($workspace);
        $scope = app(WorkspaceContext::class)->accessibleProjectIds($member);
        $this->assertContains($created->id, $scope);
    }

    public function test_member_without_team_rows_sees_no_project(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);

        CreateIssue::run($workspace, ['project_id' => $alpha->id, 'title' => 'Ticket invisible']);

        // Belongs to the workspace, no team line anywhere: every project
        // surface behaves as non-existent.
        $this->actingAs($member)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('projects.active', 0)
                ->has('projects.archived', 0));

        $this->actingAs($member)->get(route('projects.board', $alpha->id))->assertNotFound();
        $this->actingAs($member)->get(route('projects.board', $beta->id))->assertNotFound();

        $this->actingAs($member)
            ->get(route('issues.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 0)
                ->where('totalUnfiltered', 0));

        // The workspace itself stays usable.
        $this->actingAs($member)->get(route('home'))->assertOk();
    }

    public function test_workspace_owner_keeps_full_access_without_team_rows(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);

        // A project created by another member: the owner holds no line
        // on it, yet keeps their implicit unrestricted access.
        $created = CreateProject::run($workspace, $member, ['name' => 'Projet du membre']);

        $this->assertSame(0, ProjectMember::query()->where('project_id', $created->id)->where('user_id', $owner->id)->count());

        $this->actingAs($owner)->get(route('projects.board', $created->id))->assertOk();
        $this->actingAs($owner)->get(route('projects.health', $created->id))->assertOk();

        app(WorkspaceContext::class)->set($workspace);
        $this->assertNull(app(WorkspaceContext::class)->accessibleProjectIds($owner));
    }

    // ── Notifications : digest et mentions limités aux équipes ────────

    public function test_teamed_member_digest_is_limited_to_their_teams(): void
    {
        Mail::fake();
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);
        $this->joinTeams($member, [$alpha]);

        $status = $workspace->statuses()->where('category', 'in_progress')->firstOrFail();

        $issueIn = CreateIssue::run($workspace, [
            'project_id' => $alpha->id,
            'title' => 'En retard dedans',
            'status_id' => $status->id,
            'due_date' => now()->subDays(2)->toDateString(),
        ]);
        CreateIssue::run($workspace, [
            'project_id' => $beta->id,
            'title' => 'En retard dehors',
            'status_id' => $status->id,
            'due_date' => now()->subDays(2)->toDateString(),
        ]);
        SetAssignees::run($issueIn->fresh(), [$member->id]);
        $issueOut = Issue::query()->where('title', 'En retard dehors')->firstOrFail();
        SetAssignees::run($issueOut, [$member->id]);

        NotificationPreference::factory()->for($member)->enabled()->create([
            'frequency' => DigestFrequency::Daily,
            'timezone' => 'Europe/Paris',
        ]);
        $member->forceFill(['current_workspace_id' => $workspace->id])->save();

        SendAccountDigest::dispatchSync($member->fresh());

        Mail::assertSent(DigestMail::class, function (DigestMail $mail) use ($issueIn): bool {
            $overdueIds = array_map(fn (array $row) => $row['id'], $mail->digest['overdueIssues']);

            return $overdueIds === [$issueIn->id];
        });
    }

    public function test_project_mentions_exclude_a_member_out_of_the_team(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);
        $this->joinTeams($member, [$alpha]);

        $inTargets = MentionParser::resolve($workspace, $owner, '@alpha on y va.', $alpha->id);
        $outTargets = MentionParser::resolve($workspace, $owner, '@beta on y va.', $beta->id);

        $this->assertSame([$member->id], array_map(fn (array $t) => $t['user']->id, $inTargets));
        $this->assertSame([], array_map(fn (array $t) => $t['user']->id, $outTargets));
    }

    // ── Outils agents : mêmes règles, mêmes formes d'erreur ───────────

    public function test_teamed_member_mcp_list_projects_is_filtered(): void
    {
        [$owner, $workspace, $alpha, $beta, $gamma] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);
        $this->joinTeams($member, [$alpha]);

        $projects = TaskrServer::actingAs($member)->tool(ListProjects::class, [
            'workspace_id' => $workspace->id,
        ])->assertOk()->payload();

        $this->assertSame(['Alpha'], array_column($projects['projects'], 'name'));
    }

    public function test_teamed_member_mcp_get_issue_out_of_team_is_not_found(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);
        $this->joinTeams($member, [$alpha]);

        $issueOut = CreateIssue::run($workspace, ['project_id' => $beta->id, 'title' => 'Ticket masqué']);

        $response = TaskrServer::actingAs($member)->tool(GetIssue::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issueOut->id,
        ]);

        $this->assertSame('not_found', $response->payload()['error']);
    }

    public function test_teamed_member_mcp_keeps_full_member_tools(): void
    {
        [$owner, $workspace, $alpha] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);
        $this->joinTeams($member, [$alpha]);

        // A teamed member stays a full member: labels and members listing
        // answer instead of the guest's forbidden.
        $label = TaskrServer::actingAs($member)->tool(CreateLabel::class, [
            'workspace_id' => $workspace->id,
            'name' => 'Priorité client',
        ])->assertOk()->payload();

        $this->assertSame('Priorité client', $label['label']['name']);

        $members = TaskrServer::actingAs($member)->tool(ListMembers::class, [
            'workspace_id' => $workspace->id,
        ])->assertOk()->payload();

        $this->assertNotEmpty($members['members']);
    }

    public function test_teamed_member_mcp_get_project_health_keeps_global_kpis(): void
    {
        [$owner, $workspace, $alpha] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);
        $this->joinTeams($member, [$alpha], 'manager');

        // Roster manager of a teamed project: a member is a full member,
        // the global KPIs are theirs — unlike a guest.
        $payload = TaskrServer::actingAs($member)->tool(GetProjectHealth::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $alpha->id,
        ])->assertOk()->payload();

        $this->assertArrayHasKey('health', $payload);
        $this->assertArrayNotHasKey('myWorkload', $payload);
        $this->assertSame(0, $payload['health']['totalIssues']);
    }
}
