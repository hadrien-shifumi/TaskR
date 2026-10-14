<?php

namespace Database\Factories;

use App\Models\Issue;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeEntry>
 */
class TimeEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'issue_id' => Issue::factory(),
            'author_id' => User::factory(),
            'worked_at' => fake()->date(),
            'hours' => fake()->randomFloat(2, 0.25, 8),
            'note' => fake()->optional()->sentence(),
        ];
    }
}
