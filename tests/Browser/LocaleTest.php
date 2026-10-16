<?php

use App\Models\User;
use Illuminate\Support\Str;
use Pest\Browser\Api\AwaitableWebpage;

/**
 * Logs a user in through the real form (session cookie), so the HTTP stack —
 * including session-based authentication — is exercised end to end.
 */
function loginThroughForm(User $owner): AwaitableWebpage
{
    $page = visit('/login')
        ->fill('email', $owner->email)
        ->fill('password', 'password')
        ->click('button[type="submit"]');

    $page->assertPathIs('/')
        ->assertSee('Projects');

    return $page;
}

it('switches the interface to French and keeps it after the request settles', function () {
    [$owner] = setupBrowserWorkspace();

    $page = loginThroughForm($owner);

    $page->click('[aria-label="Language"]')
        ->click('Français')
        // Let the PATCH /user/locale request and its redirect fully settle:
        // the global `router.on('success')` listener re-syncs i18n with the
        // locale carried by the response — the reported bug is that it
        // echoes the PREVIOUS locale and reverts the UI to English.
        ->waitForEvent('networkidle');

    $page->assertSee('Projets')
        ->assertDontSee('Projects')
        ->assertDontSee('Settings')
        ->assertNoSmoke();
});

it('persists the language across navigation and a full page reload', function () {
    [$owner] = setupBrowserWorkspace();

    $page = loginThroughForm($owner);

    // Switch on a page other than home — `back()` redirects to the referer.
    $page = visit('/issues');

    $page->click('[aria-label="Language"]')
        ->click('Français')
        ->waitForEvent('networkidle');

    $page->assertSee('Tickets')
        ->assertDontSee('Issues');

    // Inertia navigation — every subsequent request must resolve 'fr'.
    $page->click('Accueil')
        ->waitForEvent('networkidle');

    $page->assertSee('Santé des projets')
        ->assertDontSee('Project health')
        ->assertDontSee('Issues');

    // Full reload — a fresh request. The server must resolve the persisted
    // users.locale, not fall back to the application default.
    $page = visit('/');

    $page->assertSee('Projets')
        ->assertSee('Réglages')
        ->assertDontSee('Projects')
        ->assertDontSee('Settings')
        ->assertNoSmoke();
});

it('renders flash and server pages in the persisted locale after re-login', function () {
    [$owner] = setupBrowserWorkspace();
    $owner->update(['locale' => 'fr', 'remember_token' => Str::random(60)]);

    $page = visit('/login')
        ->fill('email', $owner->email)
        ->fill('password', 'password')
        ->click('button[type="submit"]');

    $page->assertPathIs('/')
        ->assertSee('Projets')
        ->assertDontSee('Projects')
        ->assertNoSmoke();
});
