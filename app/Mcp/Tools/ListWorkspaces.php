<?php

namespace App\Mcp\Tools;

use App\Actions\Workspaces\ListWorkspaces as ListWorkspacesAction;
use App\Mcp\Support\AgentTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_workspaces')]
#[Description('List the workspaces the authenticated user is a member of, with their role (owner or member) in each. Use this first to discover workspace ids for every other tool.')]
class ListWorkspaces extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        return Response::structured([
            'workspaces' => ListWorkspacesAction::run($this->currentUser($request)),
        ]);
    }
}
