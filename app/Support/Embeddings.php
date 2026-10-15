<?php

namespace App\Support;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Minimal client for an OpenAI-compatible embeddings endpoint
 * (POST {base_url}/embeddings, request `input: string[]`, response
 * `data[].embedding`). Works with OpenAI, Ollama and most providers —
 * configure via KNOWLEDGE_EMBEDDINGS_* env keys, see config/knowledge.php.
 */
class Embeddings
{
    /**
     * @return array{base_url: ?string, api_key: ?string, model: ?string}
     */
    protected function config(): array
    {
        /** @var array{base_url: ?string, api_key: ?string, model: ?string} $config */
        $config = config('knowledge.embeddings', []);

        return $config;
    }

    public function enabled(): bool
    {
        $config = $this->config();

        return $config['base_url'] !== null && $config['base_url'] !== ''
            && $config['api_key'] !== null && $config['api_key'] !== '';
    }

    /**
     * Embed a single input and return its vector.
     *
     * @return array<int, float>
     *
     * @throws RequestException
     */
    public function embed(string $input): array
    {
        return $this->embedMany([$input])[0];
    }

    /**
     * Embed several inputs in one endpoint call.
     *
     * @param  array<int, string>  $inputs
     * @return array<int, array<int, float>>
     *
     * @throws RequestException
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

        return array_map(
            fn (array $item): array => array_map(floatval(...), $item['embedding']),
            $data,
        );
    }
}
