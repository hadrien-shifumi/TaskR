<?php

namespace Database\Factories;

use App\Models\Milestone;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Milestone>
 */
class MilestoneFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'project_id' => Project::factory(),
            'parent_id' => null,
            'title' => ucfirst(fake()->words(3, true)),
            'description' => fake()->paragraph(),
            'start_date' => null,
            'target_date' => null,
            'completed_at' => null,
        ];
    }
}
