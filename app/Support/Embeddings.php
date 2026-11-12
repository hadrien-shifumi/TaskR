<?php

namespace App\Support;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Minimal client for an OpenAI-compatible embeddings endpoint
 * (POST {base_url}/embeddings, request `input: string[]`, response
 * `data[].embedding`). Works with OpenAI, Ollama, vLLM, Mistral and
 * most providers — configure via KNOWLEDGE_EMBEDDINGS_* env keys, see
 * config/knowledge.php.
 */
class Embeddings
{
    /**
     * @return array{base_url: ?string, api_key: ?string, model: ?string, dimensions: int}
     */
    protected function config(): array
    {
        /** @var array{base_url: ?string, api_key: ?string, model: ?string, dimensions: int} $config */
        $config = config('knowledge.embeddings', []);

        return $config;
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
     * Embed several inputs in one endpoint call. The `dimensions` setting is
     * never sent to the provider (strict providers reject unknown params) —
     * it only states what the model returns, and every returned vector is
     * checked against it so inconsistent vectors never enter the pipeline.
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

        $response = Http::withToken($config['api_key'] ?? '')
            ->acceptJson()
            ->post(rtrim((string) $config['base_url'], '/').'/embeddings', [
                'model' => $config['model'],
                'input' => array_values($inputs),
            ])
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
}
