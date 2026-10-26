<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Mcp\Resources\ProjectIssues;
use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\ArchiveProject as ArchiveProjectTool;
use App\Mcp\Tools\CreateLabel;
use App\Mcp\Tools\CreateProject as CreateProjectTool;
use App\Mcp\Tools\DeleteProject as DeleteProjectTool;
use App\Mcp\Tools\GetIssue;
use App\Mcp\Tools\GetProjectHealth;
use App\Mcp\Tools\InviteMember;
use App\Mcp\Tools\ListLabels;
use App\Mcp\Tools\ListMembers;
use App\Mcp\Tools\ListStatuses;
use App\Mcp\Tools\ListTemplates;
use App\Mcp\Tools\UpdateProject as UpdateProjectTool;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Testing\TestResponse;
use ReflectionClass;
use Tests\TestCase;

class McpGuestScopeTest extends TestCase
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
     * @return array{0: User, 1: Workspace, 2: Project, 3: Project, 4: User, 5: array<string, Status>}
     */
    private function setupScopedGuest(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $inScope = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $outOfScope = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);
        ProjectMember::create([
            'project_id' => $inScope->id,
            'user_id' => $guest->id,
            'role' => 'member',
        ]);

        $statuses = [];
        foreach ($workspace->statuses as $status) {
            $statuses[$status->category->value] = $status;
        }

        return [$owner, $workspace, $inScope, $outOfScope, $guest, $statuses];
    }

    // ── Resource ProjectIssues : scopée au périmètre ──────────────────

    public function test_guest_mcp_resource_project_issues_out_of_scope_is_not_found(): void
    {
        [$owner, $workspace, $inScope, $outOfScope, $guest] = $this->setupScopedGuest();
        app(WorkspaceContext::class)->set($workspace);
        CreateIssue::run($workspace, ['project_id' => $outOfScope->id, 'title' => 'Secret']);

        TaskrServer::actingAs($guest)
            ->resource(ProjectIssues::class, ['workspace_id' => $workspace->id, 'project_id' => $outOfScope->id])
            ->assertHasErrors(['The requested resource was not found in this workspace.']);
    }

    public function test_guest_mcp_resource_project_issues_lists_in_scope_project_issues(): void
    {
        [$owner, $workspace, $inScope, $outOfScope, $guest] = $this->setupScopedGuest();
        app(WorkspaceContext::class)->set($workspace);
        CreateIssue::run($workspace, ['project_id' => $inScope->id, 'title' => 'Visible']);

        $result = TaskrServer::actingAs($guest)
            ->resource(ProjectIssues::class, ['workspace_id' => $workspace->id, 'project_id' => $inScope->id])
            ->assertOk()
            ->result();

        /** @var array{issues: array<int, array{title: string}>} */
        $payload = json_decode($result['contents'][0]['text'], true);

        $this->assertSame(['Visible'], array_column($payload['issues'], 'title'));
    }

    // ── Écritures projet : périmètre appliqué (MCP + web) ─────────────

    public function test_guest_mcp_update_out_of_scope_project_is_not_found(): void
    {
        [$owner, $workspace, $inScope, $outOfScope, $guest] = $this->setupScopedGuest();

        $response = TaskrServer::actingAs($guest)->tool(UpdateProjectTool::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $outOfScope->id,
            'name' => 'Pirated',
        ]);

        $this->assertSame('not_found', $response->payload()['error']);
        $this->assertSame('Beta', $outOfScope->fresh()->name);
    }

    public function test_guest_mcp_archive_out_of_scope_project_is_not_found(): void
    {
        [$owner, $workspace, $inScope, $outOfScope, $guest] = $this->setupScopedGuest();

        $response = TaskrServer::actingAs($guest)->tool(ArchiveProjectTool::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $outOfScope->id,
        ]);

        $this->assertSame('not_found', $response->payload()['error']);
        $this->assertNull($outOfScope->fresh()->archived_at);
    }

    public function test_guest_mcp_delete_out_of_scope_project_is_not_found(): void
    {
        [$owner, $workspace, $inScope, $outOfScope, $guest] = $this->setupScopedGuest();

        $response = TaskrServer::actingAs($guest)->tool(DeleteProjectTool::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $outOfScope->id,
        ]);

        $this->assertSame('not_found', $response->payload()['error']);
        $this->assertNotNull($outOfScope->fresh());
    }

    public function test_guest_web_update_out_of_scope_project_is_not_found(): void
    {
        [$owner, $workspace, $inScope, $outOfScope, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->put(route('projects.update', $outOfScope), ['name' => 'Pirated'])
            ->assertNotFound();

        $this->assertSame('Beta', $outOfScope->fresh()->name);
    }

    public function test_guest_can_update_and_archive_in_scope_projects(): void
    {
        [$owner, $workspace, $inScope, $outOfScope, $guest] = $this->setupScopedGuest();

        $updated = TaskrServer::actingAs($guest)->tool(UpdateProjectTool::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $inScope->id,
            'name' => 'Alpha renommé',
        ])->assertOk()->payload();

        $this->assertSame('Alpha renommé', $updated['project']['name']);

        TaskrServer::actingAs($guest)->tool(ArchiveProjectTool::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $inScope->id,
        ])->assertOk();

        $this->assertNotNull($inScope->fresh()->archived_at);
    }

    // ── Surfaces full-members uniquement ─────────────────────────────

    public function test_guest_mcp_create_label_is_forbidden(): void
    {
        [$owner, $workspace, , , $guest] = $this->setupScopedGuest();

        $response = TaskrServer::actingAs($guest)->tool(CreateLabel::class, [
            'workspace_id' => $workspace->id,
            'name' => 'Espion',
        ]);

        $this->assertSame('forbidden', $response->payload()['error']);
        $this->assertDatabaseMissing('labels', ['name' => 'Espion']);
    }

    public function test_guest_mcp_list_members_is_forbidden(): void
    {
        [$owner, $workspace, , , $guest] = $this->setupScopedGuest();

        $response = TaskrServer::actingAs($guest)->tool(ListMembers::class, [
            'workspace_id' => $workspace->id,
        ]);

        $this->assertSame('forbidden', $response->payload()['error']);
    }

    public function test_guest_cannot_create_a_project(): void
    {
        [$owner, $workspace, , , $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->post(route('projects.store'), ['name' => 'Guest project'])
            ->assertForbidden();

        $response = TaskrServer::actingAs($guest)->tool(CreateProjectTool::class, [
            'workspace_id' => $workspace->id,
            'name' => 'Guest project',
        ]);

        $this->assertSame('forbidden', $response->payload()['error']);
        $this->assertDatabaseMissing('projects', ['name' => 'Guest project']);
    }

    // ── GetProjectHealth : vue contributeur pour un guest ─────────────

    public function test_guest_mcp_get_project_health_in_scope_returns_member_view(): void
    {
        [$owner, $workspace, $inScope, , $guest, $statuses] = $this->setupScopedGuest();
        app(WorkspaceContext::class)->set($workspace);
        $issue = CreateIssue::run($workspace, [
            'project_id' => $inScope->id,
            'title' => 'Mine',
            'status_id' => $statuses['in_progress']->id,
        ]);
        $issue->assignees()->attach($guest);

        $payload = TaskrServer::actingAs($guest)->tool(GetProjectHealth::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $inScope->id,
        ])->assertOk()->payload();

        // A guest never gets the global KPIs: contributor view only.
        $this->assertNull($payload['health']);
        $this->assertFalse($payload['canSeeGlobalKpis']);
        $this->assertSame($guest->id, $payload['myWorkload']['userId']);
        $this->assertSame(1, $payload['myWorkload']['openIssues']);
    }

    // ── Invitations guest via MCP : parité et garde croisée ───────────

    public function test_guest_mcp_invite_guest_without_projects_is_refused(): void
    {
        [$owner, $workspace, $inScope, , $guest] = $this->setupScopedGuest();

        // Guests can never invite at all (owner-only), so the cross-field
        // guard is proven through the owner-facing parity below plus the
        // object-level guard — here an owner invite without scope fails.
        $response = TaskrServer::actingAs($owner)->tool(InviteMember::class, [
            'workspace_id' => $workspace->id,
            'email' => 'client@example.com',
            'role' => 'guest',
        ]);

        $this->assertSame('validation_failed', $response->payload()['error']);
        $this->assertDatabaseMissing('invitations', ['email' => 'client@example.com']);
    }

    public function test_owner_mcp_can_invite_a_guest_with_a_project_scope(): void
    {
        [$owner, $workspace, $inScope, , $guest] = $this->setupScopedGuest();

        $response = TaskrServer::actingAs($owner)->tool(InviteMember::class, [
            'workspace_id' => $workspace->id,
            'email' => 'client@example.com',
            'role' => 'guest',
            'project_ids' => [$inScope->id],
        ])->assertOk()->payload();

        $invitation = Invitation::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('email', 'client@example.com')
            ->firstOrFail();

        $this->assertSame('guest', $invitation->role->value);
        $this->assertSame($response['invitation']['id'], $invitation->id);
    }

    // ── Lectures de référence : nécessaires au travail dans le périmètre

    public function test_guest_mcp_reference_reads_stay_available(): void
    {
        [$owner, $workspace, , , $guest] = $this->setupScopedGuest();

        TaskrServer::actingAs($guest)->tool(ListStatuses::class, [
            'workspace_id' => $workspace->id,
        ])->assertOk();

        TaskrServer::actingAs($guest)->tool(ListLabels::class, [
            'workspace_id' => $workspace->id,
        ])->assertOk();

        TaskrServer::actingAs($guest)->tool(ListTemplates::class, [
            'workspace_id' => $workspace->id,
        ])->assertOk();
    }

    // ── GetIssue : périmètre guest et refus sans fuite ────────────────

    public function test_guest_mcp_get_issue_in_scope_returns_full_body(): void
    {
        [$owner, $workspace, $inScope, , $guest] = $this->setupScopedGuest();
        app(WorkspaceContext::class)->set($workspace);
        $issue = CreateIssue::run($workspace, [
            'project_id' => $inScope->id,
            'title' => 'Visible spec',
            'description' => 'The specification body an agent needs.',
        ], $owner);

        $result = TaskrServer::actingAs($guest)->tool(GetIssue::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
        ])->assertOk()->payload();

        $this->assertSame($issue->id, $result['issue']['id']);
        $this->assertSame('The specification body an agent needs.', $result['issue']['description']);
        $this->assertSame('Visible spec', $result['issue']['title']);
    }

    public function test_guest_mcp_get_issue_out_of_scope_is_not_found(): void
    {
        [$owner, $workspace, , $outOfScope, $guest] = $this->setupScopedGuest();
        app(WorkspaceContext::class)->set($workspace);
        $issue = CreateIssue::run($workspace, [
            'project_id' => $outOfScope->id,
            'title' => 'Secret spec',
            'description' => 'Classified specification body.',
        ], $owner);

        $response = TaskrServer::actingAs($guest)->tool(GetIssue::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
        ]);

        $this->assertSame('not_found', $response->payload()['error']);
        $response->assertDontSee('Classified specification body.');
    }

    public function test_guest_mcp_get_issue_refused_for_non_member_without_leak(): void
    {
        [$owner, $workspace] = $this->setupScopedGuest();

        $intruder = User::factory()->create();
        CreateWorkspace::run($intruder, ['name' => 'Theirs']);

        TaskrServer::actingAs($intruder)
            ->tool(GetIssue::class, ['workspace_id' => $workspace->id, 'issue_id' => (string) str()->ulid()])
            ->assertHasErrors(['You are not a member of this workspace.']);

        // A non-existent workspace yields the same refusal: no existence leak.
        TaskrServer::actingAs($intruder)
            ->tool(GetIssue::class, ['workspace_id' => (string) str()->ulid(), 'issue_id' => (string) str()->ulid()])
            ->assertHasErrors(['You are not a member of this workspace.']);
    }

    public function test_get_issue_description_states_the_guest_perimeter_rule(): void
    {
        $attributes = (new ReflectionClass(GetIssue::class))->getAttributes(Description::class);

        $this->assertCount(1, $attributes);
        $this->assertStringContainsStringIgnoringCase('guest', $attributes[0]->newInstance()->value);
        $this->assertStringContainsStringIgnoringCase('perimeter', $attributes[0]->newInstance()->value);
    }
}
