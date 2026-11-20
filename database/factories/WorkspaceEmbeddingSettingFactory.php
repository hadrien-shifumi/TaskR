<?php

namespace Database\Factories;

use App\Models\Workspace;
use App\Models\WorkspaceEmbeddingSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkspaceEmbeddingSetting> */
class WorkspaceEmbeddingSettingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'base_url' => 'https://embeddings.test/v1',
            'api_key' => 'factory-key',
            'model' => 'mistral-embed',
            'dimensions' => 3,
            'send_dimensions' => false,
        ];
    }
}
