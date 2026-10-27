<?php

namespace App\Mcp\Tools;

use App\Actions\Knowledge\ListKnowledgePageVersions;
use App\Mcp\Support\AgentTool;
use App\Models\KnowledgePage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_knowledge_versions')]
#[Description('List the version history of a knowledge page, most recent first. Each version includes the title, editor name, and timestamp. Use this to see what changed over time or to find a version to restore.')]
class ListKnowledgeVersions extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'page_id' => $schema->string()->description('ULID of the knowledge page')->required(),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $this->resolveWorkspace($request);

        $page = KnowledgePage::query()->findOrFail($request->get('page_id'));

        $versions = ListKnowledgePageVersions::run($page, $this->currentUser($request));

        return Response::structured([
            'pageId' => $page->id,
            'pageTitle' => $page->title,
            'versions' => $versions,
            'count' => count($versions),
        ]);
    }
}
