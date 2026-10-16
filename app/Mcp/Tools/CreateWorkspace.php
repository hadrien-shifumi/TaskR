<?php

namespace App\Mcp\Tools;

use App\Actions\Workspaces\CreateWorkspace as CreateWorkspaceAction;
use App\Mcp\Support\AgentTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_workspace')]
#[Description('Create a new workspace. The authenticated user becomes its owner. Default statuses are created automatically.')]
class CreateWorkspace extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Workspace name (max 255 chars)')->required(),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $action = app(CreateWorkspaceAction::class);
        $data = $request->validate($action->rules());

        $workspace = $action->handle($this->currentUser($request), $data);

        return Response::structured([
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'role' => 'owner',
            ],
        ]);
    }
}
