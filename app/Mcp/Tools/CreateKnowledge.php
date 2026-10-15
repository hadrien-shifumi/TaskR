<?php

namespace App\Mcp\Tools;

use App\Actions\Knowledge\CreateKnowledgePage;
use App\Mcp\Support\AgentTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_knowledge')]
#[Description('Create a knowledge page in a workspace: markdown body, optional parent page and project attachment. The page is vectorized for semantic search asynchronously.')]
class CreateKnowledge extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'title' => $schema->string()->description('Page title (max 500 chars)')->required(),
            'body' => $schema->string()->description('Markdown body of the page')->required(),
            'parent_id' => $schema->string()->description('ULID of the parent page for the tree hierarchy'),
            'project_id' => $schema->string()->description('ULID of a project of the same workspace to attach the page to'),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $workspace = $this->resolveWorkspace($request);

        $action = app(CreateKnowledgePage::class);
        $data = $request->validate($action->rules());

        $page = $action->handle($workspace, $this->currentUser($request), $data);

        return Response::structured([
            'page' => $this->presentPage($page),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPage($page): array
    {
        return [
            'id' => $page->id,
            'title' => $page->title,
            'parentId' => $page->parent_id,
            'projectId' => $page->project_id,
            'updatedAt' => $page->updated_at?->toISOString(),
        ];
    }
}
