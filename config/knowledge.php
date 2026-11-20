<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Knowledge base embeddings (instance-wide default)
    |--------------------------------------------------------------------------
    |
    | Any OpenAI-compatible embeddings endpoint works: point base_url at the
    | provider root (e.g. https://api.openai.com/v1), pick a model and set
    | the API key. The vector dimension must match what the chosen model
    | returns — set KNOWLEDGE_EMBEDDINGS_DIMENSIONS BEFORE running
    | `php artisan migrate`: production runs on PostgreSQL and sizes the
    | pgvector column (vector(N) + ivfflat cosine index) from it. Changing
    | the model or dimensions on an existing instance requires the resize
    | procedure documented in the README plus `php artisan knowledge:reembed`.
    |
    | This is the fallback for every workspace without its own provider:
    | workspace owners configure theirs from Settings → Semantic search
    | (workspace_embedding_settings, one row per workspace), under the same
    | fixed instance dimension.
    |
    */

    'embeddings' => [
        'base_url' => env('KNOWLEDGE_EMBEDDINGS_BASE_URL'),
        'api_key' => env('KNOWLEDGE_EMBEDDINGS_API_KEY'),
        'model' => env('KNOWLEDGE_EMBEDDINGS_MODEL'),
        'dimensions' => (int) env('KNOWLEDGE_EMBEDDINGS_DIMENSIONS', 1024),
    ],

];
