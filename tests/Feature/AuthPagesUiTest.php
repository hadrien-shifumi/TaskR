<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * UI contract of the password and invitation flows: the reset journey ends on
 * the login page carrying the (generic) confirmation flash, the forgot-password
 * page shares the same flash channel, and the user-facing copy of the reset
 * page is declared in both supported locales.
 */
class AuthPagesUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_reset_flow_ends_on_the_login_page_carrying_the_confirmation_flash(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->followingRedirects()
            ->post(route('password.update'), [
                'token' => $token,
                'email' => $user->email,
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/Login')
                ->where('flash.status', __('flash.password.reset_done')));
    }

    public function test_the_forgot_password_page_shares_the_generic_status_flash(): void
    {
        $this->withSession(['status' => __('flash.password.reset_link_sent')])
            ->get(route('password.request'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('flash.status', __('flash.password.reset_link_sent')));
    }

    public function test_the_reset_password_hint_is_declared_in_both_locales(): void
    {
        foreach (['en', 'fr'] as $locale) {
            $contents = file_get_contents(resource_path("js/i18n/{$locale}.ts"));

            $this->assertMatchesRegularExpression(
                '/reset:\s*\{[^}]*passwordHint/s',
                $contents,
                "The auth.reset.passwordHint key is missing from {$locale}.ts.",
            );
        }
    }
}
