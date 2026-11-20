<?php

namespace App\Support;

use App\Models\Workspace;
use App\Models\WorkspaceEmbeddingSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Minimal client for an OpenAI-compatible chat completions endpoint
 * (POST {base_url}/chat/completions, request `messages`, response
 * `choices[0].message.content`). Works with Mistral, OpenAI, Ollama,
 * vLLM, LM Studio and most providers.
 *
 * The chat credentials live on the same `workspace_embedding_settings`
 * row as the embeddings provider. Per-workspace overrides take
 * precedence: `Chat::forWorkspace($workspace)` (or `forWorkspaceId`)
 * returns a client bound to the workspace's own chat configuration —
 * base URL, key and model; without any of them the chat is simply
 * disabled, never a 500. When the owner leaves its chat base URL and
 * API key empty (the pair fallback), the client reuses the stored
 * embeddings endpoint and key.
 *
 * Unlike App\Support\Embeddings, the chat key is never sent in a
 * request body — only whether one is set is ever serialized.
 */
class Chat
{
    public const SOURCE_WORKSPACE = 'workspace';

    public const SOURCE_FALLBACK = 'fallback';

    public const SOURCE_NONE = 'none';

    /**
     * Explicit chat configuration, when this client was resolved for a
     * specific workspace. Null reads the workspace's stored chat values
     * with the pair fallback applied below.
     *
     * @var array{base_url: ?string, api_key: ?string, model: ?string}|null
     */
    protected ?array $config;

    /**
     * Which source the resolved settings came from: `workspace` (the
     * workspace's own chat pair), `fallback` (the stored embeddings pair,
     * reused when the chat fields are left empty) or `none` (nothing
     * configured: the chat is simply disabled, never a 500).
     */
    protected string $source;

    /**
     * @param  array{base_url: ?string, api_key: ?string, model: ?string}|null  $config
     */
    public function __construct(?array $config = null, string $source = self::SOURCE_NONE)
    {
        $this->config = $config;
        $this->source = $source;
    }

    /**
     * Client for the workspace's effective chat provider: its own
     * configured chat pair when one exists, the stored embeddings pair
     * it reuses otherwise (the owner's explicit pair-fallback choice).
     * Must stay usable outside HTTP (queued completions): the stored
     * settings are looked up by workspace id, explicitly out of any
     * request-scoped tenant context.
     */
    public static function forWorkspace(?Workspace $workspace): static
    {
        return static::forWorkspaceId($workspace?->id);
    }

    public static function forWorkspaceId(?string $workspaceId): static
    {
        if ($workspaceId === null) {
            return new static;
        }

        $setting = WorkspaceEmbeddingSetting::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->first();

        if ($setting === null) {
            return new static;
        }

        $ownPair = $setting->chat_base_url !== null && $setting->chat_base_url !== ''
            && $setting->chat_api_key !== null && $setting->chat_api_key !== '';

        // The chat model is required to enable the chat: without one, the
        // chat is disabled even with a reusable embeddings pair.
        if (! ($setting->chat_model !== null && $setting->chat_model !== '')) {
            return new static;
        }

        $client = new static([
            'base_url' => $ownPair ? $setting->chat_base_url : $setting->base_url,
            'api_key' => $ownPair ? $setting->chat_api_key : $setting->api_key,
            'model' => $setting->chat_model,
        ], $ownPair ? self::SOURCE_WORKSPACE : self::SOURCE_FALLBACK);

        return $client;
    }

    /**
     * Which source this client's settings came from — consumers use it to
     * report which provider is in use.
     */
    public function source(): string
    {
        return $this->source;
    }

    /**
     * Whether this client was resolved from a workspace's stored settings —
     * consumers use it to report which provider is in use.
     */
    public function fromWorkspaceSettings(): bool
    {
        return $this->source === self::SOURCE_WORKSPACE;
    }

    /**
     * Summary of the effective chat provider for an owner configuring it
     * (which source is active and its non-secret settings). The API key
     * never appears — only whether one is set.
     *
     * @return array{source: string, baseUrl: ?string, model: ?string, hasApiKey: bool}
     */
    public function describe(): array
    {
        $config = $this->config();

        return [
            'source' => $this->source,
            'baseUrl' => $config['base_url'],
            'model' => $config['model'],
            'hasApiKey' => $config['api_key'] !== null && $config['api_key'] !== '',
        ];
    }

    /**
     * The chat reads the workspace's own chat columns only — there is no
     * instance-wide chat configuration (arbiter decision). Without a
     * resolved client the chat is simply disabled, never a 500.
     *
     * @return array{base_url: ?string, api_key: ?string, model: ?string}
     */
    protected function config(): array
    {
        return $this->config ?? ['base_url' => null, 'api_key' => null, 'model' => null];
    }

    /**
     * Chat is usable only when all three settings are provided: the
     * endpoint URL, its API key and the conversational model. Without
     * one of them the chat is simply disabled — never a 500.
     */
    public function enabled(): bool
    {
        $config = $this->config();

        return $config['base_url'] !== null && $config['base_url'] !== ''
            && $config['api_key'] !== null && $config['api_key'] !== ''
            && $config['model'] !== null && $config['model'] !== '';
    }

    /**
     * Complete a user message and return the assistant's reply text.
     *
     * @throws RequestException
     * @throws ConnectionException
     */
    public function complete(string $message): string
    {
        $config = $this->config();

        $response = Http::withToken($config['api_key'] ?? '')
            ->acceptJson()
            ->post(rtrim((string) $config['base_url'], '/').'/chat/completions', [
                'model' => $config['model'],
                'messages' => [
                    ['role' => 'user', 'content' => $message],
                ],
            ])
            ->throw();

        return $response->json('choices.0.message.content', '');
    }

    /**
     * One real call to the endpoint with a short user message — the probe
     * the "test" button runs without persisting anything. The API key
     * travels only in the request to the configured endpoint; it never
     * appears in any payload. No dimension check: the chat model has no
     * dimension constraint — the only point separating this probe from the
     * embeddings one.
     *
     * @return array{content: string, latencyMs: int}
     *
     * @throws RequestException
     * @throws ConnectionException
     */
    public function probe(): array
    {
        $config = $this->config();
        $startedAt = microtime(true);

        $content = Http::withToken($config['api_key'] ?? '')
            ->acceptJson()
            ->post(rtrim((string) $config['base_url'], '/').'/chat/completions', [
                'model' => $config['model'],
                'messages' => [
                    ['role' => 'user', 'content' => 'TaskR'],
                ],
            ])
            ->throw()
            ->json('choices.0.message.content', '');

        return [
            'content' => $content,
            'latencyMs' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }
}
