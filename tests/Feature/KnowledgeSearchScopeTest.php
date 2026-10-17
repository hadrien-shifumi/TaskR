<?php

namespace Tests\Feature;

use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\SearchKnowledge;
use App\Models\KnowledgePage;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class KnowledgeSearchScopeTest extends TestCase
{
    use RefreshDatabase;

    /** Query vector: close to every seeded page vector. */
    private const QUERY_VECTOR = [1.0, 0.0];

    private const PAGE_VECTOR = [0.99, 0.141];

    protected function tearDown(): void
    {
        config()->set('knowledge.embeddings', [
            'base_url' => null,
            'api_key' => null,
            'model' => null,
        ]);

        parent::tearDown();
    }

    private function configureEmbeddings(): void
    {
        config()->set('knowledge.embeddings', [
            'base_url' => 'https://embeddings.test/v1',
            'api_key' => 'test-key',
            'model' => 'text-embedding-3-small',
        ]);

        Http::fake(function (Request $request) {
            return Http::response(['data' => [['embedding' => self::QUERY_VECTOR, 'index' => 0]]]);
        });
    }

    /**
     * Guest scoped to one project. Three pages, all near the query vector:
     * one on the scope project, one on another project, one transverse.
     *
     * @return array{0: User, 1: Workspace, 2: Project, 3: Project, 4: User}
     */
    private function setupScopedGuest(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $inScope = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $outOfScope = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        KnowledgePage::factory()->vectorized(self::PAGE_VECTOR)->create([
            'workspace_id' => $workspace->id,
            'project_id' => $inScope->id,
            'title' => 'Page client',
            'body' => 'Contenu du perimetre.',
        ]);
        KnowledgePage::factory()->vectorized(self::PAGE_VECTOR)->create([
            'workspace_id' => $workspace->id,
            'project_id' => $outOfScope->id,
            'title' => 'Page interne',
            'body' => 'Contenu interne.',
        ]);
        KnowledgePage::factory()->vectorized(self::PAGE_VECTOR)->create([
            'workspace_id' => $workspace->id,
            'project_id' => null,
            'title' => 'Page transverse',
            'body' => 'Contenu transverse.',
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

        return [$owner, $workspace, $inScope, $outOfScope, $guest];
    }

    public function test_guest_search_returns_only_scope_pages(): void
    {
        $this->configureEmbeddings();
        [$owner, $workspace, $inScope, $outOfScope, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->get(route('knowledge.search', ['q' => 'contenu']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('results', 1)
                ->where('results.0.title', 'Page client'));
    }

    public function test_member_search_sees_every_page(): void
    {
        $this->configureEmbeddings();
        [$owner, $workspace, $inScope, $outOfScope, $guest] = $this->setupScopedGuest();
        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $results = app(\App\Actions\Knowledge\SearchKnowledge::class)->handle(
            $workspace,
            'contenu',
            null,
            10,
            $member
        );

        $this->assertSame(3, count($results));
    }

    public function test_guest_search_cannot_target_an_out_of_scope_project(): void
    {
        $this->configureEmbeddings();
        [$owner, $workspace, $inScope, $outOfScope, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->get(route('knowledge.search', ['q' => 'contenu', 'project_id' => $outOfScope->id]))
            ->assertSessionHasErrors('project_id');
    }

    public function test_guest_mcp_search_returns_only_scope_pages(): void
    {
        $this->configureEmbeddings();

        TestResponse::macro('payload', function (): array {
            /** @var TestResponse $this */
            return $this->response->toArray()['result']['structuredContent'] ?? [];
        });

        [$owner, $workspace, $inScope, $outOfScope, $guest] = $this->setupScopedGuest();

        $results = TaskrServer::actingAs($guest)->tool(SearchKnowledge::class, [
            'workspace_id' => $workspace->id,
            'query' => 'contenu',
        ])->assertOk()->payload()['results'];

        $this->assertSame(['Page client'], array_column($results, 'title'));
    }
}
