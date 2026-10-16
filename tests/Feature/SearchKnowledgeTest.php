<?php

namespace Tests\Feature;

use App\Actions\Knowledge\SearchKnowledge;
use App\Actions\Knowledge\UpdateKnowledgePage;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\KnowledgePage;
use App\Models\User;
use App\Support\EmbeddingsNotConfigured;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SearchKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Query vector ~ "comment se passe la facturation d'un client":
     * close to the billing page vector, far from the style guide vector.
     */
    private const QUERY_VECTOR = [1.0, 0.0];

    private const BILLING_VECTOR = [0.99, 0.141];

    private const STYLE_VECTOR = [0.0, 1.0];

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

    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace];
    }

    public function test_semantic_match_without_shared_keywords(): void
    {
        $this->configureEmbeddings();
        [$owner, $workspace] = $this->setupWorkspace();

        KnowledgePage::factory()->vectorized(self::BILLING_VECTOR)->create([
            'workspace_id' => $workspace->id,
            'title' => 'Processus de règlement et invoices',
            'body' => 'Le cycle de règlement des factures clients.',
        ]);
        KnowledgePage::factory()->vectorized(self::STYLE_VECTOR)->create([
            'workspace_id' => $workspace->id,
            'title' => 'Guide de style des composants',
            'body' => 'Boutons, couleurs et espacements.',
        ]);

        $results = SearchKnowledge::run($workspace, 'comment se passe la facturation d\'un client');

        $this->assertNotEmpty($results);
        $this->assertSame('Processus de règlement et invoices', $results[0]['title']);
        $this->assertGreaterThan(0.98, $results[0]['score']);
        $this->assertSame('Guide de style des composants', $results[1]['title']);
        $this->assertLessThan(0.05, $results[1]['score']);
    }

    public function test_results_are_scoped_to_current_workspace(): void
    {
        $this->configureEmbeddings();
        [$owner, $workspace] = $this->setupWorkspace();

        $stranger = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($stranger, ['name' => 'Other']);

        KnowledgePage::factory()->vectorized(self::BILLING_VECTOR)->create([
            'workspace_id' => $otherWorkspace->id,
            'title' => 'Their billing page',
            'body' => 'Not visible from our workspace.',
        ]);
        KnowledgePage::factory()->vectorized(self::STYLE_VECTOR)->create([
            'workspace_id' => $workspace->id,
            'title' => 'Notre page',
            'body' => 'Seule page du workspace courant.',
        ]);

        $results = SearchKnowledge::run($workspace, 'facturation');

        $this->assertCount(1, $results);
        $this->assertSame('Notre page', $results[0]['title']);
    }

    public function test_project_filter_restricts_results(): void
    {
        $this->configureEmbeddings();
        [$owner, $workspace] = $this->setupWorkspace();

        $billing = CreateProject::run($workspace, $owner, ['name' => 'Facturation']);
        $other = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        KnowledgePage::factory()->vectorized(self::BILLING_VECTOR)->create([
            'workspace_id' => $workspace->id,
            'project_id' => $billing->id,
            'title' => 'Règlement',
            'body' => 'Factures.',
        ]);
        KnowledgePage::factory()->vectorized(self::BILLING_VECTOR)->create([
            'workspace_id' => $workspace->id,
            'project_id' => $other->id,
            'title' => 'Hors périmètre',
            'body' => 'Autre projet.',
        ]);

        $results = SearchKnowledge::run($workspace, 'facturation', $billing->id);

        $this->assertCount(1, $results);
        $this->assertSame('Règlement', $results[0]['title']);
    }

    public function test_results_are_ranked_with_excerpt_and_score(): void
    {
        $this->configureEmbeddings();
        [$owner, $workspace] = $this->setupWorkspace();

        $longBody = str_repeat('Le règlement des invoices clients passe par un cycle de validation complet. ', 20);

        KnowledgePage::factory()->vectorized(self::BILLING_VECTOR)->create([
            'workspace_id' => $workspace->id,
            'title' => 'Long page',
            'body' => $longBody,
        ]);

        $results = SearchKnowledge::run($workspace, 'facturation', null, 5);

        $this->assertCount(1, $results);
        $this->assertSame($workspace->id, $workspace->id);
        $this->assertIsFloat($results[0]['score']);
        $this->assertLessThanOrEqual(282, mb_strlen($results[0]['excerpt']));
        $this->assertStringEndsWith('…', $results[0]['excerpt']);
        $this->assertStringNotContainsString("\n", $results[0]['excerpt']);
    }

    public function test_non_vectorized_pages_are_excluded_but_navigable(): void
    {
        $this->configureEmbeddings();
        [$owner, $workspace] = $this->setupWorkspace();

        $pending = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'En attente de vectorisation',
            'body' => 'Freshly edited, embedding pending.',
        ]);
        KnowledgePage::factory()->vectorized(self::BILLING_VECTOR)->create([
            'workspace_id' => $workspace->id,
            'title' => 'Indexée',
            'body' => 'Already embedded.',
        ]);

        $results = SearchKnowledge::run($workspace, 'anything');

        $this->assertCount(1, $results);
        $this->assertSame('Indexée', $results[0]['title']);

        // Navigation ignores the vectorization state.
        $this->actingAs($owner)
            ->get("/knowledge/{$pending->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Knowledge/Index')
                ->where('selected.title', 'En attente de vectorisation'));
    }

    public function test_edited_page_reenters_search_with_new_content(): void
    {
        $this->configureEmbeddings();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = KnowledgePage::factory()->vectorized(self::STYLE_VECTOR)->create([
            'workspace_id' => $workspace->id,
            'title' => 'Guide de style',
            'body' => 'Couleurs et espacements.',
        ]);

        // Not a match before the edit.
        $before = SearchKnowledge::run($workspace, 'facturation');
        $this->assertLessThan(0.05, $before[0]['score']);

        UpdateKnowledgePage::run($page, $owner, [
            'title' => 'Guide de style',
            'body' => 'Le règlement des invoices clients.',
        ]);

        // The sync-queue job re-embedded with the fake query vector: the
        // edited content now leads the search results.
        $after = SearchKnowledge::run($workspace, 'facturation');

        $this->assertSame($page->id, $after[0]['id']);
        $this->assertGreaterThan(0.99, $after[0]['score']);
    }

    public function test_unconfigured_embeddings_raise_explicit_error(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        config()->set('knowledge.embeddings', [
            'base_url' => null,
            'api_key' => null,
            'model' => null,
        ]);

        $this->expectException(EmbeddingsNotConfigured::class);

        SearchKnowledge::run($workspace, 'facturation');
    }

    public function test_unconfigured_embeddings_surface_a_state_message_in_the_ui(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        config()->set('knowledge.embeddings', [
            'base_url' => null,
            'api_key' => null,
            'model' => null,
        ]);

        $this->actingAs($owner)
            ->get('/knowledge/search?q=facturation')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Knowledge/Index')
                ->where('searchUnavailable', true)
                ->where('query', 'facturation'));
    }
}
