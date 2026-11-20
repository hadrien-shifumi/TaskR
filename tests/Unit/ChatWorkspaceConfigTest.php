<?php

namespace Tests\Unit;

use App\Models\Workspace;
use App\Models\WorkspaceEmbeddingSetting;
use App\Support\Chat;
use App\Support\Embeddings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Workspace-aware resolution of the chat client: an owner-configured
 * chat provider overrides the stored embeddings pair for that workspace
 * only, and the optional pair fallback reuses the stored embeddings
 * endpoint and key when the owner left the chat fields empty.
 */
class ChatWorkspaceConfigTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_HOST = 'https://chat-provider.test/v1';

    private const EMBED_HOST = 'https://embeddings.test/v1';

    protected function tearDown(): void
    {
        config()->set('knowledge.chat', []);
        config()->set('knowledge.embeddings', [
            'base_url' => null,
            'api_key' => null,
            'model' => null,
            'dimensions' => 1024,
        ]);
        Http::fake();

        parent::tearDown();
    }

    private function configureWorkspace(array $settings): Workspace
    {
        $workspace = Workspace::factory()->create();

        WorkspaceEmbeddingSetting::create(array_merge([
            'workspace_id' => $workspace->id,
            'base_url' => self::EMBED_HOST,
            'api_key' => 'embed-key',
            'model' => 'mistral-embed',
            'dimensions' => 1024,
            'send_dimensions' => false,
            'chat_base_url' => null,
            'chat_api_key' => null,
            'chat_model' => null,
        ], $settings));

        return $workspace;
    }

    public function test_chat_settings_take_precedence_over_the_embeddings_pair(): void
    {
        $workspace = $this->configureWorkspace([
            'chat_base_url' => self::CHAT_HOST,
            'chat_api_key' => 'chat-key',
            'chat_model' => 'mistral-large-latest',
        ]);

        $chat = Chat::forWorkspace($workspace);
        $embed = Embeddings::forWorkspace($workspace);

        // The workspace's own chat pair overrides the embeddings pair...
        $this->assertTrue($chat->enabled());
        $this->assertSame('workspace', $chat->source());
        $this->assertSame(self::CHAT_HOST, $chat->describe()['baseUrl']);
        $this->assertSame('mistral-large-latest', $chat->describe()['model']);
        $this->assertTrue($chat->describe()['hasApiKey']);
        // ...and the embeddings resolution stays untouched — same row, own pair.
        $this->assertSame('https://embeddings.test/v1', $embed->describe()['baseUrl']);
        $this->assertSame('mistral-embed', $embed->describe()['model']);
    }

    public function test_workspace_without_chat_settings_reuses_the_embeddings_pair(): void
    {
        $workspace = $this->configureWorkspace([
            'chat_model' => 'mistral-large-latest',
        ]);

        $chat = Chat::forWorkspace($workspace);

        // The pair fallback: the chat's own URL and key are empty, so it
        // reuses the stored embeddings endpoint and key — the owner's
        // explicit mutualisation choice. The chat model stays its own.
        $this->assertTrue($chat->enabled());
        $this->assertSame('fallback', $chat->source());
        $this->assertSame(self::EMBED_HOST, $chat->describe()['baseUrl']);
        $this->assertSame('mistral-large-latest', $chat->describe()['model']);
        $this->assertTrue($chat->describe()['hasApiKey']);
    }

    public function test_the_chat_stays_disabled_without_any_settings(): void
    {
        // A workspace whose owner left every chat field empty and whose
        // stored embeddings pair is also gone: the chat is simply
        // disabled — never a 500.
        $withEmbed = $this->configureWorkspace([]);
        WorkspaceEmbeddingSetting::query()->delete();

        $chat = Chat::forWorkspace($withEmbed);

        $this->assertFalse($chat->enabled());
        $this->assertSame('none', $chat->source());
        $this->assertFalse($chat->describe()['hasApiKey']);
        $this->assertNull($chat->describe()['baseUrl']);
        $this->assertNull($chat->describe()['model']);
    }

    public function test_the_chat_api_key_round_trips_encrypted(): void
    {
        $workspace = $this->configureWorkspace([
            'chat_api_key' => 'chat-secret-key',
        ]);

        $setting = WorkspaceEmbeddingSetting::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->firstOrFail();

        $this->assertSame('chat-secret-key', $setting->chat_api_key);
        $this->assertNotSame('chat-secret-key', $setting->getRawOriginal('chat_api_key'));
    }

    public function test_probe_reports_the_reply_and_latency(): void
    {
        $workspace = $this->configureWorkspace([
            'chat_base_url' => self::CHAT_HOST,
            'chat_api_key' => 'chat-key',
            'chat_model' => 'mistral-large-latest',
        ]);

        Http::fake([
            self::CHAT_HOST.'/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Un reply test.']]],
            ]),
        ]);

        $probe = Chat::forWorkspace($workspace)->probe();

        $this->assertSame('Un reply test.', $probe['content']);
        $this->assertIsInt($probe['latencyMs']);
        $this->assertGreaterThanOrEqual(0, $probe['latencyMs']);

        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), self::CHAT_HOST.'/chat/completions')
                && $request->hasHeader('Authorization', 'Bearer chat-key')
                && $request['model'] === 'mistral-large-latest'
                && $request['messages'] === [
                    ['role' => 'user', 'content' => 'TaskR'],
                ];
        });
    }

    public function test_complete_sends_the_message_and_returns_the_reply(): void
    {
        $workspace = $this->configureWorkspace([
            'chat_base_url' => self::CHAT_HOST,
            'chat_api_key' => 'chat-key',
            'chat_model' => 'mistral-large-latest',
        ]);

        Http::fake([
            self::CHAT_HOST.'/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Réponse du modèle.']]],
            ]),
        ]);

        $reply = Chat::forWorkspace($workspace)->complete('Bonjour');

        $this->assertSame('Réponse du modèle.', $reply);
    }

    public function test_the_fallback_pair_also_needs_its_own_model(): void
    {
        // The pair fallback carries the endpoint and key, but the chat
        // model remains required to enable the chat — without one, the
        // chat is disabled even with a reusable embeddings pair.
        $workspace = $this->configureWorkspace([]);

        $this->assertFalse(Chat::forWorkspace($workspace)->enabled());
        $this->assertSame('none', Chat::forWorkspace($workspace)->source());
    }
}
