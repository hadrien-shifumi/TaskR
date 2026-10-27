<?php

namespace Database\Factories;

use App\Models\KnowledgePage;
use App\Models\KnowledgePageVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KnowledgePageVersion> */
class KnowledgePageVersionFactory extends Factory
{
    protected $model = KnowledgePageVersion::class;

    public function definition(): array
    {
        return [
            'page_id' => KnowledgePage::factory(),
            'title' => fake()->sentence(3),
            'body' => fake()->paragraph(),
            'editor_id' => User::factory(),
            'created_at' => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }
}
