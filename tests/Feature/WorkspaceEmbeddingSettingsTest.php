<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\AuditEventType;
use App\Jobs\VectorizeKnowledgePage;
use App\Models\AuditEvent;
use App\Models\KnowledgePage;
use App\Models\Membership;
use App\Models\User;
use App\Models\WorkspaceEmbeddingSetting;
use App\Support\Chat;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The /settings/embeddings surface: owner-only write actions (save,
 * probe, re-vectorize), never-exposed API keys and the save-time
 * dimension probe that guards the vector column's fixed size.
 */
class WorkspaceEmbeddingSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const PROBE_HOST = 'https://workspace-provider.test/v1';

    private const CHAT_HOST = 'https://chat-provider.test/v1';

    private function configureInstance(int $dimensions = 3): void
    {
        config()->set('knowledge.embeddings', [
            'base_url' => null,
            'api_key' => null,
            'model' => null,
            'dimensions' => $dimensions,
        ]);
    }

    /**
     * Fake provider answering the save-time probe with a vector of the
     * instance's expected dimension.
     */
    private function fakeProvider(int $dimensions = 3, string $host = self::PROBE_HOST): void
    {
        Http::fake([
            "$host/embeddings" => Http::response([
                'data' => [
                    ['embedding' => array_fill(0, $dimensions, 0.5)],
                ],
            ]),
        ]);
    }

    private function settings(array $overrides = []): array
    {
        return array_merge([
            'base_url' => self::PROBE_HOST,
            'api_key' => 'workspace-secret-key',
            'model' => 'mistral-embed',
        ], $overrides);
    }

    private function chatSettings(array $overrides = []): array
    {
        return array_merge([
            'chat_base_url' => self::CHAT_HOST,
            'chat_api_key' => 'chat-secret-key',
            'chat_model' => 'mistral-large-latest',
        ], $overrides);
    }

    public function test_settings_page_shows_current_config_and_never_the_api_key(): void
    {
        $this->configureInstance();
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        $this->fakeProvider();
        $this->actingAs($owner)
            ->put(route('settings.embeddings.update'), $this->settings())
            ->assertRedirect(route('settings.embeddings'));

        $response = $this->actingAs($owner)->get(route('settings.embeddings'));

        $response->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/Embeddings')
                ->where('settings.baseUrl', self::PROBE_HOST)
                ->where('settings.model', 'mistral-embed')
                ->where('settings.dimensions', 3)
                ->where('settings.hasApiKey', true)
                ->where('activeProvider.source', 'workspace')
                ->where('activeProvider.model', 'mistral-embed')
                ->where('requiredDimensions', 3));

        // The key is stored encrypted and never echoed back, Inertia or HTML.
        $this->assertNotSame('workspace-secret-key', WorkspaceEmbeddingSetting::query()->first()->getRawOriginal('api_key'));
        $response->assertDontSee('workspace-secret-key');
    }

    public function test_fallback_chain_reflects_the_active_provider(): void
    {
        $this->configureInstance();
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        $page = fn () => $this->actingAs($owner)->get(route('settings.embeddings'))->assertOk();

        // Nothing local, nothing global: search will stay unavailable.
        $page()->assertInertia(fn (AssertableInertia $props) => $props
            ->where('settings', null)
            ->where('activeProvider.source', 'none')
            ->where('activeProvider.hasApiKey', false));

        // Still nothing local, but the instance-wide provider exists.
        config()->set('knowledge.embeddings', [
            'base_url' => 'https://global.test/v1',
            'api_key' => 'global-key',
            'model' => 'global-model',
            'dimensions' => 3,
        ]);

        $page()->assertInertia(fn (AssertableInertia $props) => $props
            ->where('activeProvider.source', 'global')
            ->where('activeProvider.model', 'global-model')
            ->where('activeProvider.hasApiKey', true));
    }

    public function test_owner_saves_a_compatible_provider(): void
    {
        $this->configureInstance();
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $this->fakeProvider();

        $this->actingAs($owner)
            ->put(route('settings.embeddings.update'), $this->settings())
            ->assertRedirect(route('settings.embeddings'))
            ->assertSessionHas('status', __('flash.embeddings.updated'));

        $this->assertDatabaseHas('workspace_embedding_settings', [
            'workspace_id' => $workspace->id,
            'base_url' => self::PROBE_HOST,
            'model' => 'mistral-embed',
            'dimensions' => 3,
            'send_dimensions' => false,
        ]);

        // The probe validated the provider using the submitted config only.
        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), self::PROBE_HOST.'/embeddings')
                && $request->hasHeader('Authorization', 'Bearer workspace-secret-key')
                && $request['model'] === 'mistral-embed';
        });

        // And the key is readable only through the encrypted cast.
        $setting = WorkspaceEmbeddingSetting::query()->firstOrFail();
        $this->assertSame('workspace-secret-key', $setting->api_key);
        $this->assertNotSame('workspace-secret-key', $setting->getRawOriginal('api_key'));
    }

    public function test_save_refuses_a_provider_of_the_wrong_dimension(): void
    {
        $this->configureInstance(3);
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        $this->fakeProvider(2, 'https://wrong-dim.test/v1');

        $this->actingAs($owner)
            ->put(route('settings.embeddings.update'), $this->settings(['base_url' => 'https://wrong-dim.test/v1']))
            ->assertSessionHasErrors('model');

        $this->assertSame(0, WorkspaceEmbeddingSetting::query()->count());
    }

    public function test_saving_refuses_an_unreachable_provider(): void
    {
        $this->configureInstance();
        $owner = User::factory()->create();
        CreateWorkspace::run($owner, ['name' => 'WS']);

        Http::fake([
            'https://down.test/v1/embeddings' => Http::response(['error' => 'boom'], 500),
        ]);

        $this->actingAs($owner)
            ->put(route('settings.embeddings.update'), $this->settings(['base_url' => 'https://down.test/v1']))
            ->assertSessionHasErrors('base_url');

        $this->assertSame(0, WorkspaceEmbeddingSetting::query()->count());
    }

    public function test_save_keeps_the_stored_api_key_when_the_field_is_left_empty(): void
    {
        $this->configureInstance();
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        $this->fakeProvider();
        $this->actingAs($owner)
            ->put(route('settings.embeddings.update'), $this->settings())
            ->assertRedirect();

        Http::fake([
            'https://other-model-provider.test/v1/embeddings' => Http::response([
                'data' => [['embedding' => [0.5, 0.5, 0.5]]],
            ]),
        ]);

        $this->actingAs($owner)
            ->put(route('settings.embeddings.update'), $this->settings([
                'base_url' => 'https://other-model-provider.test/v1',
                'model' => 'other-model',
                'api_key' => '',
            ]))
            ->assertRedirect(route('settings.embeddings'));

        $setting = WorkspaceEmbeddingSetting::query()->firstOrFail();
        $this->assertSame('workspace-secret-key', $setting->api_key);
        $this->assertSame('other-model', $setting->model);
        $this->assertSame('https://other-model-provider.test/v1', $setting->base_url);

        // The probe for the new provider ran with the stored key.
        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), 'https://other-model-provider.test/v1/embeddings')
                && $request->hasHeader('Authorization', 'Bearer workspace-secret-key');
        });
    }

    public function test_dimensions_opt_in_is_probed_then_sent_on_every_subsequent_call(): void
    {
        $this->configureInstance(3);
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $this->fakeProvider();

        $this->actingAs($owner)
            ->put(route('settings.embeddings.update'), $this->settings(['dimensions' => 3]))
            ->assertRedirect();

        $setting = WorkspaceEmbeddingSetting::query()->firstOrFail();
        $this->assertSame(3, $setting->dimensions);
        $this->assertTrue($setting->send_dimensions);

        Http::assertSent(function (Request $request): bool {
            return $request['model'] === 'mistral-embed'
                && $request['dimensions'] === 3
                && $request['input'] !== null;
        });
    }

    public function test_member_and_guest_cannot_change_the_provider(): void
    {
        $this->configureInstance();
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        $member = User::factory()->create();
        Membership::create(['user_id' => $member->id, 'workspace_id' => $workspace->id, 'role' => 'member']);
        $guest = User::factory()->create();
        Membership::create(['user_id' => $guest->id, 'workspace_id' => $workspace->id, 'role' => 'guest']);

        Http::fake();

        foreach ([$member, $guest] as $user) {
            $this->actingAs($user)->put(route('settings.embeddings.update'), $this->settings())->assertForbidden();
            $this->actingAs($user)->post(route('settings.embeddings.test'), $this->settings())->assertForbidden();
            $this->actingAs($user)->post(route('settings.embeddings.reembed'))->assertForbidden();
        }

        // Read-only audit stays intact: the page renders for members.
        $this->actingAs($member)->get(route('settings.embeddings'))->assertOk();
        $this->assertSame(0, WorkspaceEmbeddingSetting::query()->count());
    }

    public function test_unauthenticated_visitors_are_sent_to_login(): void
    {
        $this->get(route('settings.embeddings'))->assertRedirect(route('login'));
        $this->put(route('settings.embeddings.update'), $this->settings())->assertRedirect(route('login'));
        $this->post(route('settings.embeddings.reembed'))->assertRedirect(route('login'));
    }

    public function test_save_is_journalled_without_the_api_key(): void
    {
        $this->configureInstance();
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        $this->fakeProvider();
        $this->actingAs($owner)
            ->put(route('settings.embeddings.update'), $this->settings())
            ->assertRedirect();

        $event = AuditEvent::query()->where('type', AuditEventType::WorkspaceEmbeddingsUpdated->value)->firstOrFail();

        $this->assertSame($workspace->id, $event->workspace_id);
        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame(self::PROBE_HOST, $event->changes['base_url']['after']);
        $this->assertSame('mistral-embed', $event->changes['model']['after']);
        $this->assertSame(3, $event->changes['dimensions']['after']);
        $this->assertArrayNotHasKey('api_key', $event->changes);

        // The secret value lives nowhere in the journal row.
        $this->assertStringNotContainsString('workspace-secret-key', json_encode($event->changes));
    }

    public function test_repeated_saves_keep_a_single_settings_row(): void
    {
        $this->configureInstance();
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        $this->fakeProvider();

        $this->actingAs($owner)->put(route('settings.embeddings.update'), $this->settings())->assertRedirect();
        $this->actingAs($owner)->put(route('settings.embeddings.update'), $this->settings(['model' => 'mistral-embed-2']))->assertRedirect();

        $this->assertSame(1, WorkspaceEmbeddingSetting::query()->where('workspace_id', $workspace->id)->count());
        $this->assertDatabaseHas('workspace_embedding_settings', ['model' => 'mistral-embed-2']);
    }

    public function test_test_endpoint_reports_dimension_and_latency_without_the_key(): void
    {
        $this->configureInstance();
        $owner = User::factory()->create();
        CreateWorkspace::run($owner, ['name' => 'WS']);

        $this->fakeProvider(3, 'https://probe-target.test/v1');

        $response = $this->actingAs($owner)
            ->post(route('settings.embeddings.test'), $this->settings(['base_url' => 'https://probe-target.test/v1']))
            ->assertOk()
            ->assertJson([
                'dimensions' => 3,
                'model' => 'mistral-embed',
            ]);

        $payload = $response->json();
        $this->assertIsInt($payload['latencyMs']);
        $this->assertGreaterThanOrEqual(0, $payload['latencyMs']);
        $this->assertStringNotContainsString('workspace-secret-key', $response->getContent());
    }

    public function test_test_endpoint_surfaces_a_dimension_mismatch(): void
    {
        $this->configureInstance(3);
        $owner = User::factory()->create();
        CreateWorkspace::run($owner, ['name' => 'WS']);

        $this->fakeProvider(2, 'https://wrong-dim.test/v1');

        $this->actingAs($owner)
            ->post(route('settings.embeddings.test'), $this->settings(['base_url' => 'https://wrong-dim.test/v1']))
            ->assertSessionHasErrors('model');
    }

    public function test_reembed_endpoint_queues_one_job_per_page_behind_a_flash(): void
    {
        $this->configureInstance();
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

        KnowledgePage::factory()->count(2)->create(['workspace_id' => $workspace->id]);
        Queue::fake();

        $this->actingAs($owner)
            ->post(route('settings.embeddings.reembed'))
            ->assertRedirect(route('settings.embeddings'))
            ->assertSessionHas('status', __('flash.embeddings.reembed', ['count' => 2]));

        Queue::assertPushed(VectorizeKnowledgePage::class, 2);
    }

    public function test_validation_rejects_a_malformed_base_url(): void
    {
        $this->configureInstance();
        $owner = User::factory()->create();
        CreateWorkspace::run($owner, ['name' => 'WS']);

        $this->actingAs($owner)
            ->put(route('settings.embeddings.update'), $this->settings(['base_url' => 'not-a-url']))
            ->assertSessionHasErrors('base_url');

        $this->actingAs($owner)
            ->put(route('settings.embeddings.update'), $this->settings(['model' => '']))
            ->assertSessionHasErrors('model');

        // No stored key yet: the write-only field left empty cannot save.
        $this->actingAs($owner)
            ->put(route('settings.embeddings.update'), $this->settings(['api_key' => '']))
            ->assertSessionHasErrors('api_key');
    }

    public function test_validation_rejects_a_malformed_chat_base_url(): void
    {
        $this->configureInstance();
        $owner = User::factory()->create();
        CreateWorkspace::run($owner, ['name' => 'WS']);

        Http::fake();

        $this->actingAs($owner)
            ->put(route('settings.embeddings.update-chat'), $this->chatSettings(['chat_base_url' => 'not-a-url']))
            ->assertSessionHasErrors('chat_base_url');

        $this->actingAs($owner)
            ->put(route('settings.embeddings.update-chat'), $this->chatSettings(['chat_base_url' => '']))
            ->assertSessionHasErrors('chat_base_url');

        // The pair fallback: both fields left empty but no stored pair
        // anywhere — the probe refuses the chat instead of the 500.
        $this->actingAs($owner)
            ->put(route('settings.embeddings.update-chat'), $this->chatSettings(['chat_base_url' => '', 'chat_api_key' => '']))
            ->assertSessionHasErrors('chat_base_url');
        $this->assertSame(0, WorkspaceEmbeddingSetting::withoutWorkspaceScope()->count());
    }

    public function test_save_keeps_a_single_chat_settings_row(): void
    {
        $this->configureInstance();
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        Http::fake([
            self::CHAT_HOST.'/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Un reply test.']]],
            ]),
        ]);

        $this->actingAs($owner)
            ->put(route('settings.embeddings.update-chat'), $this->chatSettings())
            ->assertRedirect();
        $this->actingAs($owner)
            ->put(route('settings.embeddings.update-chat'), $this->chatSettings(['chat_model' => 'mistral-large-latest-2']))
            ->assertRedirect();

        $this->assertSame(1, WorkspaceEmbeddingSetting::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->count());
        $this->assertDatabaseHas('workspace_embedding_settings', ['chat_model' => 'mistral-large-latest-2']);
    }
}
