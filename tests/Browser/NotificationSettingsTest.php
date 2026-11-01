<?php

use App\Enums\DigestFrequency;
use App\Models\NotificationPreference;
use App\Support\WorkspaceContext;

it('test_notification_preferences_page_shows_form_with_defaults', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    $this->actingAs($owner);

    $page = visit('/settings/notifications');

    // No preference row yet: the presenter falls back to the opt-out
    // defaults (daily, 7 days, Europe/Paris) and the form renders them.
    $page->assertPresent('[data-test="notification-preferences-form"]')
        ->assertVisible('[data-test="notification-opt-in"]')
        ->assertNotChecked('[data-test="notification-opt-in"]')
        ->assertAttributeContains('[data-test="notification-opt-in"]', 'aria-checked', 'false')
        ->assertValue('[data-test="notification-threshold"]', '7')
        ->assertSeeIn('[data-test="notification-frequency"]', 'Daily')
        ->assertSeeIn('[data-test="notification-timezone"]', 'Europe/Paris');

    app(WorkspaceContext::class)->set($workspace);
    expect(NotificationPreference::query()->where('user_id', $owner->id)->exists())->toBeFalse();

    $page->assertNoSmoke();
});

it('test_user_can_opt_in_and_save_preferences', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    $this->actingAs($owner);

    $page = visit('/settings/notifications');

    $page->click('[data-test="notification-opt-in"]')
        ->assertAttributeContains('[data-test="notification-opt-in"]', 'aria-checked', 'true');

    // Frequency: Base UI select, the option carries the translated label.
    $page->click('[data-test="notification-frequency"]')
        ->click('[role="option"]:has-text("Weekly")');

    $page->fill('[data-test="notification-threshold"]', '14');

    // Timezone: the list is long, type-ahead narrows it down.
    $page->click('[data-test="notification-timezone"]')
        ->keys('[data-test="notification-timezone"]', 'A')
        ->assertPresent('[role="option"]:has-text("America/New_York")')
        ->click('[role="option"]:has-text("America/New_York")');

    $page->click('[data-test="notification-save"]')
        ->waitForEvent('networkidle');

    app(WorkspaceContext::class)->set($workspace);
    $preference = NotificationPreference::query()
        ->where('user_id', $owner->id)
        ->firstOrFail();

    expect($preference->enabled)->toBeTrue()
        ->and($preference->frequency)->toBe(DigestFrequency::Weekly)
        ->and($preference->upcoming_threshold_days)->toBe(14)
        ->and($preference->timezone)->toBe('America/New_York')
        ->and($preference->last_digest_at)->toBeNull();

    // The saved values come back on the next render.
    $page = visit('/settings/notifications');
    $page->assertAttributeContains('[data-test="notification-opt-in"]', 'aria-checked', 'true')
        ->assertValue('[data-test="notification-threshold"]', '14')
        ->assertSeeIn('[data-test="notification-frequency"]', 'Weekly')
        ->assertSeeIn('[data-test="notification-timezone"]', 'America/New_York');

    $page->assertNoSmoke();
});

it('test_user_can_opt_out', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);
    NotificationPreference::factory()->for($owner)->enabled()->weekly()->create([
        'upcoming_threshold_days' => 21,
        'timezone' => 'Europe/Paris',
    ]);
    $this->actingAs($owner);

    $page = visit('/settings/notifications');

    $page->assertAttributeContains('[data-test="notification-opt-in"]', 'aria-checked', 'true')
        ->click('[data-test="notification-opt-in"]')
        ->assertAttributeContains('[data-test="notification-opt-in"]', 'aria-checked', 'false')
        ->click('[data-test="notification-save"]')
        ->waitForEvent('networkidle');

    // Opting out only flips the flag: the other settings stay intact.
    app(WorkspaceContext::class)->set($workspace);
    $preference = NotificationPreference::query()
        ->where('user_id', $owner->id)
        ->firstOrFail();

    expect($preference->enabled)->toBeFalse()
        ->and($preference->frequency)->toBe(DigestFrequency::Weekly)
        ->and($preference->upcoming_threshold_days)->toBe(21)
        ->and($preference->timezone)->toBe('Europe/Paris');

    $page->assertNoSmoke();
});
