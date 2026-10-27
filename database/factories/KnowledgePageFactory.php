<?php

namespace Database\Factories;

use App\Models\KnowledgePage;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KnowledgePage> */
class KnowledgePageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'project_id' => null,
            'parent_id' => null,
            'title' => fake()->sentence(3),
            'body' => fake()->paragraph(),
            'last_editor_id' => User::factory(),
            'embedding' => null,
            'embedded_at' => null,
        ];
    }

    /**
     * @param  array<int, float>|null  $embedding
     */
    public function vectorized(?array $embedding = null): static
    {
        return $this->state([
            'embedding' => $embedding ?? [1.0],
            'embedded_at' => now(),
        ]);
    }

    /**
     * Mark the page as soft-deleted (in trash).
     */
    public function trashed(?User $deletedBy = null, ?string $reason = null): static
    {
        return $this->state([
            'deleted_at' => now(),
            'deleted_by_id' => $deletedBy?->id,
            'deleted_reason' => $reason,
        ]);
    }
}
