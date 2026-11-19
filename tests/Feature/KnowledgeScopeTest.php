<?php

namespace Tests\Feature;

use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Projects\ArchiveProject;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\GetKnowledge;
use App\Mcp\Tools\ListKnowledge;
use App\Models\KnowledgePage;
use App\Models\Membership;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class KnowledgeScopeTest extends TestCase
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
     * Guest scoped to one project. Pages: in-scope project page, out-of-scope
     * project page, transverse page.
     *
     * @return array{0: User, 1: Workspace, 2: KnowledgePage, 3: KnowledgePage, 4: KnowledgePage, 5: User}
     */
    private function setupScopedGuest(): array
    {
        Queue::fake();

        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $inScope = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $outOfScope = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $inScopePage = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page client', 'body' => 'Corps', 'project_id' => $inScope->id,
        ]);
        $outOfScopePage = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page interne', 'body' => 'Corps', 'project_id' => $outOfScope->id,
        ]);
        $transversePage = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page transverse', 'body' => 'Corps',
        ]);

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

        return [$owner, $workspace, $inScopePage, $outOfScopePage, $transversePage, $guest];
    }

    // ── Arborescence ─────────────────────────────────────────────────

    public function test_guest_bare_index_ships_only_perimeter_pages()
    {
        [$owner, $workspace, $inScopePage, $outOfScopePage, $transversePage, $guest] = $this->setupScopedGuest();

        // No scope chosen yet: the landing shows the recently updated
        // pages — but only the perimeter's, never out-of-scope ones.
        $this->actingAs($guest)
            ->get(route('knowledge.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('pages', 1)
                ->where('pages.0.id', $inScopePage->id)
                ->has('projects', 1));

        // An explicit in-scope filter still lists only the perimeter's pages.
        $this->actingAs($guest)
            ->get(route('knowledge.index', ['project' => $inScopePage->project_id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('pages', 1)
                ->where('pages.0.id', $inScopePage->id));
    }

    public function test_member_bare_index_ships_perimeter_and_transverse_pages(): void
    {
        [$owner, $workspace, $inScopePage, $outOfScopePage, $transversePage, $guest] = $this->setupScopedGuest();
        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        // A roster member with no project team sees the transverse pages
        // only — the landing's recent list respects that perimeter.
        $this->actingAs($member)
            ->get(route('knowledge.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('pages', 1)
                ->where('pages.0.id', $transversePage->id));
    }

    public function test_guest_sees_pages_of_archived_scope_projects(): void
    {
        [$owner, $workspace, $inScopePage, $outOfScopePage, $transversePage, $guest] = $this->setupScopedGuest();

        app(WorkspaceContext::class)->set($workspace);
        ArchiveProject::run($inScopePage->project);

        $this->actingAs($guest)
            ->get(route('knowledge.index', ['project' => $inScopePage->project_id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('pages', 1)
                ->where('pages.0.id', $inScopePage->id));
    }

    // ── Lecture ──────────────────────────────────────────────────────

    public function test_guest_cannot_open_an_out_of_scope_page(): void
    {
        [$owner, $workspace, $inScopePage, $outOfScopePage, $transversePage, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->get(route('knowledge.show', $outOfScopePage->id))
            ->assertNotFound();
    }

    public function test_guest_cannot_open_a_transverse_page(): void
    {
        [$owner, $workspace, $inScopePage, $outOfScopePage, $transversePage, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->get(route('knowledge.show', $transversePage->id))
            ->assertNotFound();
    }

    public function test_guest_can_open_an_in_scope_page(): void
    {
        [$owner, $workspace, $inScopePage, $outOfScopePage, $transversePage, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->get(route('knowledge.show', $inScopePage->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selected.id', $inScopePage->id));
    }

    // ── Création ─────────────────────────────────────────────────────

    public function test_guest_cannot_create_a_transverse_page(): void
    {
        [$owner, $workspace, $inScopePage, $outOfScopePage, $transversePage, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->post(route('knowledge.store'), [
                'title' => 'Sans projet', 'body' => 'Corps',
            ])
            ->assertSessionHasErrors('project_id');

        $this->assertDatabaseMissing('knowledge_pages', ['title' => 'Sans projet']);
    }

    public function test_guest_cannot_create_a_page_on_an_out_of_scope_project(): void
    {
        [$owner, $workspace, $inScopePage, $outOfScopePage, $transversePage, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->post(route('knowledge.store'), [
                'title' => 'Fuite', 'body' => 'Corps', 'project_id' => $outOfScopePage->project_id,
            ])
            ->assertSessionHasErrors('project_id');

        $this->assertDatabaseMissing('knowledge_pages', ['title' => 'Fuite']);
    }

    public function test_guest_can_create_a_page_in_scope(): void
    {
        [$owner, $workspace, $inScopePage, $outOfScopePage, $transversePage, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->post(route('knowledge.store'), [
                'title' => 'Nouvelle page client', 'body' => 'Corps', 'project_id' => $inScopePage->project_id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('knowledge_pages', ['title' => 'Nouvelle page client']);
    }

    public function test_guest_cannot_set_an_inaccessible_parent(): void
    {
        [$owner, $workspace, $inScopePage, $outOfScopePage, $transversePage, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->post(route('knowledge.store'), [
                'title' => 'Enfant', 'body' => 'Corps',
                'project_id' => $inScopePage->project_id,
                'parent_id' => $transversePage->id,
            ])
            ->assertSessionHasErrors('parent_id');

        $this->assertDatabaseMissing('knowledge_pages', ['title' => 'Enfant']);
    }

    // ── Édition et suppression ────────────────────────────────────────

    public function test_guest_cannot_update_an_out_of_scope_page(): void
    {
        [$owner, $workspace, $inScopePage, $outOfScopePage, $transversePage, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->patch(route('knowledge.update', $outOfScopePage->id), [
                'title' => 'Pirate', 'body' => 'Corps', 'project_id' => $outOfScopePage->project_id,
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('knowledge_pages', [
            'id' => $outOfScopePage->id,
            'title' => 'Page interne',
        ]);
    }

    public function test_guest_cannot_detach_a_page_to_transverse_or_out_of_scope(): void
    {
        [$owner, $workspace, $inScopePage, $outOfScopePage, $transversePage, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->patch(route('knowledge.update', $inScopePage->id), [
                'title' => 'Page client', 'body' => 'Corps',
            ])
            ->assertSessionHasErrors('project_id');

        $this->actingAs($guest)
            ->patch(route('knowledge.update', $inScopePage->id), [
                'title' => 'Page client', 'body' => 'Corps', 'project_id' => $outOfScopePage->project_id,
            ])
            ->assertSessionHasErrors('project_id');

        $this->assertDatabaseHas('knowledge_pages', [
            'id' => $inScopePage->id,
            'project_id' => $inScopePage->project_id,
        ]);
    }

    public function test_guest_cannot_delete_an_out_of_scope_page(): void
    {
        [$owner, $workspace, $inScopePage, $outOfScopePage, $transversePage, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->delete(route('knowledge.destroy', $outOfScopePage->id))
            ->assertNotFound();

        $this->assertDatabaseHas('knowledge_pages', ['id' => $outOfScopePage->id]);
    }

    // ── Invité projet : lecture complète, pages projet en lecture seule ─

    /**
     * A workspace member carrying the roster role Guest on the project:
     * they see the project's pages fully, but never write them.
     *
     * @return array{0: User, 1: Workspace, 2: KnowledgePage, 3: User}
     */
    private function setupProjectGuest(): array
    {
        Queue::fake();

        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page du projet', 'body' => 'Corps', 'project_id' => $project->id,
        ]);

        $projectGuest = User::factory()->create();
        Membership::create([
            'user_id' => $projectGuest->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $projectGuest->id,
            'role' => 'guest',
        ]);

        return [$owner, $workspace, $page, $projectGuest];
    }

    public function test_project_guest_reads_in_scope_project_pages(): void
    {
        [$owner, $workspace, $page, $projectGuest] = $this->setupProjectGuest();

        $this->actingAs($projectGuest)
            ->get(route('knowledge.show', $page->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $assert) => $assert
                ->where('selected.id', $page->id));
    }

    public function test_project_guest_cannot_create_a_project_page(): void
    {
        [$owner, $workspace, $page, $projectGuest] = $this->setupProjectGuest();

        $this->actingAs($projectGuest)
            ->post(route('knowledge.store'), [
                'title' => 'Page interdite', 'body' => 'Corps', 'project_id' => $page->project_id,
            ])
            ->assertSessionHasErrors('project_id');

        $this->assertDatabaseMissing('knowledge_pages', ['title' => 'Page interdite']);
    }

    public function test_project_guest_cannot_update_a_project_page(): void
    {
        [$owner, $workspace, $page, $projectGuest] = $this->setupProjectGuest();

        $this->actingAs($projectGuest)
            ->patch(route('knowledge.update', $page->id), [
                'title' => 'Pirate', 'body' => 'Corps', 'project_id' => $page->project_id,
            ])
            ->assertSessionHasErrors('project_id');

        $this->assertDatabaseHas('knowledge_pages', [
            'id' => $page->id,
            'title' => 'Page du projet',
        ]);
    }

    // ── MCP ──────────────────────────────────────────────────────────

    public function test_guest_mcp_list_knowledge_returns_only_scope_pages(): void
    {
        [$owner, $workspace, $inScopePage, $outOfScopePage, $transversePage, $guest] = $this->setupScopedGuest();

        $pages = TaskrServer::actingAs($guest)->tool(ListKnowledge::class, [
            'workspace_id' => $workspace->id,
        ])->assertOk()->payload()['pages'];

        $this->assertSame([$inScopePage->id], array_column($pages, 'id'));
    }

    public function test_guest_mcp_get_knowledge_out_of_scope_is_not_found(): void
    {
        [$owner, $workspace, $inScopePage, $outOfScopePage, $transversePage, $guest] = $this->setupScopedGuest();

        $response = TaskrServer::actingAs($guest)->tool(GetKnowledge::class, [
            'workspace_id' => $workspace->id,
            'page_id' => $outOfScopePage->id,
        ]);

        $this->assertSame('not_found', $response->payload()['error']);
    }
}
