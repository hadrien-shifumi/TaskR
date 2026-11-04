<?php

use App\Mail\ResetPasswordMail;
use App\Models\Invitation;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Security round trips seen from the browser: the forgot-password loop up to
 * the reconnection with the new password, the password change from the
 * account settings, and the refusal of an invitation opened by another
 * account. Acceptance scenarios of already-shipped behaviours: they must pass
 * as written, a red run means the flow and the UI disagree.
 */
it('walks the full forgot-password loop up to the reconnection', function () {
    [$owner] = setupBrowserWorkspace();
    Mail::fake();

    $page = visit('/forgot-password');

    $page->fill('email', $owner->email)
        ->click('[data-test="forgot-submit"]');

    // The answer is deliberately generic: it must not reveal whether the
    // account exists.
    $page->assertSeeIn('[data-test="forgot-flash"]', 'If an account exists for this e-mail address, a reset link has been sent. It is valid for one hour.')
        ->assertNoSmoke();

    Mail::assertSent(ResetPasswordMail::class, fn (ResetPasswordMail $mail) => $mail->hasTo($owner->email));

    $token = null;
    Mail::assertSent(ResetPasswordMail::class, function (ResetPasswordMail $mail) use (&$token): bool {
        $token = $mail->token;

        return true;
    });

    $page = visit("/reset-password/{$token}?email={$owner->email}");

    $page->assertSee('Choose a new password')
        ->fill('password', 'brand-new-password')
        ->fill('password_confirmation', 'brand-new-password')
        ->click('[data-test="reset-submit"]');

    // The reset closes every session: the user lands on the login page with
    // the confirmation message.
    $page->assertPathIs('/login')
        ->assertSeeIn('[data-test="login-flash"]', 'Your password has been updated. You can now log in with your new password.');

    // The old password is dead.
    $page->fill('email', $owner->email)
        ->fill('password', 'password')
        ->click('button[type="submit"]');

    $page->assertSee('These credentials do not match our records.');

    // The new password opens the workspace.
    $page->fill('email', $owner->email)
        ->fill('password', 'brand-new-password')
        ->click('button[type="submit"]');

    $page->assertPathIs('/')
        ->assertSee('WS')
        ->assertNoSmoke();
});

it('changes the password from the account settings and keeps the session', function () {
    [$owner] = setupBrowserWorkspace();
    $owner->update(['name' => 'Alice']);

    $page = visit('/login')
        ->fill('email', $owner->email)
        ->fill('password', 'password')
        ->click('button[type="submit"]');

    $page->assertPathIs('/');

    $page = visit('/settings/account');

    // The permanent advice banner: tokens survive a password change and must
    // be revoked by hand when the account is compromised.
    $page->assertSeeIn('[data-test="account-security-hint"]', 'If you suspect your account has been compromised, also revoke your API tokens:');

    // A wrong current password is refused with its dedicated message.
    $page->fill('account-current-password', 'wrong-password')
        ->fill('account-new-password', 'brand-new-password')
        ->fill('account-confirm-password', 'brand-new-password')
        ->click('[data-test="account-save"]');

    $page->assertSee('The current password is incorrect.')
        ->assertAriaAttribute('#account-current-password', 'invalid', 'true');

    // The correct change succeeds and confirms in the banner.
    $page->fill('account-current-password', 'password')
        ->fill('account-new-password', 'brand-new-password')
        ->fill('account-confirm-password', 'brand-new-password')
        ->click('[data-test="account-save"]');

    $page->assertSeeIn('[data-test="account-flash"]', 'Your password has been updated.')
        ->assertNoSmoke();

    // Unlike the forgot-password reset, the session survives: the user can
    // log out and back in with the new password.
    $page->click('Alice')
        ->click('Log out');

    $page->assertPathIs('/login');

    $page->fill('email', $owner->email)
        ->fill('password', 'brand-new-password')
        ->click('button[type="submit"]');

    $page->assertPathIs('/')
        ->assertSee('WS')
        ->assertNoSmoke();
});

it('refuses an invitation opened by an account with a different email', function () {
    [, $workspace] = setupBrowserWorkspace();
    $invitation = Invitation::withoutWorkspaceScope()->create([
        'workspace_id' => $workspace->id,
        'email' => 'client@example.com',
        'token' => Str::random(64),
        'expires_at' => now()->addDays(7),
    ]);
    $other = User::factory()->create(['email' => 'other@example.com']);
    $this->actingAs($other);

    $page = visit("/invitations/{$invitation->token}/accept");

    // Explicit refusal page: the invited address is masked, nothing was
    // joined and the workspace stays closed.
    $page->assertSee('This invitation is not addressed to you')
        ->assertSeeIn('[data-test="invitation-mismatch-message"]', 'It was intended for c***@example.com. You are signed in with a different account.')
        ->assertNoSmoke();

    app(WorkspaceContext::class)->set($workspace);

    expect($other->memberships()->where('workspace_id', $workspace->id)->exists())->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});
