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
#[Description('Update a knowledge page: title, markdown body, optional parent and project. Partial update: omitted parent_id/project_id keep their current value; pass null to detach (root page / transverse page). Enforces the same rules as the UI (no hierarchy cycle, project of the same workspace) and re-vectorizes the page.')]
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
            'parent_id' => $schema->string()->description('ULID of the new parent page. Omitted: keeps the current parent. null: detaches (page becomes a root)'),
            'project_id' => $schema->string()->description('ULID of a project of the same workspace. Omitted: keeps the current project. null: detaches (page becomes transverse)'),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $this->resolveWorkspace($request);

        $page = KnowledgePage::query()->findOrFail($request->get('page_id'));

        // MCP calls update part of the page: an omitted parent or project
        // keeps its current value instead of silently detaching the page
        // (the UI form, the only other client of the action, always
        // re-posts every field). An explicit null still detaches.
        $arguments = $request->all();

        if (! array_key_exists('parent_id', $arguments)) {
            $request->merge(['parent_id' => $page->parent_id]);
        }

        if (! array_key_exists('project_id', $arguments)) {
            $request->merge(['project_id' => $page->project_id]);
        }

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
