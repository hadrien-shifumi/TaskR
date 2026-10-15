<?php

namespace App\Mcp\Tools;

use App\Actions\Knowledge\ListKnowledgePages;
use App\Mcp\Support\AgentTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_knowledge')]
#[Description('List the knowledge pages of a workspace: id, title, parent and project links. Rebuild the tree from parentId. Start here to get acquainted with a workspace, then call get_knowledge on the pages you need.')]
class ListKnowledge extends AgentTool
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
            'pages' => ListKnowledgePages::run($workspace),
        ]);
    }
}
