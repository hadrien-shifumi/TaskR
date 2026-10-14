<?php

namespace Database\Factories;

use App\Enums\Priority;
use App\Models\IssueTemplate;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IssueTemplate> */
class IssueTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->word(),
            'description' => fake()->paragraph(),
            'priority' => fake()->randomElement(Priority::cases()),
        ];
    }
}
