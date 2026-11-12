<?php

namespace Tests\Unit;

use App\Support\EmbeddingDimensionMismatch;
use App\Support\Embeddings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmbeddingsTest extends TestCase
{
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

    public function test_embeds_input_and_returns_the_vector(): void
    {
        config()->set('knowledge.embeddings', [
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'test-key',
            'model' => 'text-embedding-3-small',
            'dimensions' => 3,
        ]);

        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [
                    ['embedding' => [0.1, -0.2, 0.3]],
                ],
            ]),
        ]);

        $vector = app(Embeddings::class)->embed('Processus de règlement');

        $this->assertSame([0.1, -0.2, 0.3], $vector);

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('Authorization', 'Bearer test-key')
                && $request['model'] === 'text-embedding-3-small'
                && $request['input'] === ['Processus de règlement'];
        });
    }

    public function test_http_error_propagates(): void
    {
        config()->set('knowledge.embeddings', [
            'base_url' => 'https://embeddings.test/v1',
            'api_key' => 'test-key',
            'model' => 'text-embedding-3-small',
            'dimensions' => 3,
        ]);

        Http::fake([
            'embeddings.test/v1/embeddings' => Http::response(['error' => 'boom'], 500),
        ]);

        $this->expectException(\Exception::class);

        app(Embeddings::class)->embed('anything');
    }

    public function test_embed_raises_on_dimension_mismatch(): void
    {
        config()->set('knowledge.embeddings', [
            'base_url' => 'https://embeddings.test/v1',
            'api_key' => 'test-key',
            'model' => 'text-embedding-3-small',
            'dimensions' => 3,
        ]);

        Http::fake([
            'embeddings.test/v1/embeddings' => Http::response([
                'data' => [
                    ['embedding' => [0.1, 0.2]],
                ],
            ]),
        ]);

        try {
            app(Embeddings::class)->embed('anything');

            $this->fail('EmbeddingDimensionMismatch was not thrown.');
        } catch (EmbeddingDimensionMismatch $exception) {
            $this->assertStringContainsString('2', $exception->getMessage());
            $this->assertStringContainsString('3', $exception->getMessage());
            $this->assertStringContainsString('KNOWLEDGE_EMBEDDINGS_DIMENSIONS', $exception->getMessage());
        }
    }

    public function test_embed_accepts_vector_matching_configured_dimension(): void
    {
        config()->set('knowledge.embeddings', [
            'base_url' => 'https://embeddings.test/v1',
            'api_key' => 'test-key',
            'model' => 'text-embedding-3-small',
            'dimensions' => 2,
        ]);

        Http::fake([
            'embeddings.test/v1/embeddings' => Http::response([
                'data' => [
                    ['embedding' => [0.1, 0.2]],
                ],
            ]),
        ]);

        $vector = app(Embeddings::class)->embed('Processus de règlement');

        $this->assertSame([0.1, 0.2], $vector);
    }

    public function test_enabled_reflects_configuration(): void
    {
        config()->set('knowledge.embeddings', [
            'base_url' => null,
            'api_key' => null,
            'model' => 'text-embedding-3-small',
            'dimensions' => 1024,
        ]);

        $this->assertFalse(app(Embeddings::class)->enabled());

        config()->set('knowledge.embeddings', [
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'test-key',
            'model' => null,
            'dimensions' => 1024,
        ]);

        $this->assertFalse(app(Embeddings::class)->enabled());

        config()->set('knowledge.embeddings', [
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'test-key',
            'model' => 'text-embedding-3-small',
            'dimensions' => 1024,
        ]);

        $this->assertTrue(app(Embeddings::class)->enabled());
    }
}
