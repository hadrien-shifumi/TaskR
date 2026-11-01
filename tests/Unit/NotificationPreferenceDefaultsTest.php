<?php

namespace Tests\Unit;

use App\Enums\DigestFrequency;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationPreferenceDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_preference_is_opted_out_with_the_default_settings(): void
    {
        $preference = NotificationPreference::factory()->create();

        $this->assertFalse($preference->enabled);
        $this->assertSame(DigestFrequency::Daily, $preference->frequency);
        $this->assertSame(7, $preference->upcoming_threshold_days);
        $this->assertSame('Europe/Paris', $preference->timezone);
        $this->assertNull($preference->last_digest_at);
        $this->assertTrue(Str::isUlid($preference->getKey()));
    }

    public function test_the_frequency_is_cast_to_the_enum(): void
    {
        $preference = NotificationPreference::factory()->weekly()->create();

        $this->assertSame(DigestFrequency::Weekly, $preference->fresh()->frequency);
        $this->assertSame('weekly', $preference->fresh()->frequency->value);
    }

    public function test_the_enabled_flag_is_cast_to_a_boolean(): void
    {
        $preference = NotificationPreference::factory()->enabled()->create();

        $this->assertTrue($preference->fresh()->enabled);
    }

    public function test_a_user_cannot_have_two_preference_rows(): void
    {
        $user = User::factory()->create();
        NotificationPreference::factory()->for($user)->create();

        $this->expectException(QueryException::class);

        NotificationPreference::factory()->for($user)->create();
    }

    public function test_the_preference_belongs_to_its_user(): void
    {
        $preference = NotificationPreference::factory()->create();

        $this->assertInstanceOf(User::class, $preference->user);
        $this->assertTrue($preference->user->is($preference->user()->first()));
    }

    public function test_deleting_the_user_cascades_to_its_preference(): void
    {
        $preference = NotificationPreference::factory()->create();
        $user = $preference->user;

        $user->delete();

        $this->assertDatabaseMissing('notification_preferences', ['id' => $preference->getKey()]);
    }
}
