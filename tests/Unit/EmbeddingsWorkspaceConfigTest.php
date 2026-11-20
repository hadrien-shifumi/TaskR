<?php

namespace Tests\Unit;

use App\Models\Workspace;
use App\Models\WorkspaceEmbeddingSetting;
use App\Support\Embeddings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Workspace-aware resolution of the embeddings client: an owner-configured
 * provider overrides the global (env) configuration for that workspace
 * only, and the optional `dimensions` request param follows the stored
 * opt-in rather than being sent blindly.
 */
class EmbeddingsWorkspaceConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        config()->set('knowledge.embeddings', [
            'base_url' => null,
            'api_key' => null,
            'model' => null,
            'dimensions' => 1024,
        ]);

        parent::tearDown();
    }

    private function configureGlobal(string $baseUrl = 'https://global.test/v1', string $model = 'global-model'): void
    {
        config()->set('knowledge.embeddings', [
            'base_url' => $baseUrl,
            'api_key' => 'global-key',
            'model' => $model,
            'dimensions' => 3,
        ]);
    }

    private function fakeResponse(array $vector): void
    {
        Http::fake(fn (Request $request) => Http::response([
            'data' => [
                ['embedding' => $vector],
            ],
        ]));
    }

    public function test_workspace_settings_override_the_global_config(): void
    {
        $this->configureGlobal();
        $workspace = Workspace::factory()->create();
        WorkspaceEmbeddingSetting::create([
            'workspace_id' => $workspace->id,
            'base_url' => 'https://workspace.test/v1',
            'api_key' => 'workspace-key',
            'model' => 'workspace-model',
            'dimensions' => 3,
            'send_dimensions' => false,
        ]);

        $this->fakeResponse([0.1, 0.2, 0.3]);

        $vector = Embeddings::forWorkspace($workspace)->embed('requête');

        $this->assertSame([0.1, 0.2, 0.3], $vector);

        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), 'https://workspace.test/v1/embeddings')
                && $request->hasHeader('Authorization', 'Bearer workspace-key')
                && $request['model'] === 'workspace-model';
        });
    }

    public function test_workspace_without_settings_falls_back_to_the_global_config(): void
    {
        $this->configureGlobal();
        $this->fakeResponse([0.4, 0.5, 0.6]);

        $vector = Embeddings::forWorkspace(Workspace::factory()->create())->embed('requête');

        $this->assertSame([0.4, 0.5, 0.6], $vector);

        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), 'https://global.test/v1/embeddings')
                && $request->hasHeader('Authorization', 'Bearer global-key')
                && $request['model'] === 'global-model';
        });
    }

    public function test_workspace_settings_enable_search_when_the_global_config_is_empty(): void
    {
        $workspace = Workspace::factory()->create();
        WorkspaceEmbeddingSetting::create([
            'workspace_id' => $workspace->id,
            'base_url' => 'https://workspace.test/v1',
            'api_key' => 'workspace-key',
            'model' => 'workspace-model',
            'dimensions' => 3,
            'send_dimensions' => false,
        ]);

        $embeddings = Embeddings::forWorkspace($workspace);

        $this->assertTrue($embeddings->enabled());
        $this->assertFalse(Embeddings::forWorkspace(Workspace::factory()->create())->enabled());
        $this->assertFalse((new Embeddings)->enabled());
    }

    public function test_the_dimensions_param_is_sent_only_when_the_settings_request_it(): void
    {
        $withOptIn = Workspace::factory()->create();
        WorkspaceEmbeddingSetting::create([
            'workspace_id' => $withOptIn->id,
            'base_url' => 'https://optin.test/v1',
            'api_key' => 'key',
            'model' => 'workspace-model',
            'dimensions' => 3,
            'send_dimensions' => true,
        ]);
        $withoutOptIn = Workspace::factory()->create();
        WorkspaceEmbeddingSetting::create([
            'workspace_id' => $withoutOptIn->id,
            'base_url' => 'https://plain.test/v1',
            'api_key' => 'key',
            'model' => 'workspace-model',
            'dimensions' => 3,
            'send_dimensions' => false,
        ]);

        Http::fake(fn () => Http::response([
            'data' => [['embedding' => [0.1, 0.2, 0.3]]],
        ]));
        $this->configureGlobal('https://global.test/v1');

        Embeddings::forWorkspace($withOptIn)->embed('requête');
        Embeddings::forWorkspace($withoutOptIn)->embed('requête');
        (new Embeddings)->embed('requête');

        $optInRequest = collect(Http::recorded())->first(fn ($pair) => str_contains($pair[0]->url(), 'optin.test'))[0];
        $plainRequest = collect(Http::recorded())->first(fn ($pair) => str_contains($pair[0]->url(), 'plain.test'))[0];
        $globalRequest = collect(Http::recorded())->first(fn ($pair) => str_contains($pair[0]->url(), 'global.test'))[0];

        $this->assertSame(3, $optInRequest['dimensions']);
        $this->assertNull($plainRequest['dimensions'] ?? null);
        $this->assertNull($globalRequest['dimensions'] ?? null);
    }

    public function test_the_api_key_round_trips_encrypted(): void
    {
        $workspace = Workspace::factory()->create();

        $setting = WorkspaceEmbeddingSetting::create([
            'workspace_id' => $workspace->id,
            'base_url' => 'https://workspace.test/v1',
            'api_key' => 'super-secret-key',
            'model' => 'workspace-model',
            'dimensions' => 3,
            'send_dimensions' => false,
        ]);

        $raw = $setting->getRawOriginal('api_key');

        $this->assertNotSame('super-secret-key', $raw);
        $this->assertSame('super-secret-key', $setting->refresh()->api_key);
    }

    public function test_probe_reports_the_raw_dimension_and_latency_without_the_check(): void
    {
        $workspace = Workspace::factory()->create();
        WorkspaceEmbeddingSetting::create([
            'workspace_id' => $workspace->id,
            'base_url' => 'https://workspace.test/v1',
            'api_key' => 'key',
            'model' => 'workspace-model',
            'dimensions' => 99,
            'send_dimensions' => false,
        ]);

        $this->fakeResponse([0.1, 0.2]);

        // The configured expected dimension (99) does not match the probe's
        // raw length (2): a probe deliberately skips the runtime check so
        // the save flow can surface the mismatch itself.
        $probe = Embeddings::forWorkspace($workspace)->probe();

        $this->assertSame(2, $probe['dimensions']);
        $this->assertIsInt($probe['latencyMs']);
        $this->assertGreaterThanOrEqual(0, $probe['latencyMs']);
    }
}
