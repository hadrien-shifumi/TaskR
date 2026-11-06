<?php

namespace Database\Factories;

use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SavedView> */
class SavedViewFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->word(),
            'summary' => null,
            'filters' => null,
            'sort' => null,
            'dir' => null,
            'created_by' => User::factory(),
        ];
    }
}
