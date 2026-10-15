<?php

namespace Database\Factories;

use App\Enums\Priority;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Status;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Issue>
 */
class IssueFactory extends Factory
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
            'title' => ucfirst(fake()->words(4, true)),
            'description' => fake()->paragraph(),
            'status_id' => Status::factory(),
            'priority' => fake()->randomElement(Priority::cases()),
            'due_date' => null,
            'estimated_hours' => null,
        ];
    }
}
