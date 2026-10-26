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
#[Description('Create a new workspace. The authenticated user becomes its owner. Default statuses are created automatically. Pass with_agent_labels=true to also seed the agent factory qualification labels (agent-ready/agent-failed); off by default.')]
class CreateWorkspace extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Workspace name (max 255 chars)')->required(),
            'with_agent_labels' => $schema->boolean()->description('Seed the agent factory qualification labels (agent-ready green, agent-failed red). Default false: nothing is imposed.'),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $action = app(CreateWorkspaceAction::class);
        $data = $request->validate($action->rules());

        if ($request->get('with_agent_labels') === true) {
            $data['agent_labels'] = true;
        }

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
