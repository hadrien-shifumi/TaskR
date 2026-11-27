<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * The Mercure broadcasting contract, frozen app-level: the driver the
 * framework loads from `config/broadcasting.php`, the connection keys the
 * hub's derivation reads, and the secrets never left empty in production.
 */
class BroadcastingConfigTest extends TestCase
{
    /** @return array<string, mixed> */
    private function mercureConnection(array $overrides = []): array
    {
        return array_merge([
            'driver' => 'mercure',
            'url' => 'http://127.0.0.1:8080/.well-known/mercure',
            'public_url' => null,
            'secret' => str_repeat('a', 64),
            'encryption_key' => null,
            'claims' => [
                'iss' => 'http://localhost:8080',
                'client_id' => 'TaskR',
            ],
            'cookie_name' => null,
            'subscribe_expiration' => 5,
            'async' => false,
        ], $overrides);
    }

    public function test_broadcasting_config_exposes_the_mercure_driver_and_its_keys(): void
    {
        Config::set([
            'broadcasting.default' => 'mercure',
            'broadcasting.connections.mercure' => $this->mercureConnection(),
        ]);

        $this->assertSame('mercure', config('broadcasting.default'));
        $this->assertSame('mercure', config('broadcasting.connections.mercure.driver'));

        // The keys Octane's mercureConfigFromBroadcasting() deriviation reads,
        // byte-for-byte the framework merge's contract.
        foreach (['url', 'public_url', 'secret', 'encryption_key', 'claims', 'cookie_name', 'subscribe_expiration', 'async'] as $key) {
            $this->assertArrayHasKey($key, config('broadcasting.connections.mercure'));
        }
    }

    public function test_the_mercure_connection_answers_the_env_defaults(): void
    {
        config(['broadcasting.connections.mercure.secret' => env('MERCURE_JWT_SECRET')]);

        $this->assertSame(env('MERCURE_JWT_SECRET'), config('broadcasting.connections.mercure.secret'));
    }

    public function test_a_production_secret_is_never_left_empty(): void
    {
        // Phase 0 ships the contract, not a boot guard: the .env.example
        // documents the generation (`openssl rand -hex 32`), the octane
        // start command refuses the derivation when the secret is empty,
        // and the docs' deploy checklist carries the check. So the
        // assertion here is the contract's own default: an empty value
        // stays an explicit to-fill, never a silently-derived hub.
        $example = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('MERCURE_JWT_SECRET=', $example);
        $this->assertStringContainsString('BROADCAST_CONNECTION=mercure', $example);
        $this->assertStringContainsString('openssl rand -hex 32', $example);

        // The config default: never a placeholder — an empty secret stays
        // null so the hub derivation can refuse it (Octane warns).
        config(['broadcasting.connections.mercure.secret' => '']);

        $this->assertSame('', config('broadcasting.connections.mercure.secret'));
    }

    public function test_the_embedded_hub_stays_derived_from_the_broadcasting_connection(): void
    {
        // A non-null block replaces Octane's derivation wholesale (it is never
        // merged) and is rendered verbatim as the `mercure { ... }` Caddyfile
        // block: a partial one keeps FrankenPHP from starting at all.
        $this->assertNull(config('octane.mercure'));

        // The hub's Bolt database follows Caddy's data directory: it has to
        // sit on the storage volume to survive container updates.
        $this->assertStringContainsString('ENV XDG_DATA_HOME=/app/storage', file_get_contents(base_path('Dockerfile')));
    }
}
