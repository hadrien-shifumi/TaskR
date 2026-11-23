<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Membership;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The "load the models" endpoints behind the AI settings screen: each
 * block (embeddings, chat) asks the backend — never the browser — to
 * list the provider's models through the OpenAI-standard
 * GET {base_url}/models, with the exact candidate-pair resolution of
 * the save flow: a blank write-only key field reuses the stored key,
 * and the chat pair falls back to the stored embeddings pair when both
 * its fields are left empty. Every answer stays structured (never a
 * 500), never contains a key, and a provider that exposes no `data`
 * answers an empty — legitimate — list.
 */
class WorkspaceSettingsModelsTest extends TestCase
{
    use RefreshDatabase;

    private const EMBED_HOST = 'https://workspace-provider.test/v1';

    private const CHAT_HOST = 'https://chat-provider.test/v1';

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
     * Fake the save-flow probes: their settings rows are the fallbacks
     * the model lists reuse.
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

    private function embeddingsSettings(array $overrides = []): array
    {
        return array_merge([
            'base_url' => self::EMBED_HOST,
            'api_key' => 'embeddings-secret-key',
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

    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace];
    }

    public function test_owner_lists_the_embeddings_provider_models(): void
    {
        $this->configureInstance();
        [$owner] = $this->setupWorkspace();

        Http::fake([
            self::EMBED_HOST.'/models' => Http::response([
                'object' => 'list',
                'data' => [
                    ['id' => 'mistral-embed', 'object' => 'model'],
                    ['id' => 'alpha-embed', 'object' => 'model'],
                ],
            ]),
        ]);

        $response = $this->actingAs($owner)
            ->postJson(route('settings.embeddings.models'), [
                'base_url' => self::EMBED_HOST,
                'api_key' => 'embeddings-secret-key',
            ])
            ->assertOk()
            ->assertJson(['models' => [
                ['id' => 'alpha-embed'],
                ['id' => 'mistral-embed'],
            ]]);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === self::EMBED_HOST.'/models'
                && $request->method() === 'GET'
                && $request->hasHeader('Authorization', 'Bearer embeddings-secret-key');
        });

        $this->assertStringNotContainsString('embeddings-secret-key', $response->getContent());
    }

    public function test_the_model_list_is_parsed_defensively(): void
    {
        $this->configureInstance();
        [$owner] = $this->setupWorkspace();

        Http::fake([
            self::EMBED_HOST.'/models' => Http::response([
                'object' => 'list',
                'data' => [
                    'not-an-item',
                    ['id' => 42],
                    ['object' => 'model'],
                    ['id' => 'mistral-embed'],
                    ['id' => 'alpha-embed'],
                    ['id' => 'mistral-embed'],
                ],
            ]),
        ]);

        // Non-string and missing ids are filtered out, duplicates fused,
        // the survivors sorted alphabetically — the free-text input stays
        // the fallback for anything else.
        $this->actingAs($owner)
            ->postJson(route('settings.embeddings.models'), [
                'base_url' => self::EMBED_HOST,
                'api_key' => 'embeddings-secret-key',
            ])
            ->assertOk()
            ->assertJson(['models' => [
                ['id' => 'alpha-embed'],
                ['id' => 'mistral-embed'],
            ]]);
    }

    public function test_an_empty_or_dataless_provider_answer_is_a_legitimate_empty_list(): void
    {
        $this->configureInstance();
        [$owner] = $this->setupWorkspace();

        Http::fake([
            self::EMBED_HOST.'/models' => Http::response(['object' => 'list']),
        ]);

        // No `data` key at all: some providers do not expose the listing —
        // an empty menu is the honest answer, never an error.
        $this->actingAs($owner)
            ->postJson(route('settings.embeddings.models'), [
                'base_url' => self::EMBED_HOST,
                'api_key' => 'embeddings-secret-key',
            ])
            ->assertOk()
            ->assertJson(['models' => []]);
    }

    public function test_the_embeddings_list_falls_back_to_the_stored_key(): void
    {
        $this->configureInstance();
        [$owner, $workspace] = $this->setupWorkspace();

        $this->fakeProviders();
        $this->actingAs($owner)
            ->put(route('settings.embeddings.update'), $this->embeddingsSettings())
            ->assertRedirect();

        Http::fake([
            'https://other-provider.test/v1/models' => Http::response([
                'data' => [['id' => 'other-embed']],
            ]),
        ]);

        // The write-only key field left empty: the listing runs with the
        // stored key, exactly like the save-time probe.
        $this->actingAs($owner)
            ->postJson(route('settings.embeddings.models'), [
                'base_url' => 'https://other-provider.test/v1',
                'api_key' => '',
            ])
            ->assertOk()
            ->assertJson(['models' => [['id' => 'other-embed']]]);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://other-provider.test/v1/models'
                && $request->hasHeader('Authorization', 'Bearer embeddings-secret-key');
        });
    }

    public function test_the_chat_models_are_listed_with_the_submitted_pair(): void
    {
        $this->configureInstance();
        [$owner] = $this->setupWorkspace();

        Http::fake([
            self::CHAT_HOST.'/models' => Http::response([
                'object' => 'list',
                'data' => [
                    ['id' => 'mistral-small-latest'],
                    ['id' => 'mistral-large-4'],
                ],
            ]),
        ]);

        $response = $this->actingAs($owner)
            ->postJson(route('settings.embeddings.chat-models'), [
                'chat_base_url' => self::CHAT_HOST,
                'chat_api_key' => 'chat-secret-key',
            ])
            ->assertOk()
            ->assertJson(['models' => [
                ['id' => 'mistral-large-4'],
                ['id' => 'mistral-small-latest'],
            ]]);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === self::CHAT_HOST.'/models'
                && $request->method() === 'GET'
                && $request->hasHeader('Authorization', 'Bearer chat-secret-key');
        });

        $this->assertStringNotContainsString('chat-secret-key', $response->getContent());
    }

    public function test_the_chat_models_fall_back_to_the_stored_chat_key(): void
    {
        $this->configureInstance();
        [$owner, $workspace] = $this->setupWorkspace();

        $this->fakeProviders();
        $this->actingAs($owner)
            ->put(route('settings.embeddings.update-chat'), $this->chatSettings())
            ->assertRedirect();

        Http::fake([
            'https://other-chat.test/v1/models' => Http::response([
                'data' => [['id' => 'other-chat-model']],
            ]),
        ]);

        // A blank write-only chat key field lists with the stored chat key.
        $this->actingAs($owner)
            ->postJson(route('settings.embeddings.chat-models'), [
                'chat_base_url' => 'https://other-chat.test/v1',
                'chat_api_key' => '',
            ])
            ->assertOk()
            ->assertJson(['models' => [['id' => 'other-chat-model']]]);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://other-chat.test/v1/models'
                && $request->hasHeader('Authorization', 'Bearer chat-secret-key');
        });
    }

    public function test_the_chat_models_use_the_embeddings_pair_when_the_chat_fields_are_left_empty(): void
    {
        $this->configureInstance();
        [$owner, $workspace] = $this->setupWorkspace();

        $this->fakeProviders();
        $this->actingAs($owner)
            ->put(route('settings.embeddings.update'), $this->embeddingsSettings())
            ->assertRedirect();

        Http::fake([
            self::EMBED_HOST.'/models' => Http::response([
                'data' => [['id' => 'mistral-embed']],
            ]),
        ]);

        // Both chat fields left empty: the listing borrows the stored
        // embeddings pair — the mutualisation the runtime resolves for
        // every later completion.
        $this->actingAs($owner)
            ->postJson(route('settings.embeddings.chat-models'), [
                'chat_base_url' => '',
                'chat_api_key' => '',
            ])
            ->assertOk()
            ->assertJson(['models' => [['id' => 'mistral-embed']]]);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === self::EMBED_HOST.'/models'
                && $request->hasHeader('Authorization', 'Bearer embeddings-secret-key');
        });
    }

    public function test_model_routes_are_owner_only(): void
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
            $this->actingAs($user)
                ->postJson(route('settings.embeddings.models'), ['base_url' => self::EMBED_HOST])
                ->assertForbidden();
            $this->actingAs($user)
                ->postJson(route('settings.embeddings.chat-models'), ['chat_base_url' => self::CHAT_HOST])
                ->assertForbidden();
        }

        Http::assertNothingSent();
    }

    public function test_provider_failures_answer_structured_errors_without_a_500_or_a_key(): void
    {
        $this->configureInstance();
        [$owner] = $this->setupWorkspace();

        Http::fake([
            self::CHAT_HOST.'/models' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        // A refused key: an explicit structured refusal, never a 500, and
        // the provider's own words surface in the message.
        $response = $this->actingAs($owner)
            ->postJson(route('settings.embeddings.chat-models'), [
                'chat_base_url' => self::CHAT_HOST,
                'chat_api_key' => 'chat-secret-key',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['chat_base_url']);

        $this->assertStringNotContainsString('chat-secret-key', $response->getContent());

        Http::fake([
            self::EMBED_HOST.'/models' => fn (): never => throw new ConnectionException('cURL error 28: Operation timed out'),
        ]);

        // A dead endpoint: the same structured shape, still no 500.
        $this->actingAs($owner)
            ->postJson(route('settings.embeddings.models'), [
                'base_url' => self::EMBED_HOST,
                'api_key' => 'embeddings-secret-key',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['base_url']);
    }

    public function test_a_provider_refusal_without_any_stored_key_names_the_missing_key(): void
    {
        $this->configureInstance();
        [$owner] = $this->setupWorkspace();

        // Nothing stored anywhere and a blank key field: the same
        // explicit "a key is required" refusal as the save flow, never a
        // 500 and never an empty Authorization header reaching out.
        $this->actingAs($owner)
            ->postJson(route('settings.embeddings.models'), [
                'base_url' => self::EMBED_HOST,
                'api_key' => '',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['api_key']);

        $this->actingAs($owner)
            ->postJson(route('settings.embeddings.chat-models'), [
                'chat_base_url' => self::CHAT_HOST,
                'chat_api_key' => '',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['chat_api_key']);

        Http::assertNothingSent();
    }

    public function test_unauthenticated_visitors_are_sent_to_login(): void
    {
        // The endpoints answer JSON clients (the screen's fetch): without
        // a session the standard JSON answer is an unauthenticated status,
        // never a payload and never a probe.
        Http::fake();

        $this->postJson(route('settings.embeddings.models'), ['base_url' => self::EMBED_HOST])->assertUnauthorized();
        $this->postJson(route('settings.embeddings.chat-models'), ['chat_base_url' => self::CHAT_HOST])->assertUnauthorized();

        Http::assertNothingSent();
    }
}
