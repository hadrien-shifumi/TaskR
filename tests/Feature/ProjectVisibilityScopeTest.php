<?php

namespace Tests\Feature;

use App\Actions\Projects\ArchiveProject;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\GetProjectHealth;
use App\Mcp\Tools\ListProjects;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class ProjectVisibilityScopeTest extends TestCase
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
     * @return array{0: User, 1: Workspace, 2: Project, 3: Project, 4: User}
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

        return [$owner, $workspace, $inScope, $outOfScope, $guest];
    }

    // ── Page Projects : liste filtrée par périmètre ───────────────────

    public function test_guest_projects_page_lists_only_scope_projects(): void
    {
        [$owner, $workspace, $inScope, $outOfScope, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('projects.active', 1)
                ->where('projects.active.0.id', $inScope->id)
                ->has('projects.archived', 0));
    }

    public function test_member_projects_page_lists_all_projects(): void
    {
        [$owner, $workspace, $inScope, $outOfScope, $guest] = $this->setupScopedGuest();
        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $this->actingAs($member)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('projects.active', 2));
    }

    public function test_guest_still_sees_archived_projects_of_their_scope(): void
    {
        [$owner, $workspace, $inScope, $outOfScope, $guest] = $this->setupScopedGuest();

        app(WorkspaceContext::class)->set($workspace);
        $archivedInScope = CreateProject::run($workspace, $owner, ['name' => 'Gamma']);
        ArchiveProject::run($archivedInScope);
        ProjectMember::create([
            'project_id' => $archivedInScope->id,
            'user_id' => $guest->id,
            'role' => 'member',
        ]);

        $archivedOut = CreateProject::run($workspace, $owner, ['name' => 'Delta']);
        ArchiveProject::run($archivedOut);

        $this->actingAs($guest)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('projects.active', 1)
                ->where('projects.active.0.id', $inScope->id)
                ->has('projects.archived', 1)
                ->where('projects.archived.0.id', $archivedInScope->id));
    }

    // ── Outil MCP list_projects : filtré par périmètre ────────────────

    public function test_guest_mcp_list_projects_returns_only_scope_projects(): void
    {
        [$owner, $workspace, $inScope, $outOfScope, $guest] = $this->setupScopedGuest();

        $listed = TaskrServer::actingAs($guest)->tool(ListProjects::class, [
            'workspace_id' => $workspace->id,
        ])->assertOk()->payload()['projects'];

        $names = array_column($listed, 'name');
        $this->assertSame(['Alpha'], $names);
    }

    // ── Gardes 404 sur les surfaces projet ────────────────────────────

    public function test_guest_cannot_open_an_out_of_scope_project_board(): void
    {
        [$owner, $workspace, $inScope, $outOfScope, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->get(route('projects.board', $outOfScope->id))
            ->assertNotFound();
    }

    public function test_guest_can_open_an_in_scope_project_board(): void
    {
        [$owner, $workspace, $inScope, $outOfScope, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->get(route('projects.board', $inScope->id))
            ->assertOk();
    }

    public function test_guest_cannot_open_out_of_scope_project_members(): void
    {
        [$owner, $workspace, $inScope, $outOfScope, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->get(route('projects.members.index', $outOfScope->id))
            ->assertNotFound();
    }

    public function test_guest_cannot_open_out_of_scope_project_health(): void
    {
        [$owner, $workspace, $inScope, $outOfScope, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->get(route('projects.health', $outOfScope->id))
            ->assertNotFound();
    }

    public function test_guest_mcp_get_project_health_out_of_scope_is_not_found(): void
    {
        [$owner, $workspace, $inScope, $outOfScope, $guest] = $this->setupScopedGuest();

        $response = TaskrServer::actingAs($guest)->tool(GetProjectHealth::class, [
            'workspace_id' => $workspace->id,
            'project_id' => $outOfScope->id,
        ]);

        $this->assertSame('not_found', $response->payload()['error']);
    }
}
