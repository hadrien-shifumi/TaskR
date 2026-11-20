<?php

namespace App\Support;

use App\Models\Workspace;
use App\Models\WorkspaceEmbeddingSetting;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Minimal client for an OpenAI-compatible embeddings endpoint
 * (POST {base_url}/embeddings, request `input: string[]`, response
 * `data[].embedding`). Works with OpenAI, Ollama, vLLM, Mistral and
 * most providers.
 *
 * The container-resolved instance (no constructor arguments) reads the
 * global instance configuration (`KNOWLEDGE_EMBEDDINGS_*` env keys, see
 * config/knowledge.php). Per-workspace overrides take precedence:
 * `Embeddings::forWorkspace($workspace)` (or `forWorkspaceId($ulid)`)
 * returns a client bound to the workspace's own provider when its owner
 * configured one, and a global-config client otherwise.
 */
class Embeddings
{
    /**
     * Explicit configuration, when this client was resolved for a
     * specific workspace. Null reads the global instance config.
     *
     * @var array{base_url: ?string, api_key: ?string, model: ?string, dimensions: int}|null
     */
    protected ?array $config;

    /**
     * Whether `dimensions` is sent in the request body. Only an explicit
     * per-workspace opt-in turns it on: strict providers reject unknown
     * params, so the global instance config never sends it — its
     * `dimensions` setting only states the expected vector size.
     */
    protected bool $sendDimensions;

    /**
     * Whether this instance was resolved from a workspace's stored
     * settings (as opposed to the global configuration).
     */
    protected bool $fromWorkspaceSettings = false;

    /**
     * @param  array{base_url: ?string, api_key: ?string, model: ?string, dimensions: ?int}|null  $config
     */
    public function __construct(?array $config = null, bool $sendDimensions = false)
    {
        $this->config = $config;
        $this->sendDimensions = $sendDimensions;
    }

    /**
     * Client for the workspace's effective provider: its own configured
     * provider when one exists, the global instance configuration
     * otherwise. Must stay usable outside HTTP (queued vectorization,
     * commands): the stored settings are looked up by workspace id,
     * explicitly out of any request-scoped tenant context.
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

        $client = new static([
            'base_url' => $setting->base_url,
            'api_key' => $setting->api_key,
            'model' => $setting->model,
            'dimensions' => (int) $setting->dimensions,
        ], (bool) $setting->send_dimensions);
        $client->fromWorkspaceSettings = true;

        return $client;
    }

    /**
     * Whether this client was resolved from a workspace's stored
     * settings — consumers use it to report which provider is in use.
     */
    public function fromWorkspaceSettings(): bool
    {
        return $this->fromWorkspaceSettings;
    }

    /**
     * Human-facing summary of the effective provider: which source is
     * active (the workspace's own settings, the instance-wide
     * configuration, or nothing yet) and its non-secret settings. The API
     * key never appears — only whether one is set.
     *
     * @return array{source: string, baseUrl: ?string, model: ?string, dimensions: ?int, hasApiKey: bool}
     */
    public function describe(): array
    {
        $config = $this->config();

        return [
            'source' => $this->fromWorkspaceSettings
                ? 'workspace'
                : ($this->enabled() ? 'global' : 'none'),
            'baseUrl' => $config['base_url'],
            'model' => $config['model'],
            'dimensions' => (int) ($config['dimensions'] ?? 1024) ?: null,
            'hasApiKey' => $config['api_key'] !== null && $config['api_key'] !== '',
        ];
    }

    /**
     * @return array{base_url: ?string, api_key: ?string, model: ?string, dimensions: int}
     */
    protected function config(): array
    {
        if ($this->config !== null) {
            return $this->config + ['dimensions' => 1024];
        }

        /** @var array{base_url: ?string, api_key: ?string, model: ?string, dimensions: int} $config */
        $config = config('knowledge.embeddings', []);

        return $config;
    }

    /**
     * Whether the API key should travel in the request body — the
     * per-workspace explicit opt-in only, see $sendDimensions.
     */
    protected function dimensionsInBody(): bool
    {
        return $this->sendDimensions;
    }

    /**
     * Semantic search is usable only when all four embeddings settings are
     * provided: the endpoint URL, its API key, the model and the vector
     * dimension that model returns.
     */
    public function enabled(): bool
    {
        $config = $this->config();

        return $config['base_url'] !== null && $config['base_url'] !== ''
            && $config['api_key'] !== null && $config['api_key'] !== ''
            && $config['model'] !== null && $config['model'] !== ''
            && (int) ($config['dimensions'] ?? 1024) > 0;
    }

    /**
     * Embed a single input and return its vector.
     *
     * @return array<int, float>
     *
     * @throws RequestException
     * @throws EmbeddingDimensionMismatch
     */
    public function embed(string $input): array
    {
        return $this->embedMany([$input])[0];
    }

    /**
     * Embed several inputs in one endpoint call. The `dimensions` setting only
     * states what the model returns, and every returned vector is checked
     * against it so inconsistent vectors never enter the pipeline. It is sent
     * in the request body only under a per-workspace explicit opt-in — strict
     * providers reject unknown params (see $sendDimensions).
     *
     * @param  array<int, string>  $inputs
     * @return array<int, array<int, float>>
     *
     * @throws RequestException
     * @throws EmbeddingDimensionMismatch
     */
    public function embedMany(array $inputs): array
    {
        $config = $this->config();

        $body = [
            'model' => $config['model'],
            'input' => array_values($inputs),
        ];

        if ($this->dimensionsInBody()) {
            $body['dimensions'] = (int) ($config['dimensions'] ?? 1024);
        }

        $response = Http::withToken($config['api_key'] ?? '')
            ->acceptJson()
            ->post(rtrim((string) $config['base_url'], '/').'/embeddings', $body)
            ->throw();

        /** @var array<int, array{embedding: array<int, float>}> $data */
        $data = $response->json('data', []);

        $expected = (int) ($config['dimensions'] ?? 1024);

        return array_map(
            function (array $item) use ($expected): array {
                $vector = array_map(floatval(...), $item['embedding']);

                if (count($vector) !== $expected) {
                    throw new EmbeddingDimensionMismatch(count($vector), $expected);
                }

                return $vector;
            },
            $data,
        );
    }

    /**
     * One real call to the endpoint with a short probe string. Unlike
     * embed()/embedMany(), the returned vector is NOT checked against the
     * expected dimension: the save-time flow compares the raw observed
     * length itself so it can report a precise, actionable refusal, and the
     * settings test button can show what the provider actually produces.
     *
     * @return array{dimensions: int, latencyMs: int}
     *
     * @throws RequestException
     */
    public function probe(): array
    {
        $config = $this->config();
        $startedAt = microtime(true);

        $body = [
            'model' => $config['model'],
            'input' => ['TaskR'],
        ];

        if ($this->dimensionsInBody()) {
            $body['dimensions'] = (int) ($config['dimensions'] ?? 1024);
        }

        /** @var array<int, array{embedding: array<int, float>}> $data */
        $data = Http::withToken($config['api_key'] ?? '')
            ->acceptJson()
            ->post(rtrim((string) $config['base_url'], '/').'/embeddings', $body)
            ->throw()
            ->json('data', []);

        $vector = $data[0]['embedding'] ?? [];

        return [
            'dimensions' => count($vector),
            'latencyMs' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }
}
