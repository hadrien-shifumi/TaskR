<?php

namespace App\Mcp\Tools;

use App\Actions\Workspaces\ShowMembersPage as ShowMembersPageAction;
use App\Mcp\Support\AgentTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_members')]
#[Description('List the members of a workspace and their pending invitations.')]
class ListMembers extends AgentTool
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

        $result = ShowMembersPageAction::run($workspace);

        return Response::structured([
            'members' => $result['members'],
            'invitations' => $result['invitations'],
        ]);
    }
}
