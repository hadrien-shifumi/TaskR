<?php

namespace App\Mcp\Tools;

use App\Actions\Projects\DeleteProject as DeleteProjectAction;
use App\Mcp\Support\AgentTool;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('delete_project')]
#[Description('Delete a project of a workspace. Fails if the project still contains issues; archive it instead.')]
class DeleteProject extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'project_id' => $schema->string()->description('ULID of the project to delete')->required(),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $this->resolveWorkspace($request);

        $project = Project::query()->findOrFail($request->get('project_id'));

        DeleteProjectAction::run($project, $this->currentUser($request));

        return Response::structured([
            'deleted' => true,
            'projectId' => $project->id,
        ]);
    }
}
