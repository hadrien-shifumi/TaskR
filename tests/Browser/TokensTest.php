<?php

it('shows a freshly created token once in a copy card, then hides it', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    $this->actingAs($owner);

    $page = visit('/settings/tokens');

    // The warning banner is always visible (maquette: "affichée qu'une seule fois").
    $page->assertSee("A token's value is shown")
        ->assertSee('only once');

    // Create a token through the header dialog.
    $page->click('New token')
        ->fill('token-name', 'Agent Claude')
        ->click('button[type="submit"]');

    // The one-shot card exposes the value, on a dashed mono field.
    $page->assertSee('Token created just now');

    $token = $owner->tokens()->firstOrFail();
    expect($token->name)->toBe('Agent Claude');

    $page->assertSeeIn('[data-test="token-created-value"]', (string) $token->id);

    // The table lists the token without its value.
    $page->assertSee('Agent Claude')
        ->assertSee('Last used');

    // "I've copied my token" dismisses the card: the one-shot flash value is
    // consumed on the next render and never shown again.
    $page->click("I've copied my token")
        ->assertDontSee('Token created just now')
        ->assertMissing('[data-test="token-created-card"]');

    $page->assertNoSmoke();
});
