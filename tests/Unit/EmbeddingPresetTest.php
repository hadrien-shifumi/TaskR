<?php

namespace Tests\Unit;

use App\Enums\EmbeddingPreset;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * EmbeddingPreset — provider presets documented in .env.example and the
 * README: each known provider carries its endpoint, model and vector
 * dimension so the installer only has to ask for the API key.
 */
class EmbeddingPresetTest extends TestCase
{
    public static function providerPresetDefaults(): array
    {
        return [
            'openai' => ['openai', 'https://api.openai.com/v1', 'text-embedding-3-small', 1536, null],
            'ollama' => ['ollama', 'http://localhost:11434/v1', 'nomic-embed-text', 768, 'ollama'],
            'mistral' => ['mistral', 'https://api.mistral.ai/v1', 'mistral-embed', 1024, null],
        ];
    }

    #[Test]
    #[DataProvider('providerPresetDefaults')]
    public function provider_presets_carry_the_documented_defaults(
        string $case,
        ?string $baseUrl,
        ?string $model,
        ?int $dimensions,
        ?string $defaultApiKey,
    ): void {
        $preset = EmbeddingPreset::from($case);

        $this->assertSame($baseUrl, $preset->baseUrl());
        $this->assertSame($model, $preset->model());
        $this->assertSame($dimensions, $preset->dimensions());
        $this->assertSame($defaultApiKey, $preset->defaultApiKey());
    }

    #[Test]
    public function none_and_custom_presets_carry_no_defaults(): void
    {
        foreach ([EmbeddingPreset::None, EmbeddingPreset::Custom] as $preset) {
            $this->assertNull($preset->baseUrl());
            $this->assertNull($preset->model());
            $this->assertNull($preset->dimensions());
            $this->assertNull($preset->defaultApiKey());
        }
    }

    #[Test]
    public function choices_list_every_preset_with_a_label(): void
    {
        $choices = EmbeddingPreset::choices();

        $this->assertSame(array_map(
            static fn (EmbeddingPreset $preset) => $preset->value,
            EmbeddingPreset::cases(),
        ), array_keys($choices));

        foreach ($choices as $label) {
            $this->assertNotSame('', $label);
        }

        $this->assertSame('None', EmbeddingPreset::choices()['none']);
    }
}
