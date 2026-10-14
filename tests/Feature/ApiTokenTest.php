<?php

namespace Tests\Feature;

use App\Actions\Auth\CreateApiToken;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_tokens_page_lists_own_tokens(): void
    {
        $user = User::factory()->create();
        CreateWorkspace::run($user, ['name' => 'WS']);
        CreateApiToken::run($user, ['name' => 'Agent Claude']);

        $this->actingAs($user)
            ->get(route('settings.tokens'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/Tokens')
                ->has('tokens', 1)
                ->where('tokens.0.name', 'Agent Claude'));
    }

    public function test_created_token_value_is_shown_once_and_never_stored_in_clear(): void
    {
        $user = User::factory()->create();
        CreateWorkspace::run($user, ['name' => 'WS']);

        $response = $this->actingAs($user)
            ->post(route('tokens.store'), ['name' => 'Agent Cursor'])
            ->assertRedirect(route('settings.tokens'));

        $newToken = $response->getSession()->get('newToken');
        $this->assertIsString($newToken);
        $this->assertNotSame('Agent Cursor', $newToken);

        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'Agent Cursor']);
        $this->assertDatabaseMissing('personal_access_tokens', ['token' => $newToken]);

        // The clear value is flashed once: the very next page load exposes it
        // for the dialog, and it disappears on the following one.
        $this->actingAs($user)
            ->get(route('settings.tokens'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('flash.newToken', $newToken));

        $this->actingAs($user)
            ->get(route('settings.tokens'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('flash.newToken', null));
    }

    public function test_owner_can_revoke_own_token(): void
    {
        $user = User::factory()->create();
        CreateWorkspace::run($user, ['name' => 'WS']);
        CreateApiToken::run($user, ['name' => 'Agent Claude']);

        $tokenId = $user->tokens()->firstOrFail()->id;

        $this->actingAs($user)
            ->delete(route('tokens.destroy', $tokenId))
            ->assertRedirect(route('settings.tokens'));

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    public function test_user_cannot_revoke_token_of_another_user(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        CreateWorkspace::run($alice, ['name' => 'WS']);
        CreateApiToken::run($alice, ['name' => 'Agent Claude']);

        $tokenId = $alice->tokens()->firstOrFail()->id;

        $this->actingAs($bob)
            ->delete(route('tokens.destroy', $tokenId))
            ->assertNotFound();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenId]);
    }

    public function test_revoked_token_is_refused_on_mcp_endpoint(): void
    {
        $user = User::factory()->create();
        CreateWorkspace::run($user, ['name' => 'WS']);

        $newToken = CreateApiToken::run($user, ['name' => 'Agent Claude']);
        $plain = explode('|', $newToken->plainTextToken, 2)[1];

        $this->postJson('/mcp', $this->initializeBody(), ['Authorization' => "Bearer {$plain}"])
            ->assertOk();

        $user->tokens()->each(fn ($token) => $token->delete());

        $this->postJson('/mcp', $this->initializeBody(), ['Authorization' => "Bearer {$plain}"])
            ->assertUnauthorized();
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
}
