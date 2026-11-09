<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\SetAssignees;
use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\DigestFrequency;
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
use App\Models\User;
use App\Models\Workspace;
use App\Support\MentionParser;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

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

    // ── Invitation d'un membre avec périmètre ──────────────────────────

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

    public function test_member_invitation_without_projects_stays_unscoped(): void
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

        // The default member invitation keeps full access on acceptance.
        $invitee = User::factory()->create(['email' => 'peer@example.com']);
        $this->actingAs($invitee)
            ->get(route('invitations.accept', $invitation->token))
            ->assertRedirect(route('home'));

        $membership = Membership::where('user_id', $invitee->id)
            ->where('workspace_id', $workspace->id)
            ->firstOrFail();

        $this->assertFalse((bool) $membership->is_scoped);
    }

    // ── Acceptation ───────────────────────────────────────────────────

    public function test_accepting_a_scoped_member_invitation_creates_a_scoped_membership(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();
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
        $this->assertTrue((bool) $membership->is_scoped);
        $this->assertSame(
            [$alpha->id, $beta->id],
            $membership->projectScopeIds(),
        );
    }

    public function test_acceptance_by_an_existing_member_attaches_the_announced_scope(): void
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

        $membership = Membership::where('user_id', $member->id)
            ->where('workspace_id', $workspace->id)
            ->firstOrFail();

        // No duplicate membership, and the latest invitation's perimeter
        // applies: the member becomes scoped to the announced projects.
        $this->assertSame(WorkspaceRole::Member, $membership->role);
        $this->assertTrue((bool) $membership->is_scoped);
        $this->assertSame([$alpha->id], $membership->projectScopeIds());
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
        $alpha->projectMembers()->create(['user_id' => $guest->id, 'role' => 'member']);

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
        // perimeter stays roster-driven, no membership scope is attached.
        $this->assertSame(WorkspaceRole::Guest, $guestMembership->role);
        $this->assertFalse((bool) $guestMembership->is_scoped);
        $this->assertSame([], $guestMembership->projectScopeIds());
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
                ->where('invitation.projects.0', 'Alpha')
                ->where('invitation.projects.1', 'Beta'));
    }

    // ── Pilotage du périmètre après acceptation ────────────────────────

    public function test_owner_can_update_a_member_perimeter(): void
    {
        [$owner, $workspace, $alpha, $beta, $gamma] = $this->setupWorkspace();
        [$member, $membership] = $this->addMember($workspace);
        $membership->update(['is_scoped' => true]);
        $membership->syncProjectScope([$alpha->id, $beta->id]);

        $this->actingAs($owner)
            ->patch(route('workspaces.members.scope', [$workspace->id, $member->id]), [
                'project_ids' => [$alpha->id, $gamma->id],
            ])
            ->assertRedirect(route('settings.members'));

        $membership->refresh();
        $this->assertTrue((bool) $membership->is_scoped);
        $this->assertSame(
            [$alpha->id, $gamma->id],
            $membership->projectScopeIds(),
        );

        app(WorkspaceContext::class)->set($workspace);
        $scope = app(WorkspaceContext::class)->accessibleProjectIds($member);
        sort($scope);
        $this->assertSame([$alpha->id, $gamma->id], $scope);
    }

    public function test_empty_perimeter_list_unscopes_the_member(): void
    {
        [$owner, $workspace, $alpha] = $this->setupWorkspace();
        [$member, $membership] = $this->addMember($workspace);
        $membership->update(['is_scoped' => true]);
        $membership->syncProjectScope([$alpha->id]);

        $this->actingAs($owner)
            ->patch(route('workspaces.members.scope', [$workspace->id, $member->id]), [
                'project_ids' => [],
            ])
            ->assertRedirect(route('settings.members'));

        $membership->refresh();
        $this->assertFalse((bool) $membership->is_scoped);
        $this->assertSame([], $membership->projectScopeIds());
    }

    public function test_non_owner_cannot_update_a_member_perimeter(): void
    {
        [$owner, $workspace, $alpha] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);
        [$other] = $this->addMember($workspace);

        $this->actingAs($member)
            ->patch(route('workspaces.members.scope', [$workspace->id, $other->id]), [
                'project_ids' => [$alpha->id],
            ])
            ->assertForbidden();
    }

    public function test_perimeter_update_refuses_guest_and_owner_targets(): void
    {
        [$owner, $workspace, $alpha] = $this->setupWorkspace();

        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);

        $this->actingAs($owner)
            ->patch(route('workspaces.members.scope', [$workspace->id, $guest->id]), [
                'project_ids' => [$alpha->id],
            ])
            ->assertInvalid('role');

        // The owner themselves is unrestricted by definition.
        $this->actingAs($owner)
            ->patch(route('workspaces.members.scope', [$workspace->id, $owner->id]), [
                'project_ids' => [$alpha->id],
            ])
            ->assertInvalid('role');
    }

    public function test_perimeter_update_for_a_non_member_is_not_found(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $stranger = User::factory()->create();

        $this->actingAs($owner)
            ->patch(route('workspaces.members.scope', [$workspace->id, $stranger->id]), [
                'project_ids' => [],
            ])
            ->assertNotFound();
    }

    public function test_perimeter_update_rejects_foreign_projects(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        [$member] = $this->addMember($workspace);

        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Other']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $foreign = CreateProject::run($otherWorkspace, $otherOwner, ['name' => 'Foreign']);
        app(WorkspaceContext::class)->set($workspace);

        $this->actingAs($owner)
            ->patch(route('workspaces.members.scope', [$workspace->id, $member->id]), [
                'project_ids' => [$foreign->id],
            ])
            ->assertInvalid('project_ids.0');
    }

    public function test_removing_a_member_cleans_the_perimeter_rows(): void
    {
        [$owner, $workspace, $alpha] = $this->setupWorkspace();
        [$member, $membership] = $this->addMember($workspace);
        $membership->update(['is_scoped' => true]);
        $membership->syncProjectScope([$alpha->id]);
        $membershipId = $membership->id;

        $this->actingAs($owner)
            ->delete(route('workspaces.members.destroy', [$workspace->id, $member->id]))
            ->assertRedirect(route('settings.members'));

        $this->assertDatabaseMissing('membership_project', ['membership_id' => $membershipId]);
    }

    // ── Page Membres : présentation du périmètre ───────────────────────

    public function test_members_page_shows_the_member_perimeter(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();
        [$member, $membership] = $this->addMember($workspace);
        $membership->update(['is_scoped' => true]);
        $membership->syncProjectScope([$alpha->id, $beta->id]);

        $this->actingAs($owner)
            ->get(route('settings.members'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('members.1.id', $member->id)
                ->where('members.1.isScoped', true)
                ->where('members.1.scopeProjects.0.name', 'Alpha')
                ->where('members.1.scopeProjects.1.name', 'Beta'));

        // An unscoped member shows no perimeter at all.
        [$plain] = $this->addMember($workspace);

        $this->actingAs($owner)
            ->get(route('settings.members'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('members.2.id', $plain->id)
                ->where('members.2.isScoped', false)
                ->has('members.2.scopeProjects', 0));
    }

    // ── Surfaces web : le périmètre s'applique partout ─────────────────

    public function test_scoped_member_sees_only_perimeter_projects(): void
    {
        [$owner, $workspace, $alpha, $beta, $gamma] = $this->setupWorkspace();
        [$member, $membership] = $this->addMember($workspace);
        $membership->update(['is_scoped' => true]);
        $membership->syncProjectScope([$alpha->id]);

        $this->actingAs($member)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('projects.active', 1)
                ->where('projects.active.0.name', 'Alpha'));
    }

    public function test_scoped_member_gets_404_on_out_of_perimeter_project_surfaces(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();
        [$member, $membership] = $this->addMember($workspace);
        $membership->update(['is_scoped' => true]);
        $membership->syncProjectScope([$alpha->id]);

        // Out of perimeter: the project behaves as non-existent — 404,
        // never a 403 that would confirm it exists.
        $this->actingAs($member)->get(route('projects.board', $beta->id))->assertNotFound();
        $this->actingAs($member)->get(route('projects.health', $beta->id))->assertNotFound();
        $this->actingAs($member)->get(route('projects.members.index', $beta->id))->assertNotFound();
        $this->actingAs($member)->get(route('projects.activity', $beta->id))->assertNotFound();

        // In perimeter: every surface answers.
        $this->actingAs($member)->get(route('projects.board', $alpha->id))->assertOk();
        $this->actingAs($member)->get(route('projects.health', $alpha->id))->assertOk();
    }

    public function test_scoped_member_issues_list_and_detail_honor_perimeter(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();
        [$member, $membership] = $this->addMember($workspace);
        $membership->update(['is_scoped' => true]);
        $membership->syncProjectScope([$alpha->id]);

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

    public function test_scoped_member_cannot_create_issues_out_of_perimeter(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();
        [$member, $membership] = $this->addMember($workspace);
        $membership->update(['is_scoped' => true]);
        $membership->syncProjectScope([$alpha->id]);

        $this->actingAs($member)
            ->post(route('issues.store'), [
                'project_id' => $beta->id,
                'title' => 'Ticket interdit',
            ])
            ->assertInvalid('project_id');

        $this->assertDatabaseMissing('issues', ['title' => 'Ticket interdit']);
    }

    public function test_scoped_member_keeps_common_surfaces(): void
    {
        Queue::fake();
        [$owner, $workspace, $alpha] = $this->setupWorkspace();
        [$member, $membership] = $this->addMember($workspace);
        $membership->update(['is_scoped' => true]);
        $membership->syncProjectScope([$alpha->id]);

        CreateKnowledgePage::run($workspace, $owner, ['title' => 'Doc transverse', 'body' => 'Corps']);

        // A scoped member remains a full member: members page, home and
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

    public function test_scoped_member_knowledge_project_page_out_of_perimeter_is_404(): void
    {
        Queue::fake();
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();
        [$member, $membership] = $this->addMember($workspace);
        $membership->update(['is_scoped' => true]);
        $membership->syncProjectScope([$alpha->id]);

        $pageIn = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Doc dedans', 'body' => 'Corps', 'project_id' => $alpha->id]);
        $pageOut = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Doc dehors', 'body' => 'Corps', 'project_id' => $beta->id]);

        $this->actingAs($member)->get(route('knowledge.show', $pageIn->id))->assertOk();
        $this->actingAs($member)->get(route('knowledge.show', $pageOut->id))->assertNotFound();
    }

    public function test_scoped_member_can_create_a_project_and_sees_it(): void
    {
        [$owner, $workspace, $alpha] = $this->setupWorkspace();
        [$member, $membership] = $this->addMember($workspace);
        $membership->update(['is_scoped' => true]);
        $membership->syncProjectScope([$alpha->id]);

        // Creating a project is a member right: the creator owns it, and a
        // member can never be blind to a project they own.
        $created = CreateProject::run($workspace, $member, ['name' => 'Projet du membre']);

        $this->actingAs($member)->get(route('projects.board', $created->id))->assertOk();

        app(WorkspaceContext::class)->set($workspace);
        $scope = app(WorkspaceContext::class)->accessibleProjectIds($member);
        $this->assertContains($created->id, $scope);
    }

    // ── Notifications : digest et mentions limités au périmètre ───────

    public function test_scoped_member_digest_is_limited_to_perimeter(): void
    {
        Mail::fake();
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();
        [$member, $membership] = $this->addMember($workspace);
        $membership->update(['is_scoped' => true]);
        $membership->syncProjectScope([$alpha->id]);

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

    public function test_project_mentions_exclude_a_scoped_member_out_of_perimeter(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();
        [$member, $membership] = $this->addMember($workspace);
        $membership->update(['is_scoped' => true]);
        $membership->syncProjectScope([$alpha->id]);

        // The member sits on both teams, but only Alpha is in perimeter.
        $alpha->projectMembers()->create(['user_id' => $member->id, 'role' => 'member']);
        $beta->projectMembers()->create(['user_id' => $member->id, 'role' => 'member']);

        $inTargets = MentionParser::resolve($workspace, $owner, '@alpha on y va.', $alpha->id);
        $outTargets = MentionParser::resolve($workspace, $owner, '@beta on y va.', $beta->id);

        $this->assertSame([$member->id], array_map(fn (array $t) => $t['user']->id, $inTargets));
        $this->assertSame([], array_map(fn (array $t) => $t['user']->id, $outTargets));
    }

    // ── Outils agents : mêmes règles, mêmes formes d'erreur ───────────

    public function test_scoped_member_mcp_list_projects_is_filtered(): void
    {
        [$owner, $workspace, $alpha, $beta, $gamma] = $this->setupWorkspace();
        [$member, $membership] = $this->addMember($workspace);
        $membership->update(['is_scoped' => true]);
        $membership->syncProjectScope([$alpha->id]);

        $projects = TaskrServer::actingAs($member)->tool(ListProjects::class, [
            'workspace_id' => $workspace->id,
        ])->assertOk()->payload();

        $this->assertSame(['Alpha'], array_column($projects['projects'], 'name'));
    }

    public function test_scoped_member_mcp_get_issue_out_of_perimeter_is_not_found(): void
    {
        [$owner, $workspace, $alpha, $beta] = $this->setupWorkspace();
        [$member, $membership] = $this->addMember($workspace);
        $membership->update(['is_scoped' => true]);
        $membership->syncProjectScope([$alpha->id]);

        $issueOut = CreateIssue::run($workspace, ['project_id' => $beta->id, 'title' => 'Ticket masqué']);

        $response = TaskrServer::actingAs($member)->tool(GetIssue::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issueOut->id,
        ]);

        $this->assertSame('not_found', $response->payload()['error']);
    }

    public function test_scoped_member_mcp_keeps_full_member_tools(): void
    {
        [$owner, $workspace, $alpha] = $this->setupWorkspace();
        [$member, $membership] = $this->addMember($workspace);
        $membership->update(['is_scoped' => true]);
        $membership->syncProjectScope([$alpha->id]);

        // A scoped member stays a full member: labels and members listing
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

    public function test_scoped_member_mcp_get_project_health_keeps_global_kpis(): void
    {
        [$owner, $workspace, $alpha] = $this->setupWorkspace();
        [$member, $membership] = $this->addMember($workspace);
        $membership->update(['is_scoped' => true]);
        $membership->syncProjectScope([$alpha->id]);

        // Roster manager of an in-perimeter project: a scoped member is a
        // full member, the global KPIs are theirs — unlike a guest.
        $alpha->projectMembers()->create(['user_id' => $member->id, 'role' => 'manager']);

        $payload = TaskrServer::actingAs($member)->tool(GetProjectHealth::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $alpha->id,
        ])->assertOk()->payload();

        $this->assertArrayHasKey('health', $payload);
        $this->assertArrayNotHasKey('myWorkload', $payload);
        $this->assertSame(0, $payload['health']['totalIssues']);
    }
}
