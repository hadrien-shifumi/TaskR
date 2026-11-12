<?php

namespace App\Enums;

/**
 * Embeddings provider presets for the knowledge base semantic search, as
 * documented in .env.example: each known provider carries its OpenAI-
 * compatible endpoint, model and vector dimension so the installer only has
 * to ask for the API key. None disables semantic search; Custom asks for
 * every setting (vLLM, LM Studio, or any other compatible endpoint).
 */
enum EmbeddingPreset: string
{
    case None = 'none';
    case OpenAI = 'openai';
    case Ollama = 'ollama';
    case Mistral = 'mistral';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::OpenAI => 'OpenAI',
            self::Ollama => 'Ollama (local)',
            self::Mistral => 'Mistral',
            self::Custom => 'Custom endpoint (vLLM, LM Studio…)',
        };
    }

    /**
     * Provider endpoint root, without a trailing slash, or null for
     * None/Custom (Custom asks the user for it).
     */
    public function baseUrl(): ?string
    {
        return match ($this) {
            self::OpenAI => 'https://api.openai.com/v1',
            self::Ollama => 'http://localhost:11434/v1',
            self::Mistral => 'https://api.mistral.ai/v1',
            self::None, self::Custom => null,
        };
    }

    public function model(): ?string
    {
        return match ($this) {
            self::OpenAI => 'text-embedding-3-small',
            self::Ollama => 'nomic-embed-text',
            self::Mistral => 'mistral-embed',
            self::None, self::Custom => null,
        };
    }

    /**
     * Dimension the preset model returns — the pgvector column is sized from
     * it at migration time, so the installer writes it before any migrate.
     */
    public function dimensions(): ?int
    {
        return match ($this) {
            self::OpenAI => 1536,
            self::Ollama => 768,
            self::Mistral => 1024,
            self::None, self::Custom => null,
        };
    }

    /**
     * Placeholder key for local providers that ignore auth (the embeddings
     * client requires a non-empty key to consider itself configured), or
     * null when the user must supply one.
     */
    public function defaultApiKey(): ?string
    {
        return match ($this) {
            self::Ollama => 'ollama',
            self::None, self::OpenAI, self::Mistral, self::Custom => null,
        };
    }

    /**
     * Choice list for the installer: preset value => display label.
     *
     * @return array<string, string>
     */
    public static function choices(): array
    {
        $choices = [];

        foreach (self::cases() as $case) {
            $choices[$case->value] = $case->label();
        }

        return $choices;
    }
}
