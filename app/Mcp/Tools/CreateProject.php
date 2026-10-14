<?php

namespace App\Mcp\Tools;

use App\Actions\Projects\CreateProject as CreateProjectAction;
use App\Mcp\Support\AgentTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_project')]
#[Description('Create a project in a workspace. Returns the created project with its ULID.')]
class CreateProject extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'name' => $schema->string()->description('Project name (unique per workspace)')->required(),
            'description' => $schema->string()->description('Optional description (max 5000 chars)'),
            'target_date' => $schema->string()->description('Optional target date (YYYY-MM-DD)'),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $workspace = $this->resolveWorkspace($request);

        $action = app(CreateProjectAction::class);
        $data = $request->validate($action->rules());

        return Response::structured([
            'project' => $this->presentProject($action->handle($workspace, $data)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentProject($project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'description' => $project->description,
            'targetDate' => $project->target_date?->toISOString(),
            'archivedAt' => $project->archived_at?->toISOString(),
        ];
    }
}
