<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspace;
use App\Jobs\VectorizeKnowledgePage;
use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\CreateKnowledge;
use App\Mcp\Tools\DeleteKnowledge;
use App\Mcp\Tools\GetKnowledge;
use App\Mcp\Tools\ListKnowledge;
use App\Mcp\Tools\SearchKnowledge;
use App\Mcp\Tools\UpdateKnowledge;
use App\Models\KnowledgePage;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class McpKnowledgeToolsTest extends TestCase
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

    private function configureEmbeddings(): void
    {
        config()->set('knowledge.embeddings', [
            'base_url' => 'https://embeddings.test/v1',
            'api_key' => 'test-key',
            'model' => 'text-embedding-3-small',
        ]);

        Http::fake([
            'embeddings.test/v1/embeddings' => Http::response(['data' => [['embedding' => [1.0, 0.0]]]]),
        ]);
    }

    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        return [$owner, $workspace];
    }

    public function test_tools_catalog_exposes_all_knowledge_tools(): void
    {
        TaskrServer::tools()->assertRegistered([
            ListKnowledge::class,
            GetKnowledge::class,
            SearchKnowledge::class,
            CreateKnowledge::class,
            UpdateKnowledge::class,
            DeleteKnowledge::class,
        ]);

        $defaults = (new \ReflectionClass(TaskrServer::class))->getDefaultProperties();
        /** @var array<int, class-string> $registered */
        $registered = $defaults['tools'];

        $knowledgeTools = array_filter(
            $registered,
            fn (string $tool): bool => str_contains($tool, 'Knowledge'),
        );

        // 6 base tools + 3 trash tools (ListKnowledgeTrash, RestoreKnowledge, ForceDeleteKnowledge)
        // + 3 versioning tools (ListKnowledgeVersions, RestoreKnowledgeVersion, CompareKnowledgeVersions).
        $this->assertCount(12, $knowledgeTools);
    }

    public function test_list_then_get_knowledge_immersion_flow(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();
        app(WorkspaceContext::class)->set($workspace);

        $root = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Environnements de staging',
            'body' => 'Le staging est redéployé chaque nuit.',
        ]);
        $child = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Runbooks',
            'body' => 'Procédures de déploiement.',
            'parent_id' => $root->id,
        ]);

        $list = TaskrServer::actingAs($owner)
            ->tool(ListKnowledge::class, ['workspace_id' => $workspace->id])
            ->assertOk()->payload();

        $this->assertCount(2, $list['pages']);
        $this->assertSame('Environnements de staging', $list['pages'][0]['title']);
        $this->assertSame($root->id, $list['pages'][0]['id']);
        $this->assertSame($root->id, $list['pages'][1]['parentId']);

        $get = TaskrServer::actingAs($owner)
            ->tool(GetKnowledge::class, [
                'workspace_id' => $workspace->id,
                'page_id' => $root->id,
            ])
            ->assertOk()->payload();

        $this->assertSame('Le staging est redéployé chaque nuit.', $get['page']['body']);
        $this->assertSame('Environnements de staging', $get['page']['title']);
    }

    public function test_get_knowledge_foreign_page_is_not_found_without_leak(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();
        app(WorkspaceContext::class)->set($workspace);

        $stranger = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($stranger, ['name' => 'Other']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $foreignPage = KnowledgePage::factory()->create([
            'workspace_id' => $otherWorkspace->id,
            'title' => 'Their secret page',
            'body' => 'Confidential.',
        ]);
        app(WorkspaceContext::class)->set($workspace);

        $result = TaskrServer::actingAs($owner)
            ->tool(GetKnowledge::class, [
                'workspace_id' => $workspace->id,
                'page_id' => $foreignPage->id,
            ])
            ->assertHasErrors()
            ->payload();

        $this->assertSame('not_found', $result['error']);
    }

    public function test_search_knowledge_returns_ranked_results(): void
    {
        $this->configureEmbeddings();
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();
        app(WorkspaceContext::class)->set($workspace);

        KnowledgePage::factory()->vectorized([1.0, 0.0])->create([
            'workspace_id' => $workspace->id,
            'title' => 'Processus de règlement et invoices',
            'body' => 'Le cycle de règlement des factures clients.',
        ]);
        KnowledgePage::factory()->vectorized([0.0, 1.0])->create([
            'workspace_id' => $workspace->id,
            'title' => 'Guide de style',
            'body' => 'Couleurs et espacements.',
        ]);

        $result = TaskrServer::actingAs($owner)
            ->tool(SearchKnowledge::class, [
                'workspace_id' => $workspace->id,
                'query' => 'comment se passe la facturation d\'un client',
            ])
            ->assertOk()->payload();

        $this->assertSame('Processus de règlement et invoices', $result['results'][0]['title']);
        $this->assertGreaterThan(0.9, $result['results'][0]['score']);
        $this->assertArrayHasKey('excerpt', $result['results'][0]);
    }

    public function test_search_knowledge_without_config_returns_distinct_error(): void
    {
        config()->set('knowledge.embeddings', [
            'base_url' => null,
            'api_key' => null,
            'model' => null,
        ]);

        [$owner, $workspace] = $this->setupWorkspace();
        app(WorkspaceContext::class)->set($workspace);

        $result = TaskrServer::actingAs($owner)
            ->tool(SearchKnowledge::class, [
                'workspace_id' => $workspace->id,
                'query' => 'anything',
            ])
            ->assertHasErrors(['Semantic search is not configured'])
            ->payload();

        $this->assertSame('embeddings_not_configured', $result['error']);
        $this->assertArrayNotHasKey('fields', $result);
    }

    public function test_create_knowledge_delegates_to_action(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $result = TaskrServer::actingAs($owner)
            ->tool(CreateKnowledge::class, [
                'workspace_id' => $workspace->id,
                'title' => 'Conventions de nommage',
                'body' => 'Les branches suivent la convention feat/…',
            ])
            ->assertOk()->payload();

        $pageId = $result['page']['id'];

        $this->assertDatabaseHas('knowledge_pages', [
            'id' => $pageId,
            'workspace_id' => $workspace->id,
            'title' => 'Conventions de nommage',
            'last_editor_id' => $owner->id,
        ]);

        Queue::assertPushed(VectorizeKnowledgePage::class);
    }

    public function test_update_knowledge_enforces_cycle_rule_and_revectorizes(): void
    {
        $this->configureEmbeddings();
        [$owner, $workspace] = $this->setupWorkspace();
        app(WorkspaceContext::class)->set($workspace);

        $parent = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Parent',
            'body' => 'Parent body.',
        ]);
        $child = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Child',
            'body' => 'Child body.',
            'parent_id' => $parent->id,
        ]);

        // Cycle: parent under its own child — same business error as the UI.
        TaskrServer::actingAs($owner)
            ->tool(UpdateKnowledge::class, [
                'workspace_id' => $workspace->id,
                'page_id' => $parent->id,
                'title' => 'Parent',
                'body' => 'Parent body.',
                'parent_id' => $child->id,
            ])
            ->assertHasErrors(['cycle']);

        $this->assertDatabaseHas('knowledge_pages', ['id' => $parent->id, 'parent_id' => null]);

        // A legitimate update goes through and re-vectorizes.
        $result = TaskrServer::actingAs($owner)
            ->tool(UpdateKnowledge::class, [
                'workspace_id' => $workspace->id,
                'page_id' => $child->id,
                'title' => 'Child',
                'body' => 'Updated body.',
            ])
            ->assertOk()->payload();

        $this->assertSame('Updated body.', $result['page']['body']);
        $this->assertDatabaseHas('knowledge_pages', [
            'id' => $child->id,
            'body' => 'Updated body.',
            'last_editor_id' => $owner->id,
        ]);

        $childModel = KnowledgePage::withoutWorkspaceScope()->findOrFail($child->id);
        $this->assertNotNull($childModel->embedded_at);
        $this->assertEquals([1.0, 0.0], array_map(floatval(...), $childModel->embedding));
    }
}
