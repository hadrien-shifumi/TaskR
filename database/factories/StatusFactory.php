<?php

namespace Database\Factories;

use App\Enums\StatusCategory;
use App\Models\Status;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Status> */
class StatusFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->word(),
            'category' => fake()->randomElement(StatusCategory::cases()),
            'position' => 0,
        ];
    }
}
