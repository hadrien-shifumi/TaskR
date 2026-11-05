<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Actions\Workspaces\ResendInvitation;
use App\Enums\WorkspaceRole;
use App\Mcp\Resources\ProjectIssues;
use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\GetProjectHealth;
use App\Mcp\Tools\ListProjects;
use App\Mcp\Tools\UpdateIssue;
use App\Models\Invitation;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class GuestCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TestResponse::macro('result', function (): array {
            /** @var TestResponse $this */
            return $this->response->toArray()['result'] ?? [];
        });

        TestResponse::macro('payload', function (): array {
            /** @var TestResponse $this */
            return $this->response->toArray()['result']['structuredContent'] ?? [];
        });
    }

    /**
     * @return array{0: User, 1: User, 2: Workspace, 3: Project, 4: Project}
     */
    private function setupWorkspaceWithMember(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $beta = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        return [$owner, $member, $workspace, $alpha, $beta];
    }

    // ── Rétrocompatibilité : un member voit tout, comme avant ─────────

    public function test_member_sees_all_projects_issues_and_knowledge(): void
    {
        [$owner, $member, $workspace, $alpha, $beta] = $this->setupWorkspaceWithMember();

        // Knowledge vectorization is queued: keep the test off the network.
        Queue::fake();

        $alphaIssue = CreateIssue::run($workspace, ['project_id' => $alpha->id, 'title' => 'A1']);
        $betaIssue = CreateIssue::run($workspace, ['project_id' => $beta->id, 'title' => 'B1']);
        CreateKnowledgePage::run($workspace, $owner, ['title' => 'Transverse', 'body' => 'Page']);

        // Nothing is filtered for a full member: every surface is complete.
        $this->actingAs($member)->get(route('projects.index'))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('projects.active.0.name', 'Alpha')
                ->where('projects.active.1.name', 'Beta'));

        $this->actingAs($member)->get(route('issues.index'))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('issues', 2)
                ->where('totalUnfiltered', 2));

        $this->actingAs($member)->get(route('knowledge.index'))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pages', 1)
                ->where('pages.0.title', 'Transverse'));

        expect($alphaIssue->exists())->toBeTrue()
            ->and($betaIssue->exists())->toBeTrue();
    }

    public function test_mcp_member_response_shapes_are_unchanged(): void
    {
        [$owner, $member, $workspace, $alpha, $beta] = $this->setupWorkspaceWithMember();

        // list_projects: the complete list, with the pre-guest payload shape.
        $projects = TaskrServer::actingAs($member)->tool(ListProjects::class, [
            'workspace_id' => $workspace->id,
        ])->assertOk()->payload();

        $this->assertSame(['projects'], array_keys($projects));
        $this->assertSame(['Alpha', 'Beta'], array_column($projects['projects'], 'name'));

        // get_project_health: a full member still gets the global KPIs.
        $health = TaskrServer::actingAs($member)->tool(GetProjectHealth::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $alpha->id,
        ])->assertOk()->payload();

        $this->assertArrayHasKey('health', $health);
        $this->assertArrayNotHasKey('myWorkload', $health);
        $this->assertSame(0, $health['health']['totalIssues']);

        // Anti-rename guard: the member health contract is the twelve keys,
        // in order — the two hierarchy indicators are additive, never a
        // replacement or a reorder.
        $this->assertSame([
            'issuesByCategory',
            'totalIssues',
            'completionPercentage',
            'overdueIssues',
            'estimatedHours',
            'loggedHours',
            'consumptionRatio',
            'expectedPercentage',
            'status',
            'targetDate',
            'weightedCompletionPercentage',
            'leafCompletionPercentage',
        ], array_keys($health['health']));

        // The project issues resource stays readable with the same shape.
        $result = TaskrServer::actingAs($member)
            ->resource(ProjectIssues::class, ['workspace_id' => $workspace->id, 'project_id' => $beta->id])
            ->assertOk()
            ->result();

        /** @var array{issues: array<int, mixed>} */
        $payload = json_decode($result['contents'][0]['text'], true);
        $this->assertArrayHasKey('issues', $payload);
    }

    // ── Réduction de périmètre : l'accès disparu est introuvable ──────

    public function test_scope_reduction_makes_previously_accessible_issues_not_found(): void
    {
        [$owner, $member, $workspace, $alpha, $beta] = $this->setupWorkspaceWithMember();

        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);
        foreach ([$alpha, $beta] as $project) {
            ProjectMember::create([
                'project_id' => $project->id,
                'user_id' => $guest->id,
                'role' => 'member',
            ]);
        }

        app(WorkspaceContext::class)->set($workspace);
        $issue = CreateIssue::run($workspace, ['project_id' => $beta->id, 'title' => 'Vanishing']);

        // Sanity: accessible while in scope.
        $this->actingAs($guest)->get(route('issues.show', $issue->id))->assertOk();

        // The owner shrinks the perimeter: the removed project's issue
        // fails exactly like a non-existent one, web and MCP alike.
        ProjectMember::query()
            ->where('project_id', $beta->id)
            ->where('user_id', $guest->id)
            ->delete();

        $this->actingAs($guest)->get(route('issues.show', $issue->id))->assertNotFound();

        $response = TaskrServer::actingAs($guest)->tool(UpdateIssue::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
            'title' => 'Pirated',
        ]);

        $this->assertSame('not_found', $response->payload()['error']);
        $this->assertSame('Vanishing', $issue->fresh()->title);
    }

    // ── Invitation guest : chaîne expire → renvoi → acceptation ───────

    public function test_expired_guest_invitation_can_be_resent_and_accepted_with_scope(): void
    {
        [$owner, , $workspace, $alpha] = $this->setupWorkspaceWithMember();

        $invitation = Invitation::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'email' => 'client@example.com',
            'role' => WorkspaceRole::Guest->value,
            'token' => Str::random(64),
            'expires_at' => now()->subDay(),
        ]);
        $invitation->attachScope([$alpha->id]);

        // The expired link is unusable.
        $this->get(route('invitations.accept', $invitation->token))->assertStatus(410);

        // Resend: fresh token, same role and scope.
        ResendInvitation::run($workspace, $invitation->id);
        $invitation->refresh();
        $this->assertFalse($invitation->isExpired());
        $this->assertSame(WorkspaceRole::Guest, $invitation->role);

        // The full acceptance chain recreates the announced perimeter.
        $invitee = User::factory()->create(['email' => 'client@example.com']);
        $this->actingAs($invitee)
            ->get(route('invitations.accept', $invitation->token))
            ->assertRedirect(route('home'));

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

    public function test_guest_invitation_accepted_by_an_existing_member_keeps_role(): void
    {
        [$owner, $member, $workspace, $alpha] = $this->setupWorkspaceWithMember();

        $invitation = Invitation::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'email' => $member->email,
            'role' => WorkspaceRole::Guest->value,
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);
        $invitation->attachScope([$alpha->id]);

        $this->actingAs($member)
            ->get(route('invitations.accept', $invitation->token))
            ->assertRedirect(route('home'));

        app(WorkspaceContext::class)->set($workspace);

        // An existing membership wins: no downgrade, no duplicate, and the
        // announced scope is attached anyway (a no-op for a full member).
        $memberships = Membership::where('user_id', $member->id)
            ->where('workspace_id', $workspace->id)
            ->get();
        $this->assertCount(1, $memberships);
        $this->assertSame(WorkspaceRole::Member, $memberships->first()->role);
        $this->assertSame(
            [$alpha->id],
            ProjectMember::where('user_id', $member->id)->pluck('project_id')->all(),
        );

        // The full member keeps the unrestricted view of the workspace.
        $this->actingAs($member)->get(route('projects.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('projects.active', 2));
    }
}
