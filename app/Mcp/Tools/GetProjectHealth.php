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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('get_project_health')]
#[Description('Get health indicators for a project: completion percentage, weighted completion (estimate-based), leaf completion (issues without sub-issues), overdue issues, time consumption, projection status. When the workspace setting "parents as containers" is enabled, container parents leave the weighted completion. For anyone outside the project team, the project is not found (the workspace owner sees every project). The global KPIs follow the project role: the project owner, its managers and the workspace owner receive them; project members and guests receive only their own workload (myWorkload).')]
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

        // The global KPIs follow the project role exactly like the web
        // page (D13): project owner, managers and workspace owner see
        // them; roster members and guests stay on the contributor view
        // ("Ma charge" only).
        if (! $project->canManage($user)) {
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
