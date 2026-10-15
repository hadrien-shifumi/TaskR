<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Knowledge base embeddings
    |--------------------------------------------------------------------------
    |
    | Any OpenAI-compatible embeddings endpoint works: point base_url at the
    | provider root (e.g. https://api.openai.com/v1), pick a model and set the
    | API key. The vector dimension is fixed at 1536 in the knowledge_pages
    | migration — production runs on PostgreSQL and requires the pgvector
    | extension (vector column + ivfflat cosine index). Changing the model or
    | dimensions requires a new migration and `php artisan knowledge:reembed`.
    |
    */

    'embeddings' => [
        'base_url' => env('KNOWLEDGE_EMBEDDINGS_BASE_URL'),
        'api_key' => env('KNOWLEDGE_EMBEDDINGS_API_KEY'),
        'model' => env('KNOWLEDGE_EMBEDDINGS_MODEL', 'text-embedding-3-small'),
    ],

];
