<?php

namespace App\Mcp\Tools;

use App\Actions\Knowledge\RestoreKnowledgePage;
use App\Mcp\Support\AgentTool;
use App\Models\KnowledgePage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('restore_knowledge')]
#[Description('Restore a knowledge page from the trash. The page reappears in the tree at its original parent if possible, or as a root page. If the original project was deleted, the page becomes transverse.')]
class RestoreKnowledge extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'page_id' => $schema->string()->description('ULID of the trashed knowledge page to restore')->required(),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $this->resolveWorkspace($request);

        // Find the page including trashed ones (workspace scope still applies).
        $page = KnowledgePage::withTrashed()->findOrFail($request->get('page_id'));

        $restored = RestoreKnowledgePage::run($page, $this->currentUser($request));

        return Response::structured([
            'restored' => true,
            'pageId' => $restored->id,
            'title' => $restored->title,
            'parentId' => $restored->parent_id,
            'projectId' => $restored->project_id,
        ]);
    }
}
