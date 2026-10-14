<?php

namespace App\Mcp\Tools;

use App\Actions\Projects\ListProjects as ListProjectsAction;
use App\Mcp\Support\AgentTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_projects')]
#[Description('List the projects of a workspace (id, name, description, target date, archived date).')]
class ListProjects extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $workspace = $this->resolveWorkspace($request);

        return Response::structured([
            'projects' => ListProjectsAction::run($workspace),
        ]);
    }
}
