<?php

namespace Tests\Feature;

use App\Actions\Auth\CreateApiToken;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Password change from Settings → Account: the current password is verified
 * before applying the change, the new one follows the registration rule
 * (min 8 + confirmation), and neither the session nor the API tokens are
 * invalidated — unlike the "forgot password" reset.
 */
class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_account_settings_page_renders_for_authenticated_users(): void
    {
        $user = User::factory()->create();
        CreateWorkspace::run($user, ['name' => 'WS']);

        $this->actingAs($user)
            ->get(route('settings.account'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Settings/Account'));
    }

    public function test_guests_are_redirected_to_login_from_the_account_page(): void
    {
        $this->get(route('settings.account'))
            ->assertRedirect(route('login', absolute: false));
    }

    public function test_authenticated_users_can_change_their_password(): void
    {
        $user = User::factory()->create(['password' => 'current-password-1']);
        CreateWorkspace::run($user, ['name' => 'WS']);

        $this->actingAs($user)
            ->from(route('settings.account'))
            ->put(route('user.password.update'), [
                'current_password' => 'current-password-1',
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ])
            ->assertRedirect(route('settings.account'))
            ->assertSessionHas('status');

        $fresh = $user->refresh();
        $this->assertTrue(Hash::check('new-password-1', $fresh->password));
        $this->assertFalse(Hash::check('current-password-1', $fresh->password));
    }

    public function test_the_user_stays_logged_in_and_can_log_back_in_with_the_new_password(): void
    {
        $user = User::factory()->create(['password' => 'current-password-1']);
        CreateWorkspace::run($user, ['name' => 'WS']);

        $this->actingAs($user)
            ->put(route('user.password.update'), [
                'current_password' => 'current-password-1',
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ])->assertRedirect(route('settings.account'));

        // The change must not invalidate the current session.
        $this->actingAs($user)->get(route('settings.account'))->assertOk();

        // And the account must reauthenticate with the new password only.
        $this->post(route('logout'));

        $this->post(route('login.attempt'), [
            'email' => $user->email,
            'password' => 'new-password-1',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_current_password_is_rejected_with_a_dedicated_error(): void
    {
        $user = User::factory()->create(['password' => 'current-password-1']);
        CreateWorkspace::run($user, ['name' => 'WS']);

        $this->actingAs($user)
            ->from(route('settings.account'))
            ->put(route('user.password.update'), [
                'current_password' => 'wrong-current-1',
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ])
            ->assertRedirect(route('settings.account'))
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('current-password-1', $user->refresh()->password));
    }

    public function test_the_new_password_requires_eight_characters_and_confirmation(): void
    {
        $user = User::factory()->create(['password' => 'current-password-1']);
        CreateWorkspace::run($user, ['name' => 'WS']);

        $this->actingAs($user)
            ->from(route('settings.account'))
            ->put(route('user.password.update'), [
                'current_password' => 'current-password-1',
                'password' => 'short1',
                'password_confirmation' => 'short1',
            ])
            ->assertSessionHasErrors('password');

        $this->actingAs($user)
            ->put(route('user.password.update'), [
                'current_password' => 'current-password-1',
                'password' => 'long-enough-1',
                'password_confirmation' => 'different-1',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('current-password-1', $user->refresh()->password));
    }

    public function test_the_current_password_is_required(): void
    {
        $user = User::factory()->create(['password' => 'current-password-1']);
        CreateWorkspace::run($user, ['name' => 'WS']);

        $this->actingAs($user)
            ->put(route('user.password.update'), [
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('current-password-1', $user->refresh()->password));
    }

    public function test_api_tokens_survive_a_password_change(): void
    {
        $user = User::factory()->create(['password' => 'current-password-1']);
        CreateWorkspace::run($user, ['name' => 'WS']);
        $token = CreateApiToken::run($user, ['name' => 'Agent Claude']);
        $plain = explode('|', $token->plainTextToken, 2)[1];

        $this->actingAs($user)
            ->put(route('user.password.update'), [
                'current_password' => 'current-password-1',
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ])->assertRedirect(route('settings.account'));

        $this->postJson('/mcp', $this->initializeBody(), ['Authorization' => "Bearer {$plain}"])
            ->assertOk();
    }

    public function test_guests_cannot_change_their_password(): void
    {
        $this->put(route('user.password.update'), [
            'current_password' => 'current-password-1',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertRedirect(route('login', absolute: false));
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
