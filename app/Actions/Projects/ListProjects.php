<?php

namespace App\Actions\Projects;

use App\Models\Workspace;
use Lorisleiva\Actions\Concerns\AsObject;

class ListProjects
{
    use AsObject;

    /**
     * Every project of the workspace, archived ones flagged as such.
     *
     * @return array<int, array<string, mixed>>
     */
    public function handle(Workspace $workspace): array
    {
        return $workspace->projects()
            ->orderBy('name')
            ->get()
            ->map(fn ($project) => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'targetDate' => $project->target_date?->toISOString(),
                'archivedAt' => $project->archived_at?->toISOString(),
            ])
            ->values()
            ->all();
    }
}
