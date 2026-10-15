<?php

namespace App\Mcp\Tools;

use App\Actions\Knowledge\UpdateKnowledgePage;
use App\Mcp\Support\AgentTool;
use App\Models\KnowledgePage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_knowledge')]
#[Description('Update a knowledge page: title, markdown body, optional parent and project. Enforces the same rules as the UI (no hierarchy cycle, project of the same workspace) and re-vectorizes the page.')]
class UpdateKnowledge extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'page_id' => $schema->string()->description('ULID of the knowledge page to update')->required(),
            'title' => $schema->string()->description('New title (max 500 chars)')->required(),
            'body' => $schema->string()->description('New markdown body')->required(),
            'parent_id' => $schema->string()->description('ULID of the new parent page (null to detach)'),
            'project_id' => $schema->string()->description('ULID of a project of the same workspace (null to detach)'),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $this->resolveWorkspace($request);

        $page = KnowledgePage::query()->findOrFail($request->get('page_id'));

        $action = app(UpdateKnowledgePage::class);
        $data = $request->validate($action->rules());

        return Response::structured([
            'page' => $this->presentPage($action->handle($page, $this->currentUser($request), $data)),
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
            'body' => $page->body,
            'parentId' => $page->parent_id,
            'projectId' => $page->project_id,
            'updatedAt' => $page->updated_at?->toISOString(),
        ];
    }
}
