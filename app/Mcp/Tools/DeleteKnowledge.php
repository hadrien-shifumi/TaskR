<?php

namespace App\Mcp\Tools;

use App\Actions\Knowledge\DeleteKnowledgePage as DeleteKnowledgePageAction;
use App\Mcp\Support\AgentTool;
use App\Models\KnowledgePage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('delete_knowledge')]
#[Description('Delete a knowledge page of a workspace. Fails if the page still has child pages.')]
class DeleteKnowledge extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'page_id' => $schema->string()->description('ULID of the knowledge page to delete')->required(),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $this->resolveWorkspace($request);

        $page = KnowledgePage::query()->findOrFail($request->get('page_id'));

        DeleteKnowledgePageAction::run($page, $this->currentUser($request));

        return Response::structured([
            'deleted' => true,
            'pageId' => $page->id,
        ]);
    }
}
