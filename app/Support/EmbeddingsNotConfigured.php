<?php

namespace App\Support;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Raised when semantic search runs without an embeddings endpoint
 * configured. Deliberately distinct from validation and authorization
 * failures: writing and browsing keep working, only search degrades —
 * callers surface an actionable configuration message instead of an
 * silently empty result set.
 */
class EmbeddingsNotConfigured extends HttpException
{
    public function __construct(
        string $message = 'Semantic search is not configured. Set KNOWLEDGE_EMBEDDINGS_BASE_URL, KNOWLEDGE_EMBEDDINGS_API_KEY, KNOWLEDGE_EMBEDDINGS_MODEL and KNOWLEDGE_EMBEDDINGS_DIMENSIONS to enable it.',
    ) {
        parent::__construct(422, $message);
    }
}
