<?php

namespace App\Mcp\Tools;

use App\Actions\Knowledge\ForceDeleteKnowledgePage;
use App\Mcp\Support\AgentTool;
use App\Models\KnowledgePage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('force_delete_knowledge')]
#[Description('Permanently delete a knowledge page from the trash. This is irreversible. The page must already be in the trash (soft deleted).')]
class ForceDeleteKnowledge extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'page_id' => $schema->string()->description('ULID of the trashed knowledge page to permanently delete')->required(),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $this->resolveWorkspace($request);

        // Find the page including trashed ones (workspace scope still applies).
        $page = KnowledgePage::withTrashed()->findOrFail($request->get('page_id'));

        ForceDeleteKnowledgePage::run($page, $this->currentUser($request));

        return Response::structured([
            'deleted' => true,
            'pageId' => $page->id,
        ]);
    }
}
