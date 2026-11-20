<?php

namespace Tests\Feature;

use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Knowledge\SearchKnowledge;
use App\Actions\Workspaces\CreateWorkspace;
use App\Jobs\VectorizeKnowledgePage;
use App\Mcp\Servers\TaskrServer;
use App\Models\KnowledgePage;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEmbeddingSetting;
use App\Support\Embeddings;
use App\Support\EmbeddingsNotConfigured;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

/**
 * Workspace-scoped resolution of the embeddings provider: two workspaces
 * with two distinct providers never cross paths — semantic search (web +
 * MCP), page vectorization and the re-embed command all follow the
 * resolved per-workspace configuration, falling back to the instance's
 * global one.
 */
class WorkspaceEmbeddingsResolutionTest extends TestCase
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

    private function configureInstance(?string $baseUrl = null, ?string $model = null, int $dimensions = 3): void
    {
        config()->set('knowledge.embeddings', [
            'base_url' => $baseUrl,
            'api_key' => $baseUrl === null ? null : 'global-key',
            'model' => $model,
            'dimensions' => $dimensions,
        ]);
    }

    private function workspaceWithProvider(string $baseUrl, string $model): Workspace
    {
        [$owner, $workspace] = $this->setupWorkspace();

        WorkspaceEmbeddingSetting::create([
            'workspace_id' => $workspace->id,
            'base_url' => $baseUrl,
            'api_key' => 'ws-key',
            'model' => $model,
            'dimensions' => 3,
            'send_dimensions' => false,
        ]);

        return $workspace;
    }

    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace];
    }

    private function fakeProviders(): void
    {
        Http::fake(function (Request $request) {
            $vector = str_contains($request->url(), 'provider-a.test') ? [0.9, 0.1, 0.2] : [0.2, 0.1, 0.9];

            return Http::response(['data' => [['embedding' => $vector]]]);
        });
    }

    public function test_two_workspaces_use_their_own_providers_without_leaking(): void
    {
        $this->fakeProviders();

        $alpha = $this->workspaceWithProvider('https://provider-a.test/v1', 'alpha-model');
        $bravo = $this->workspaceWithProvider('https://provider-b.test/v1', 'bravo-model');

        $alphaOwner = $alpha->memberships()->first()->user;
        $bravoOwner = $bravo->memberships()->first()->user;

        app(WorkspaceContext::class)->set($alpha);
        $alphaPage = CreateKnowledgePage::run($alpha, $alphaOwner, [
            'title' => 'Page Alpha',
            'body' => 'Contenu de la page alpha.',
        ]);

        app(WorkspaceContext::class)->set($bravo);
        $bravoPage = CreateKnowledgePage::run($bravo, $bravoOwner, [
            'title' => 'Page Bravo',
            'body' => 'Contenu de la page bravo.',
        ]);

        $this->assertSame([0.9, 0.1, 0.2], $alphaPage->refresh()->embedding);
        $this->assertSame([0.2, 0.1, 0.9], $bravoPage->refresh()->embedding);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'provider-a.test/v1/embeddings')) {
                return false;
            }

            return collect($request['input'] ?? [])->contains(fn (string $input) => str_contains($input, 'Page Alpha'))
                && $request['model'] === 'alpha-model'
                && $request->hasHeader('Authorization', 'Bearer ws-key');
        });

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'provider-b.test/v1/embeddings')) {
                return false;
            }

            return collect($request['input'] ?? [])->contains(fn (string $input) => str_contains($input, 'Page Bravo'))
                && $request['model'] === 'bravo-model';
        });
    }

    public function test_workspace_search_uses_the_workspace_provider(): void
    {
        $this->fakeProviders();

        $alpha = $this->workspaceWithProvider('https://provider-a.test/v1', 'alpha-model');
        $owner = $alpha->memberships()->first()->user;

        app(WorkspaceContext::class)->set($alpha);
        KnowledgePage::factory()->create([
            'workspace_id' => $alpha->id,
            'title' => 'Règlement',
            'body' => 'Le cycle de facturation.',
            'embedding' => [1.0, 0.0, 0.0],
        ]);

        $results = SearchKnowledge::run($alpha, 'cycle de facturation', null, 10, $owner);

        $this->assertSame('Règlement', $results[0]['title']);
        $this->assertGreaterThan(0.9, $results[0]['score']);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'provider-a.test/v1/embeddings')
                && $request['model'] === 'alpha-model';
        });
    }

    public function test_workspace_without_settings_uses_the_global_provider(): void
    {
        Http::fake([
            'https://global-provider.test/v1/embeddings' => Http::response([
                'data' => [['embedding' => [0.9, 0.1, 0.2]]],
            ]),
        ]);

        $this->configureInstance('https://global-provider.test/v1', 'global-model', 3);

        [$owner, $workspace] = $this->setupWorkspace();
        $page = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Global', 'body' => 'Via le fournisseur global.']);

        $this->assertSame([0.9, 0.1, 0.2], $page->refresh()->embedding);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'global-provider.test/v1/embeddings')
                && $request['model'] === 'global-model'
                && $request->hasHeader('Authorization', 'Bearer global-key');
        });
    }

    public function test_workspace_settings_enable_search_while_the_bare_workspace_stays_unavailable(): void
    {
        $this->configureInstance(); // nothing global
        $this->fakeProviders();

        $alpha = $this->workspaceWithProvider('https://provider-a.test/v1', 'alpha-model');
        [$bravoOwner, $bravo] = $this->setupWorkspace();

        $results = SearchKnowledge::run($alpha, 'cycle de facturation', null, 10, $alpha->memberships()->first()->user);
        $this->assertSame([], $results); // provider-a fakes every vector as [1,0,0], no page matches

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'provider-a.test/v1/embeddings'));

        $this->expectException(EmbeddingsNotConfigured::class);
        SearchKnowledge::run($bravo, 'anything', null, 10, $bravoOwner);
    }

    public function test_mcp_search_knowledge_uses_the_workspace_provider(): void
    {
        $this->fakeProviders();

        $alpha = $this->workspaceWithProvider('https://provider-a.test/v1', 'alpha-model');
        $owner = $alpha->memberships()->first()->user;

        KnowledgePage::factory()->create([
            'workspace_id' => $alpha->id,
            'title' => 'Processus de règlement',
            'body' => 'Le cycle de règlement des factures clients.',
            'embedding' => [1.0, 0.0, 0.0],
        ]);

        $result = TaskrServer::actingAs($owner)
            ->tool(\App\Mcp\Tools\SearchKnowledge::class, [
                'workspace_id' => $alpha->id,
                'query' => 'cycle de règlement',
            ])
            ->assertOk()
            ->payload();

        $this->assertSame('Processus de règlement', $result['results'][0]['title']);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'provider-a.test/v1/embeddings')
                && $request->hasHeader('Authorization', 'Bearer ws-key');
        });
    }

    public function test_vectorize_job_resolves_the_provider_from_the_page_workspace(): void
    {
        // The settings are written AFTER the page exists: the queued job
        // must resolve the provider per page, at run time.
        Http::fake(['*' => Http::response([
            'data' => [['embedding' => [0.3, 0.5, 0.5]]],
        ])]);

        $this->configureInstance('https://old-global.test/v1', 'old-model', 3);

        [$owner, $workspace] = $this->setupWorkspace();
        $page = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Re-vectorisée',
            'body' => 'Contenuinchangé.',
        ]);

        WorkspaceEmbeddingSetting::create([
            'workspace_id' => $workspace->id,
            'base_url' => 'https://fresh-provider.test/v1',
            'api_key' => 'ws-key',
            'model' => 'fresh-model',
            'dimensions' => 3,
            'send_dimensions' => false,
        ]);

        (new VectorizeKnowledgePage($page))->handle();

        $this->assertSame([0.3, 0.5, 0.5], $page->refresh()->embedding);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'fresh-provider.test/v1/embeddings')
                && $request['model'] === 'fresh-model';
        });

        $oldGlobalCalls = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'old-global.test'))
            ->count();
        $this->assertSame(0, $oldGlobalCalls);
    }

    public function test_reembed_command_with_workspace_option_targets_only_that_workspace(): void
    {
        Http::fake(['*' => Http::response([
            'data' => [['embedding' => [1.0, 0.0, 0.0]]],
        ])]);

        $this->configureInstance('https://global.test/v1', 'global-model', 3);

        $alpha = $this->workspaceWithProvider('https://provider-a.test/v1', 'alpha-model');
        [$bravoOwner, $bravo] = $this->setupWorkspace();

        $alphaPage = KnowledgePage::factory()->create(['workspace_id' => $alpha->id, 'title' => 'A', 'body' => 'Alpha.']);
        $bravoPage = KnowledgePage::factory()->create(['workspace_id' => $bravo->id, 'title' => 'B', 'body' => 'Bravo.']);

        Queue::fakeExcept(VectorizeKnowledgePage::class);

        $this->assertSame(0, Artisan::call('knowledge:reembed', ['--workspace' => $alpha->id]));

        $this->assertNotNull($alphaPage->refresh()->embedded_at);
        $this->assertNull($bravoPage->refresh()->embedded_at);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'provider-a.test/v1/embeddings')
                && $request['model'] === 'alpha-model';
        });

        $globalCalls = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'global.test/v1/embeddings'))
            ->count();
        $this->assertSame(0, $globalCalls);
    }

    public function test_sending_dimensions_when_the_workspace_opted_in(): void
    {
        Http::fake(['*' => Http::response([
            'data' => [['embedding' => [0.1, 0.2, 0.3]]],
        ])]);

        $workspace = $this->workspaceWithProvider('https://provider-a.test/v1', 'alpha-model');
        WorkspaceEmbeddingSetting::query()->where('workspace_id', $workspace->id)->update(['send_dimensions' => true]);

        app(WorkspaceContext::class)->set($workspace);
        Embeddings::forWorkspace($workspace)->embed('requête');

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'provider-a.test/v1/embeddings')
                && $request['dimensions'] === 3;
        });
    }
}
