<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\User;
use App\Models\WorkspaceEmbeddingSetting;
use App\Support\Chat;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The /settings/embeddings surface: the chat block joins the existing
 * embeddings provider on the same screen. Owner-only write actions (save,
 * probe) — never-exposed API keys, the pair fallback reusing the stored
 * embeddings credentials, and the audit journal. Without chat settings the
 * chat is simply disabled — never a 500.
 */
class WorkspaceChatSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_HOST = 'https://chat-provider.test/v1';

    private const EMBED_HOST = 'https://workspace-provider.test/v1';

    private const EMBED_PROBE_HOST = 'https://probe-target.test/v1';

    protected function tearDown(): void
    {
        Http::fake();

        parent::tearDown();
    }

    private function configureInstance(): void
    {
        config()->set('knowledge.embeddings', [
            'base_url' => null,
            'api_key' => null,
            'model' => null,
            'dimensions' => 1024,
        ]);
    }

    /**
     * Fake both save-flow probes: the embeddings first (the shared page's
     * form also validates it), then the chat.
     */
    private function fakeProviders(): void
    {
        Http::fake([
            self::EMBED_HOST.'/embeddings' => Http::response([
                'data' => [['embedding' => array_fill(0, 1024, 0.5)]],
            ]),
            self::EMBED_HOST.'/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Un reply test.']]],
            ]),
            self::CHAT_HOST.'/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Un reply test.']]],
            ]),
        ]);
    }

    private function chatSettings(array $overrides = []): array
    {
        return array_merge([
            'chat_base_url' => self::CHAT_HOST,
            'chat_api_key' => 'chat-secret-key',
            'chat_model' => 'mistral-large-latest',
        ], $overrides);
    }

    private function embeddingsSettings(array $overrides = []): array
    {
        return array_merge([
            'base_url' => self::EMBED_HOST,
            'api_key' => 'workspace-secret-key',
            'model' => 'mistral-embed',
        ], $overrides);
    }

    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace];
    }

    public function test_settings_page_shows_the_chat_config_and_never_the_api_key(): void
    {
        $this->configureInstance();
        [$owner, $workspace] = $this->setupWorkspace();

        $this->fakeProviders();
        $this->actingAs($owner)
            ->put(route('settings.embeddings.update'), $this->embeddingsSettings())
            ->assertRedirect(route('settings.embeddings'))
            ->assertSessionHas('status', __('flash.embeddings.updated'));
        $this->actingAs($owner)
            ->put(route('settings.embeddings.update-chat'), $this->chatSettings())
            ->assertRedirect(route('settings.embeddings'))
            ->assertSessionHas('status', __('flash.chat.updated'));

        $response = $this->actingAs($owner)->get(route('settings.embeddings'));

        $response->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/Embeddings')
                ->where('settings.chatBaseUrl', self::CHAT_HOST)
                ->where('settings.chatModel', 'mistral-large-latest')
                ->where('settings.chatHasApiKey', true)
                ->where('chatProvider.source', 'workspace')
                ->where('chatProvider.baseUrl', self::CHAT_HOST)
                ->where('chatProvider.model', 'mistral-large-latest')
                ->where('chatProvider.hasApiKey', true));

        // The chat key is stored encrypted and never echoed back, Inertia
        // or HTML — the payload only reports that it exists.
        $setting = WorkspaceEmbeddingSetting::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->firstOrFail();
        $this->assertSame('chat-secret-key', $setting->chat_api_key);
        $this->assertNotSame('chat-secret-key', $setting->getRawOriginal('chat_api_key'));
        $response->assertDontSee('chat-secret-key');
    }

    public function test_the_chat_is_simply_disabled_without_any_settings(): void
    {
        $this->configureInstance();
        [$owner] = $this->setupWorkspace();

        // Nothing configured anywhere: no chat block on the page, no key
        // flag, no probe — and the save flow refuses an empty model.
        $this->actingAs($owner)->get(route('settings.embeddings'))
            ->assertInertia(fn (AssertableInertia $props) => $props
                ->where('settings', null)
                ->where('chatProvider.source', 'none')
                ->where('chatProvider.hasApiKey', false));

        // And saving without any chat model refuses the whole block.
        $this->fakeProviders();
        $this->actingAs($owner)
            ->put(route('settings.embeddings.update-chat'), $this->chatSettings(['chat_model' => '']))
            ->assertSessionHasErrors('chat_model');
        $this->assertSame(0, WorkspaceEmbeddingSetting::query()->count());
    }

    public function test_the_chat_fallback_pair_reuses_the_embeddings_pair(): void
    {
        $this->configureInstance();
        [$owner, $workspace] = $this->setupWorkspace();

        $this->fakeProviders();
        // The owner saved the chat pair empty (the write-only fields) — only
        // the model: the chat reuses the stored embeddings endpoint and key
        // at runtime, without persisting anything into the pair.
        $this->actingAs($owner)
            ->put(route('settings.embeddings.update'), $this->embeddingsSettings())
            ->assertRedirect();
        $this->actingAs($owner)
            ->put(route('settings.embeddings.update-chat'), $this->chatSettings([
                'chat_base_url' => null,
                'chat_api_key' => null,
            ]))
            ->assertRedirect();

        $setting = WorkspaceEmbeddingSetting::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->firstOrFail();
        $this->assertNull($setting->chat_base_url);
        $this->assertNull($setting->chat_api_key);
        $this->assertSame('mistral-large-latest', $setting->chat_model);

        // The runtime resolution follows the owner's mutualisation: the
        // stored embeddings endpoint and key, the chat model its own.
        $chat = Chat::forWorkspace($workspace);
        $this->assertTrue($chat->enabled());
        $this->assertSame('fallback', $chat->source());
        $this->assertSame(self::EMBED_HOST, $chat->describe()['baseUrl']);
        $this->assertSame('mistral-large-latest', $chat->describe()['model']);
        $this->assertTrue($chat->describe()['hasApiKey']);

        // And the shared client completes with the reused pair — exact
        // same resolution for a plain container-resolved client.
        $resolved = Chat::forWorkspaceId($workspace->id);
        $this->assertSame('fallback', $resolved->source());
    }

    public function test_owner_saves_a_compatible_chat_provider(): void
    {
        $this->configureInstance();
        [$owner, $workspace] = $this->setupWorkspace();

        $this->fakeProviders();

        $this->actingAs($owner)
            ->put(route('settings.embeddings.update-chat'), $this->chatSettings())
            ->assertRedirect(route('settings.embeddings'))
            ->assertSessionHas('status', __('flash.chat.updated'));

        $this->assertDatabaseHas('workspace_embedding_settings', [
            'workspace_id' => $workspace->id,
            'chat_base_url' => self::CHAT_HOST,
            'chat_model' => 'mistral-large-latest',
        ]);

        // The probe validated the provider using the submitted config only.
        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), self::CHAT_HOST.'/chat/completions')
                && $request->hasHeader('Authorization', 'Bearer chat-secret-key')
                && $request['model'] === 'mistral-large-latest'
                && $request['messages'] === [['role' => 'user', 'content' => 'TaskR']];
        });

        // And the key is readable only through the encrypted cast.
        $setting = WorkspaceEmbeddingSetting::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->firstOrFail();
        $this->assertSame('chat-secret-key', $setting->chat_api_key);
        $this->assertNotSame('chat-secret-key', $setting->getRawOriginal('chat_api_key'));
    }

    public function test_saving_refuses_an_unreachable_chat_provider(): void
    {
        $this->configureInstance();
        [$owner, $workspace] = $this->setupWorkspace();

        Http::fake([
            'https://down-chat.test/v1/chat/completions' => Http::response(['error' => 'boom'], 500),
        ]);

        $this->actingAs($owner)
            ->put(route('settings.embeddings.update-chat'), $this->chatSettings([
                'chat_base_url' => 'https://down-chat.test/v1',
            ]))
            ->assertSessionHasErrors('chat_base_url');

        $this->assertSame(0, WorkspaceEmbeddingSetting::withoutWorkspaceScope()->count());
    }

    public function test_probe_falls_back_to_the_stored_chat_key_when_the_field_is_left_empty(): void
    {
        $this->configureInstance();
        [$owner, $workspace] = $this->setupWorkspace();

        $this->fakeProviders();
        $this->actingAs($owner)
            ->put(route('settings.embeddings.update-chat'), $this->chatSettings())
            ->assertRedirect();

        Http::fake([
            'https://other-provider.test/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Le stocké.']]],
            ]),
        ]);

        // An empty write-only field keeps the stored key: the probe for the
        // new provider runs with the stored one, like the embeddings flow.
        $this->actingAs($owner)
            ->put(route('settings.embeddings.update-chat'), $this->chatSettings([
                'chat_base_url' => 'https://other-provider.test/v1',
                'chat_api_key' => '',
                'chat_model' => 'other-chat-model',
            ]))
            ->assertRedirect();

        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), 'https://other-provider.test/v1/chat/completions')
                && $request->hasHeader('Authorization', 'Bearer chat-secret-key');
        });

        $setting = WorkspaceEmbeddingSetting::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertSame('chat-secret-key', $setting->chat_api_key);
    }

    public function test_member_and_guest_cannot_change_the_chat(): void
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
            $this->actingAs($user)->put(route('settings.embeddings.update-chat'), $this->chatSettings())->assertForbidden();
            $this->actingAs($user)->post(route('settings.embeddings.test-chat'), $this->chatSettings())->assertForbidden();
        }

        // The settings page itself stays readable for members — the chat
        // just shows the read-only member indicator.
        $this->actingAs($member)->get(route('settings.embeddings'))->assertOk();
        $this->assertSame(0, WorkspaceEmbeddingSetting::query()->count());
    }

    public function test_save_is_journalled_without_the_chat_api_key(): void
    {
        $this->configureInstance();
        [$owner, $workspace] = $this->setupWorkspace();

        $this->fakeProviders();
        $this->actingAs($owner)
            ->put(route('settings.embeddings.update-chat'), $this->chatSettings())
            ->assertRedirect();

        $event = AuditEvent::query()
            ->where('type', AuditEventType::WorkspaceChatUpdated->value)
            ->firstOrFail();

        $this->assertSame($workspace->id, $event->workspace_id);
        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame(self::CHAT_HOST, $event->changes['chat_base_url']['after']);
        $this->assertSame('mistral-large-latest', $event->changes['chat_model']['after']);
        $this->assertArrayNotHasKey('chat_api_key', $event->changes);

        // The secret value lives nowhere in the journal row.
        $this->assertStringNotContainsString('chat-secret-key', json_encode($event->changes));
    }

    public function test_test_chat_endpoint_reports_content_and_latency_without_the_key(): void
    {
        $this->configureInstance();
        [$owner] = $this->setupWorkspace();

        Http::fake([
            'https://probe-chat.test/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Un reply test.']]],
            ]),
        ]);

        $response = $this->actingAs($owner)
            ->post(route('settings.embeddings.test-chat'), $this->chatSettings([
                'chat_base_url' => 'https://probe-chat.test/v1',
            ]))
            ->assertOk()
            ->assertJsonStructure(['content', 'latencyMs']);

        $payload = $response->json();
        $this->assertIsInt($payload['latencyMs']);
        $this->assertGreaterThanOrEqual(0, $payload['latencyMs']);
        $this->assertSame('Un reply test.', $payload['content']);
        $this->assertStringNotContainsString('chat-secret-key', $response->getContent());
    }

    public function test_validation_rejects_a_malformed_chat_base_url(): void
    {
        $this->configureInstance();
        [$owner] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->put(route('settings.embeddings.update-chat'), $this->chatSettings(['chat_base_url' => 'not-a-url']))
            ->assertSessionHasErrors('chat_base_url');

        $this->actingAs($owner)
            ->put(route('settings.embeddings.update-chat'), $this->chatSettings(['chat_model' => '']))
            ->assertSessionHasErrors('chat_model');

        // No stored key yet: the write-only field left empty cannot save —
        // the probe reuses the stored pair first (embeddings), so it only
        // refuses when nothing is stored anywhere.
        $this->actingAs($owner)
            ->put(route('settings.embeddings.update-chat'), $this->chatSettings(['chat_api_key' => '']))
            ->assertSessionHasErrors('chat_api_key');
    }

    public function test_test_chat_probe_falls_back_to_the_stored_chat_key_when_the_field_is_left_empty(): void
    {
        $this->configureInstance();
        [$owner] = $this->setupWorkspace();

        $this->fakeProviders();
        $this->actingAs($owner)
            ->put(route('settings.embeddings.update-chat'), $this->chatSettings())
            ->assertRedirect();

        Http::fake([
            'https://other-probe.test/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Le stocké aussi.']]],
            ]),
        ]);

        // The probe button always posts the raw form: an empty write-only
        // key field probes with the stored chat key — like the save flow,
        // never a 500 and never an empty Authorization header.
        $response = $this->actingAs($owner)
            ->post(route('settings.embeddings.test-chat'), $this->chatSettings([
                'chat_base_url' => 'https://other-probe.test/v1',
                'chat_api_key' => '',
                'chat_model' => 'other-chat-model',
            ]))
            ->assertOk()
            ->assertJsonStructure(['content', 'latencyMs']);

        $this->assertSame('Le stocké aussi.', $response->json('content'));
        $this->assertStringNotContainsString('chat-secret-key', $response->getContent());

        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), 'https://other-probe.test/v1/chat/completions')
                && $request->hasHeader('Authorization', 'Bearer chat-secret-key')
                && $request['model'] === 'other-chat-model';
        });
    }

    public function test_unauthenticated_visitors_are_sent_to_login(): void
    {
        $this->get(route('settings.embeddings'))->assertRedirect(route('login'));
        $this->put(route('settings.embeddings.update'), $this->embeddingsSettings())->assertRedirect(route('login'));
        $this->put(route('settings.embeddings.update-chat'), $this->chatSettings())->assertRedirect(route('login'));
        $this->post(route('settings.embeddings.test-chat'), $this->chatSettings())->assertRedirect(route('login'));
    }
}
