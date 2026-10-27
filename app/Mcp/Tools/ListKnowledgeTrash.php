<?php

namespace App\Mcp\Tools;

use App\Actions\Knowledge\ListTrashedKnowledgePages;
use App\Mcp\Support\AgentTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_knowledge_trash')]
#[Description('List the knowledge pages currently in the trash of a workspace, with who trashed them, when, and why. Use this to see what can be restored or permanently deleted.')]
class ListKnowledgeTrash extends AgentTool
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

        $pages = ListTrashedKnowledgePages::run($workspace, $this->currentUser($request));

        return Response::structured([
            'trashedPages' => $pages,
            'count' => count($pages),
        ]);
    }
}
