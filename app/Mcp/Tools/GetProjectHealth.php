<?php

namespace App\Mcp\Tools;

use App\Actions\Projects\GetProjectHealth as GetProjectHealthAction;
use App\Mcp\Support\AgentTool;
use App\Models\Project;
use App\Support\WorkspaceContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_project_health')]
#[Description('Get health indicators for a project: completion percentage, weighted completion (estimate-based), leaf completion (issues without sub-issues), overdue issues, time consumption, projection status. When the workspace setting "parents as containers" is enabled, container parents leave the weighted completion. For a guest or a scoped member, an out-of-perimeter project is not found. Guests receive only their own workload (myWorkload), never the global KPIs; a scoped member keeps them.')]
class GetProjectHealth extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'project_id' => $schema->string()->description('ULID of the project')->required(),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $this->resolveWorkspace($request);

        $user = $this->currentUser($request);

        $project = Project::query()->findOrFail($request->get('project_id'));

        // The health action guards the perimeter (out-of-scope is not_found).
        app(WorkspaceContext::class)->abortUnlessProjectAccessible($user, $project);
        $action = app(GetProjectHealthAction::class);

        // A guest never gets the global KPIs, not even roster-manager: the
        // contributor view mirrors the web page ("Ma charge" only).
        if ($project->workspace->isGuest($user)) {
            return Response::structured([
                'health' => null,
                'canSeeGlobalKpis' => false,
                'myWorkload' => $action->computeWorkloadFor($project, $user)->toArray(),
            ]);
        }

        $health = GetProjectHealthAction::run($project, $user);

        return Response::structured([
            'health' => $health->toArray(),
        ]);
    }
}
