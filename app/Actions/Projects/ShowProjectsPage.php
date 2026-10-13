<?php

namespace App\Actions\Projects;

use App\Models\Workspace;
use App\Responders\Projects\ProjectsPageResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class ShowProjectsPage
{
    use AsController;
    use AsObject;

    /**
     * @return array{projects: array<array-key, array<string, mixed>>}
     */
    public function handle(Workspace $workspace): array
    {
        $projects = $workspace->projects()
            ->orderBy('name')
            ->get();

        return [
            'projects' => [
                'active' => $projects->reject->isArchived()->map(fn ($project) => $this->present($project))->values()->all(),
                'archived' => $projects->filter->isArchived()->map(fn ($project) => $this->present($project))->values()->all(),
            ],
        ];
    }

    public function asController(Request $request, ProjectsPageResponder $responder): Response
    {
        $workspace = app(WorkspaceContext::class)->getOrFail();

        return $responder->toResponse($request, $this->handle($workspace));
    }

    /**
     * @return array<string, mixed>
     */
    private function present($project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'description' => $project->description,
            'archivedAt' => $project->archived_at?->toISOString(),
        ];
    }
}
