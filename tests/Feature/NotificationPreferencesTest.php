<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\DigestFrequency;
use App\Models\Membership;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace];
    }

    private function addUser(Workspace $workspace, string $role): User
    {
        $user = User::factory()->create();
        Membership::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'role' => $role,
        ]);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'frequency' => 'weekly',
            'upcoming_threshold_days' => 14,
            'timezone' => 'America/New_York',
        ], $overrides);
    }

    public function test_the_page_renders_the_opt_out_defaults_when_no_preference_exists(): void
    {
        [$owner] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->get(route('settings.notifications'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/Notifications')
                ->where('preferences.enabled', false)
                ->where('preferences.frequency', 'daily')
                ->where('preferences.upcomingThresholdDays', 7)
                ->where('preferences.timezone', 'Europe/Paris')
                ->where('preferences.lastDigestAt', null)
                ->has('timezones'));
    }

    public function test_the_page_renders_the_persisted_preferences(): void
    {
        [$owner] = $this->setupWorkspace();
        NotificationPreference::factory()->for($owner)->enabled()->weekly()->create([
            'upcoming_threshold_days' => 21,
            'timezone' => 'America/New_York',
        ]);

        $this->actingAs($owner)
            ->get(route('settings.notifications'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('preferences.enabled', true)
                ->where('preferences.frequency', 'weekly')
                ->where('preferences.upcomingThresholdDays', 21)
                ->where('preferences.timezone', 'America/New_York'));
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->setupWorkspace();

        $this->get(route('settings.notifications'))
            ->assertRedirect(route('login', absolute: false));

        $this->put(route('settings.notifications.update'), $this->validPayload())
            ->assertRedirect(route('login', absolute: false));
    }

    public function test_a_member_can_read_and_update_their_own_preferences(): void
    {
        [, $workspace] = $this->setupWorkspace();
        $member = $this->addUser($workspace, 'member');

        $this->actingAs($member)
            ->get(route('settings.notifications'))
            ->assertOk();

        $this->actingAs($member)
            ->put(route('settings.notifications.update'), $this->validPayload())
            ->assertRedirect(route('settings.notifications'));

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $member->id,
            'enabled' => true,
            'frequency' => 'weekly',
            'upcoming_threshold_days' => 14,
            'timezone' => 'America/New_York',
        ]);
    }

    public function test_a_guest_cannot_reach_the_preferences(): void
    {
        [, $workspace] = $this->setupWorkspace();
        $guest = $this->addUser($workspace, 'guest');

        $this->actingAs($guest)
            ->get(route('settings.notifications'))
            ->assertForbidden();

        $this->actingAs($guest)
            ->put(route('settings.notifications.update'), $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseMissing('notification_preferences', ['user_id' => $guest->id]);
    }

    public function test_the_first_save_creates_the_preference_row(): void
    {
        [$owner] = $this->setupWorkspace();

        $this->assertDatabaseMissing('notification_preferences', ['user_id' => $owner->id]);

        $this->actingAs($owner)
            ->patch(route('settings.notifications.update'), $this->validPayload())
            ->assertRedirect(route('settings.notifications'));

        $this->assertDatabaseCount('notification_preferences', 1);
        $this->assertDatabaseHas('notification_preferences', ['user_id' => $owner->id]);
    }

    public function test_every_field_is_persisted(): void
    {
        [$owner] = $this->setupWorkspace();

        $this->actingAs($owner)->put(route('settings.notifications.update'), [
            'enabled' => true,
            'frequency' => 'weekly',
            'upcoming_threshold_days' => 90,
            'timezone' => 'Asia/Tokyo',
        ])->assertSessionHasNoErrors();

        $preference = NotificationPreference::query()->where('user_id', $owner->id)->firstOrFail();

        $this->assertTrue($preference->enabled);
        $this->assertSame(DigestFrequency::Weekly, $preference->frequency);
        $this->assertSame(90, $preference->upcoming_threshold_days);
        $this->assertSame('Asia/Tokyo', $preference->timezone);
    }

    public function test_an_unknown_frequency_is_rejected(): void
    {
        [$owner] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->put(route('settings.notifications.update'), $this->validPayload(['frequency' => 'monthly']))
            ->assertSessionHasErrors('frequency');

        $this->assertDatabaseMissing('notification_preferences', ['user_id' => $owner->id]);
    }

    public function test_the_threshold_must_stay_between_one_and_ninety_days(): void
    {
        [$owner] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->put(route('settings.notifications.update'), $this->validPayload(['upcoming_threshold_days' => 0]))
            ->assertSessionHasErrors('upcoming_threshold_days');

        $this->actingAs($owner)
            ->put(route('settings.notifications.update'), $this->validPayload(['upcoming_threshold_days' => 91]))
            ->assertSessionHasErrors('upcoming_threshold_days');

        $this->assertDatabaseMissing('notification_preferences', ['user_id' => $owner->id]);
    }

    public function test_an_unknown_timezone_is_rejected(): void
    {
        [$owner] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->put(route('settings.notifications.update'), $this->validPayload(['timezone' => 'Mars/Olympus_Mons']))
            ->assertSessionHasErrors('timezone');

        $this->assertDatabaseMissing('notification_preferences', ['user_id' => $owner->id]);
    }

    public function test_the_opt_in_flag_is_required(): void
    {
        [$owner] = $this->setupWorkspace();

        $payload = $this->validPayload();
        unset($payload['enabled']);

        $this->actingAs($owner)
            ->put(route('settings.notifications.update'), $payload)
            ->assertSessionHasErrors('enabled');

        $this->assertDatabaseMissing('notification_preferences', ['user_id' => $owner->id]);
    }

    public function test_opting_out_keeps_the_other_settings(): void
    {
        [$owner] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->put(route('settings.notifications.update'), $this->validPayload())
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)
            ->put(route('settings.notifications.update'), $this->validPayload(['enabled' => false]))
            ->assertSessionHasNoErrors();

        $preference = NotificationPreference::query()->where('user_id', $owner->id)->firstOrFail();

        $this->assertFalse($preference->enabled);
        $this->assertSame(DigestFrequency::Weekly, $preference->frequency);
        $this->assertSame(14, $preference->upcoming_threshold_days);
        $this->assertSame('America/New_York', $preference->timezone);
    }

    public function test_preferences_are_isolated_between_two_accounts(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $member = $this->addUser($workspace, 'member');

        $this->actingAs($owner)
            ->put(route('settings.notifications.update'), $this->validPayload())
            ->assertSessionHasNoErrors();

        $this->actingAs($member)
            ->put(route('settings.notifications.update'), $this->validPayload([
                'enabled' => false,
                'frequency' => 'daily',
                'upcoming_threshold_days' => 3,
                'timezone' => 'Europe/London',
            ]))
            ->assertSessionHasNoErrors();

        $ownerPreference = NotificationPreference::query()->where('user_id', $owner->id)->firstOrFail();
        $memberPreference = NotificationPreference::query()->where('user_id', $member->id)->firstOrFail();

        $this->assertTrue($ownerPreference->enabled);
        $this->assertSame(DigestFrequency::Weekly, $ownerPreference->frequency);
        $this->assertSame('America/New_York', $ownerPreference->timezone);

        $this->assertFalse($memberPreference->enabled);
        $this->assertSame(DigestFrequency::Daily, $memberPreference->frequency);
        $this->assertSame(3, $memberPreference->upcoming_threshold_days);
        $this->assertSame('Europe/London', $memberPreference->timezone);

        $this->assertDatabaseCount('notification_preferences', 2);
    }

    public function test_the_flash_message_follows_the_account_locale(): void
    {
        $owner = User::factory()->create(['locale' => 'fr']);
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $this->actingAs($owner)
            ->put(route('settings.notifications.update'), $this->validPayload())
            ->assertSessionHas('status', 'Préférences de notification enregistrées.');
    }
}
