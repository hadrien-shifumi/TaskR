<?php

namespace Tests\Feature;

use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Knowledge\UpdateKnowledgePage;
use App\Actions\Workspaces\CreateWorkspace;
use App\Jobs\VectorizeKnowledgePage;
use App\Models\KnowledgePage;
use App\Models\User;
use App\Support\EmbeddingDimensionMismatch;
use App\Support\Embeddings;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class KnowledgeVectorizationTest extends TestCase
{
    use RefreshDatabase;

    private function configureEmbeddings(int $dimensions = 3): void
    {
        config()->set('knowledge.embeddings', [
            'base_url' => 'https://embeddings.test/v1',
            'api_key' => 'test-key',
            'model' => 'text-embedding-3-small',
            'dimensions' => $dimensions,
        ]);
    }

    private function fakeEmbeddingResponse(array $vector): void
    {
        Http::fake([
            'embeddings.test/v1/embeddings' => Http::response(['data' => [['embedding' => $vector]]]),
        ]);
    }

    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace];
    }

    public function test_creating_page_vectorizes_title_and_body(): void
    {
        $this->configureEmbeddings();
        $this->fakeEmbeddingResponse([0.1, 0.2, 0.3]);
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Processus de règlement',
            'body' => 'Les invoices sont émises chaque mois.',
        ]);

        $page->refresh();

        $this->assertNotNull($page->embedded_at);
        $this->assertSame([0.1, 0.2, 0.3], $page->embedding);

        Http::assertSent(function (Request $request): bool {
            return $request['input'] === ["Processus de règlement\n\nLes invoices sont émises chaque mois."];
        });
    }

    public function test_updating_page_revectorizes_new_content(): void
    {
        $this->configureEmbeddings(2);
        $this->fakeEmbeddingResponse([0.4, 0.5]);
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Staging',
            'body' => 'Old content.',
        ]);

        $before = $page->refresh()->embedded_at?->toDateTimeString();

        $this->travel(1)->minutes();

        UpdateKnowledgePage::run($page->refresh(), $owner, [
            'title' => 'Staging',
            'body' => 'New content about staging environments.',
        ]);

        $page->refresh();

        $this->assertSame([0.4, 0.5], $page->embedding);
        $this->assertNotSame($before, $page->embedded_at?->toDateTimeString());
    }

    public function test_endpoint_failure_keeps_page_readable_and_job_is_retried(): void
    {
        $this->configureEmbeddings();
        [$owner, $workspace] = $this->setupWorkspace();

        Queue::fake();

        // The write itself never touches the embeddings endpoint.
        $this->actingAs($owner)
            ->post('/knowledge', [
                'title' => 'Résiliente',
                'body' => 'Page écrite pendant une panne.',
            ])
            ->assertRedirect();

        $page = KnowledgePage::query()->where('title', 'Résiliente')->firstOrFail();
        $this->assertSame('Page écrite pendant une panne.', $page->body);
        $this->assertNull($page->embedding);
        $this->assertNull($page->embedded_at);

        Queue::assertPushed(VectorizeKnowledgePage::class);

        // The job retries with backoff and does not lose the page.
        $job = new VectorizeKnowledgePage($page);
        $this->assertSame(5, $job->tries);

        Http::fake([
            'embeddings.test/v1/embeddings' => Http::response(['error' => 'down'], 500),
        ]);

        $this->expectException(\Exception::class);
        $job->handle(app(Embeddings::class));

        $this->assertNull($page->refresh()->embedding);
    }

    public function test_vectorization_fails_with_clear_error_on_dimension_mismatch(): void
    {
        $this->configureEmbeddings(3);
        $this->fakeEmbeddingResponse([0.1, 0.2]);
        [$owner, $workspace] = $this->setupWorkspace();

        $page = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'last_editor_id' => $owner->id,
            'title' => 'Incohérent',
            'body' => 'Le fournisseur renvoie une autre dimension.',
        ]);

        $job = new VectorizeKnowledgePage($page);

        try {
            $job->handle(app(Embeddings::class));

            $this->fail('EmbeddingDimensionMismatch was not thrown.');
        } catch (EmbeddingDimensionMismatch $exception) {
            $this->assertStringContainsString('KNOWLEDGE_EMBEDDINGS_DIMENSIONS', $exception->getMessage());
        }

        $this->assertNull($page->refresh()->embedding);
        $this->assertNull($page->embedded_at);
    }

    public function test_reembed_command_revectorizes_all_pages_idempotently(): void
    {
        $this->configureEmbeddings();
        [$owner, $workspace] = $this->setupWorkspace();

        Queue::fake();

        $a = CreateKnowledgePage::run($workspace, $owner, ['title' => 'A', 'body' => 'Alpha.']);
        $b = CreateKnowledgePage::run($workspace, $owner, ['title' => 'B', 'body' => 'Beta.']);

        $this->assertNull($a->refresh()->embedding);
        $this->assertNull($b->refresh()->embedding);

        Queue::fakeExcept([VectorizeKnowledgePage::class]);
        $this->fakeEmbeddingResponse([0.7]);
        $this->configureEmbeddings(1);

        $this->assertSame(0, Artisan::call('knowledge:reembed'));

        $this->assertNotNull($a->refresh()->embedding);
        $this->assertNotNull($b->refresh()->embedding);

        // Running it again stays green: the pipeline is idempotent.
        $this->assertSame(0, Artisan::call('knowledge:reembed'));

        $this->assertSame([0.7], $a->refresh()->embedding);
    }
}
