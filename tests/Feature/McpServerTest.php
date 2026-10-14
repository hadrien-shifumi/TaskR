<?php

namespace Tests\Feature;

use App\Actions\Auth\CreateApiToken;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_initialize_succeeds_with_valid_token(): void
    {
        $token = $this->createBearerToken();

        $response = $this->postJson('/mcp', $this->initializeBody(), $this->headers($token))
            ->assertOk()
            ->assertJsonPath('jsonrpc', '2.0')
            ->assertJsonPath('id', 1);

        $result = $response->json('result');

        $this->assertSame('2025-11-25', $result['protocolVersion']);
        $this->assertArrayHasKey('tools', $result['capabilities']);
        $this->assertArrayHasKey('resources', $result['capabilities']);
        $this->assertArrayHasKey('serverInfo', $result);
    }

    public function test_call_without_token_is_refused_and_returns_no_data(): void
    {
        $this->postJson('/mcp', $this->initializeBody())
            ->assertUnauthorized();
    }

    public function test_call_with_malformed_token_is_refused(): void
    {
        $this->postJson('/mcp', $this->initializeBody(), $this->headers('not-a-real-token'))
            ->assertUnauthorized();
    }

    public function test_non_mcp_request_is_rejected_with_protocol_error(): void
    {
        $token = $this->createBearerToken();

        $response = $this->call('POST', '/mcp', [], [], [], $this->serverVars($token), 'not-json');
        $response->assertStatus(400);

        $this->assertSame(-32700, $response->json('error.code'));
    }

    public function test_get_on_mcp_endpoint_is_rejected(): void
    {
        $token = $this->createBearerToken();

        $this->get('/mcp', $this->headers($token))
            ->assertStatus(405);
    }

    private function createBearerToken(): string
    {
        $user = User::factory()->create();
        CreateWorkspace::run($user, ['name' => 'WS']);

        $newToken = CreateApiToken::run($user, ['name' => 'tests']);

        return explode('|', $newToken->plainTextToken, 2)[1];
    }

    /**
     * @return array<string, mixed>
     */
    private function initializeBody(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => new \stdClass,
                'clientInfo' => ['name' => 'tests', 'version' => '1.0'],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    /**
     * @return array<string, string>
     */
    private function serverVars(string $token): array
    {
        return [
            'HTTP_AUTHORIZATION' => "Bearer {$token}",
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];
    }
}
