<?php

namespace Database\Factories;

use App\Enums\DigestFrequency;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationPreference>
 */
class NotificationPreferenceFactory extends Factory
{
    /**
     * Defaults mirror the migration: opted out, daily digest, 7 days, Paris.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'enabled' => false,
            'frequency' => DigestFrequency::Daily,
            'upcoming_threshold_days' => 7,
            'timezone' => 'Europe/Paris',
            'last_digest_at' => null,
        ];
    }

    public function enabled(): static
    {
        return $this->state(fn (): array => ['enabled' => true]);
    }

    public function weekly(): static
    {
        return $this->state(fn (): array => ['frequency' => DigestFrequency::Weekly]);
    }
}
