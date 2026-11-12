<?php

namespace App\Support;

/**
 * Raised when the embeddings endpoint returns vectors whose dimension does
 * not match the configured one (KNOWLEDGE_EMBEDDINGS_DIMENSIONS). Deliberately
 * a runtime error rather than an HTTP one: a mismatch means the vector column
 * and the provider disagree — silently comparing such vectors would corrupt
 * semantic ranking, so both the vectorization job and the search query fail
 * loudly with an actionable message instead.
 */
class EmbeddingDimensionMismatch extends \RuntimeException
{
    public function __construct(int $returned, int $expected)
    {
        parent::__construct(sprintf(
            'The embeddings endpoint returned a %d-dimensional vector while %d dimensions are expected. Set KNOWLEDGE_EMBEDDINGS_DIMENSIONS to match your model, then follow the dimension change procedure in the README (resize the vector column and run php artisan knowledge:reembed).',
            $returned,
            $expected,
        ));
    }
}
