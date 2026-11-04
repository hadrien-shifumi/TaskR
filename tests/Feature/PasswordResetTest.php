<?php

namespace Tests\Feature;

use App\Mail\ResetPasswordMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Password reset flow: request a link by e-mail, use it once within one hour
 * to define a new password, every session of the account invalidated. The
 * request response is always generic — no account existence disclosure.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_page_renders_for_guests(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/ForgotPassword'));
    }

    public function test_forgot_password_sends_reset_link_for_known_email(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status');

        Mail::assertSent(ResetPasswordMail::class, fn (ResetPasswordMail $mail) => $mail->hasTo($user->email));
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_forgot_password_response_is_generic_for_unknown_email(): void
    {
        Mail::fake();
        $known = User::factory()->create();

        $knownStatus = $this->post(route('password.email'), ['email' => $known->email])
            ->assertSessionHas('status')
            ->getSession()->get('status');

        $unknownStatus = $this->post(route('password.email'), ['email' => 'nobody@example.com'])
            ->assertSessionHas('status')
            ->getSession()->get('status');

        $this->assertSame($knownStatus, $unknownStatus);
        Mail::assertSent(ResetPasswordMail::class, 1);
    }

    public function test_forgot_password_validates_email_format(): void
    {
        Mail::fake();

        $this->post(route('password.email'), ['email' => 'not-an-email'])
            ->assertSessionHasErrors('email');

        Mail::assertNothingSent();
    }

    public function test_reset_password_page_renders_with_token_and_email(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->get(route('password.reset', ['token' => $token]).'?email='.$user->email)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/ResetPassword')
                ->where('token', $token)
                ->where('email', $user->email));
    }

    public function test_reset_password_updates_password_within_one_hour(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->travelTo(now()->addMinutes(59));

        $this->followingRedirects()
            ->from(route('password.reset', ['token' => $token]))
            ->post(route('password.update'), [
                'token' => $token,
                'email' => $user->email,
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('new-password-1', $user->refresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_reset_password_rejects_expired_token(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $oldHash = $user->password;

        $this->travelTo(now()->addMinutes(61));

        $this->from(route('password.reset', ['token' => $token]))
            ->post(route('password.update'), [
                'token' => $token,
                'email' => $user->email,
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame($oldHash, $user->refresh()->password);
    }

    public function test_reset_password_is_single_use(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('new-password-1', $user->refresh()->password));

        $this->from(route('password.reset', ['token' => $token]))
            ->post(route('password.update'), [
                'token' => $token,
                'email' => $user->email,
                'password' => 'second-password',
                'password_confirmation' => 'second-password',
            ])
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('new-password-1', $user->refresh()->password));
    }

    public function test_reset_password_rejects_invalid_token(): void
    {
        $user = User::factory()->create();
        Password::createToken($user);
        $oldHash = $user->password;

        $this->from(route('password.reset', ['token' => 'garbage-token']))
            ->post(route('password.update'), [
                'token' => 'garbage-token',
                'email' => $user->email,
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame($oldHash, $user->refresh()->password);
    }

    public function test_reset_password_requires_min_8_and_confirmation(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'short1',
            'password_confirmation' => 'short1',
        ])->assertSessionHasErrors('password');

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'long-enough-1',
            'password_confirmation' => 'different-1',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_reset_password_invalidates_all_sessions(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        foreach (['session-a', 'session-b'] as $sessionId) {
            DB::table('sessions')->insert([
                'id' => $sessionId,
                'user_id' => $user->id,
                'payload' => base64_encode('payload'),
                'last_activity' => now()->timestamp,
            ]);
        }

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertRedirect(route('login'));

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_reset_password_rejects_unknown_email(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->from(route('password.reset', ['token' => $token]))
            ->post(route('password.update'), [
                'token' => $token,
                'email' => 'nobody@example.com',
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }
}
