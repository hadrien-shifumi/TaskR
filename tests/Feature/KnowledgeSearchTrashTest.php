<?php

namespace Tests\Feature;

use App\Actions\Knowledge\ForceDeleteKnowledgePage;
use App\Actions\Knowledge\RestoreKnowledgePage;
use App\Actions\Knowledge\SearchKnowledge;
use App\Actions\Workspaces\CreateWorkspace;
use App\Jobs\VectorizeKnowledgePage;
use App\Models\KnowledgePage;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class KnowledgeSearchTrashTest extends TestCase
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
     * @return array{0: User, 1: Workspace}
     */
    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace];
    }

    public function test_trashed_pages_are_excluded_from_semantic_search(): void
    {
        $this->configureEmbeddings();
        [$owner, $workspace] = $this->setupWorkspace();

        $active = KnowledgePage::factory()->vectorized(self::PAGE_VECTOR)->create([
            'workspace_id' => $workspace->id,
            'title' => 'Page active',
            'body' => 'Contenu actif et indexé.',
        ]);
        $trashed = KnowledgePage::factory()->vectorized(self::PAGE_VECTOR)->create([
            'workspace_id' => $workspace->id,
            'title' => 'Page en corbeille',
            'body' => 'Contenu supprimé mais encore indexé.',
        ]);

        // Soft-delete the second page — its embedding remains in the DB.
        $trashed->delete();

        $results = SearchKnowledge::run($workspace, 'contenu');

        $ids = array_column($results, 'id');
        $this->assertContains($active->id, $ids);
        $this->assertNotContains($trashed->id, $ids);
    }

    public function test_multiple_trashed_pages_are_all_excluded_from_search(): void
    {
        $this->configureEmbeddings();
        [$owner, $workspace] = $this->setupWorkspace();

        $active = KnowledgePage::factory()->vectorized(self::PAGE_VECTOR)->create([
            'workspace_id' => $workspace->id,
            'title' => 'Page restée active',
            'body' => 'Seule page non supprimée.',
        ]);
        $trashed1 = KnowledgePage::factory()->vectorized(self::PAGE_VECTOR)->create([
            'workspace_id' => $workspace->id,
            'title' => 'Corbeille 1',
            'body' => 'Première page supprimée.',
        ]);
        $trashed2 = KnowledgePage::factory()->vectorized(self::PAGE_VECTOR)->create([
            'workspace_id' => $workspace->id,
            'title' => 'Corbeille 2',
            'body' => 'Deuxième page supprimée.',
        ]);

        $trashed1->delete();
        $trashed2->delete();

        $results = SearchKnowledge::run($workspace, 'page');

        $this->assertCount(1, $results);
        $this->assertSame($active->id, $results[0]['id']);
    }

    public function test_restore_dispatches_revectorization_job(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = KnowledgePage::factory()->vectorized(self::PAGE_VECTOR)->create([
            'workspace_id' => $workspace->id,
            'title' => 'Page à restaurer',
            'body' => 'Corps.',
        ]);

        $page->delete();

        // Reset the fake to clear any previously recorded jobs.
        Queue::fake();

        $trashedPage = KnowledgePage::withTrashed()->findOrFail($page->id);
        RestoreKnowledgePage::run($trashedPage, $owner);

        Queue::assertPushed(VectorizeKnowledgePage::class, function (VectorizeKnowledgePage $job) use ($page) {
            return $job->page->id === $page->id;
        });
    }

    public function test_restored_page_reappears_in_search_after_revectorization(): void
    {
        // No Queue::fake() — the sync queue runs VectorizeKnowledgePage
        // immediately, embedding the restored page.
        $this->configureEmbeddings();
        [$owner, $workspace] = $this->setupWorkspace();

        // Create a page WITHOUT an embedding — it would never appear in
        // search results because SearchKnowledge filters whereNotNull('embedding').
        $page = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Page restaurée',
            'body' => 'Contenu de la page restaurée.',
        ]);

        // Sanity check: not vectorized → not in search.
        $resultsUnvectorized = SearchKnowledge::run($workspace, 'contenu');
        $this->assertNotContains($page->id, array_column($resultsUnvectorized, 'id'));

        // Trash: still excluded.
        $page->delete();

        $resultsTrashed = SearchKnowledge::run($workspace, 'contenu');
        $this->assertNotContains($page->id, array_column($resultsTrashed, 'id'));

        // Restore: triggers VectorizeKnowledgePage synchronously. The job
        // calls the faked embeddings endpoint and stores the vector.
        $trashedPage = KnowledgePage::withTrashed()->findOrFail($page->id);
        RestoreKnowledgePage::run($trashedPage, $owner);

        // After re-vectorization, the page has an embedding and appears in search.
        $resultsAfter = SearchKnowledge::run($workspace, 'contenu');
        $this->assertContains($page->id, array_column($resultsAfter, 'id'));
    }

    public function test_search_source_never_uses_with_trashed(): void
    {
        $file = file_get_contents(app_path('Actions/Knowledge/SearchKnowledge.php'));

        $this->assertStringNotContainsString('withTrashed', $file);
        $this->assertStringNotContainsString('onlyTrashed', $file);
    }

    public function test_force_deleted_page_never_appears_in_search(): void
    {
        $this->configureEmbeddings();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = KnowledgePage::factory()->vectorized(self::PAGE_VECTOR)->create([
            'workspace_id' => $workspace->id,
            'title' => 'Page supprimée définitivement',
            'body' => 'Cette page sera force deleted.',
        ]);

        // Soft-delete then force-delete: the row is gone entirely.
        $page->delete();
        $trashedPage = KnowledgePage::withTrashed()->findOrFail($page->id);
        ForceDeleteKnowledgePage::run($trashedPage, $owner);

        $results = SearchKnowledge::run($workspace, 'supprimée');

        $this->assertNotContains($page->id, array_column($results, 'id'));
        $this->assertEmpty($results);
    }
}
