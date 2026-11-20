<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | This option controls the default broadcaster that will be used by the
    | framework when an event needs to be broadcast. You may set this to
    | any of the connections defined in the "connections" array below.
    |
    | Supported: "reverb", "pusher", "ably", "mercure", "redis", "log", "null"
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the broadcast connections that will be used
    | to broadcast events to other systems or over WebSockets. Samples of
    | each available type of connection are provided inside this array.
    |
    | The "mercure" connection targets the hub FrankenPHP embeds: the TTL of
    | its subscribe tokens follows `subscribe_expiration` (minutes), the
    | publisher and subscriber secrets default to `secret` — pass explicit
    | `MERCURE_JWT_SECRET` (64 hex characters — `openssl rand -hex 32`) in
    | production, and the browser carries one only for as long as a
    | subscriber is authorized.
    |
    */

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
        ],

        // THE contract: frozen from the framework's own merge — every key
        // Octane's mercureConfigFromBroadcasting() reads. Changing a key's
        // shape here silently breaks the hub derivation, so it stays
        // byte-for-byte the framework's shape.
        'mercure' => [
            'driver' => 'mercure',
            'url' => env('MERCURE_URL'),
            'public_url' => env('MERCURE_PUBLIC_URL'),
            'secret' => env('MERCURE_JWT_SECRET'),
            'encryption_key' => env('MERCURE_ENCRYPTION_KEY'),
            'claims' => [
                'iss' => env('MERCURE_JWT_ISSUER'),
                'client_id' => env('APP_NAME'),
            ],
            'cookie_name' => env('MERCURE_COOKIE_NAME'),
            'subscribe_expiration' => (int) env('MERCURE_SUBSCRIBE_EXPIRATION', 5),
            'async' => (bool) env('MERCURE_ASYNC', false),
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
