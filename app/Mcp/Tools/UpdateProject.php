<?php

namespace App\Mcp\Tools;

use App\Actions\Projects\UpdateProject as UpdateProjectAction;
use App\Mcp\Support\AgentTool;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_project')]
#[Description('Update a project of a workspace.')]
class UpdateProject extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'project_id' => $schema->string()->description('ULID of the project to update')->required(),
            'name' => $schema->string()->description('New project name (unique per workspace)')->required(),
            'description' => $schema->string()->description('Optional description (max 5000 chars)'),
            'target_date' => $schema->string()->description('Optional target date (YYYY-MM-DD)'),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $workspace = $this->resolveWorkspace($request);

        $project = Project::query()->findOrFail($request->get('project_id'));

        $action = app(UpdateProjectAction::class)->forProject($project);
        $data = $request->validate($action->rules());

        $project = $action->handle($project, $data, $this->currentUser($request));

        return Response::structured([
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'targetDate' => $project->target_date?->toISOString(),
                'archivedAt' => $project->archived_at?->toISOString(),
            ],
        ]);
    }
}
