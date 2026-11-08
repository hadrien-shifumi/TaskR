<?php

namespace Database\Factories;

use App\Models\ActiveTimer;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActiveTimer>
 */
class ActiveTimerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * workspace_id is intentionally absent: the BelongsToWorkspace hook
     * fills it from the WorkspaceContext, which the caller must have set.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'issue_id' => Issue::factory(),
            'started_at' => now(),
        ];
    }
}
