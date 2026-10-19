<?php

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Projects\ProjectsPageResponder;
use App\Support\ProjectHealth;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class ShowProjectsPage
{
    use AsController;
    use AsObject;

    public function __construct(private GetProjectHealth $getProjectHealth) {}

    /**
     * @return array{projects: array{active: array<array-key, array<string, mixed>>, archived: array<array-key, array<string, mixed>>}}
     */
    public function handle(Workspace $workspace, ?User $user = null): array
    {
        $query = $workspace->projects();

        // A guest only sees the projects of their roster, active or archived.
        if ($user instanceof User) {
            $scope = app(WorkspaceContext::class)->accessibleProjectIds($user);
            if ($scope !== null) {
                $query->whereIn('id', $scope);
            }
        }

        $projects = $query->orderBy('name')->get();

        $healthByProject = $this->getProjectHealth->handleForProjects($projects->all());

        return [
            'projects' => [
                'active' => $projects->reject->isArchived()
                    ->map(fn (Project $project) => $this->present($project, $healthByProject[$project->id] ?? null))
                    ->values()->all(),
                'archived' => $projects->filter->isArchived()
                    ->map(fn (Project $project) => $this->present($project, $healthByProject[$project->id] ?? null))
                    ->values()->all(),
            ],
        ];
    }

    public function asController(Request $request, ProjectsPageResponder $responder): Response
    {
        $workspace = app(WorkspaceContext::class)->getOrFail();

        return $responder->toResponse($request, $this->handle($workspace, $request->user()));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Project $project, ?ProjectHealth $health): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'description' => $project->description,
            'targetDate' => $project->target_date?->toDateString(),
            'archivedAt' => $project->archived_at?->toISOString(),
            'health' => $health?->toArray(),
        ];
    }
}
