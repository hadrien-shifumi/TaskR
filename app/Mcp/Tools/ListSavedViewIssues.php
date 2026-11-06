<?php

namespace App\Mcp\Tools;

use App\Actions\Issues\ListIssues as ListIssuesAction;
use App\Mcp\Support\AgentTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_saved_view_issues')]
#[Description('List the issues matching a saved view of the workspace: the stored filters (including the title search) and sort are applied as-is. Use list_saved_views to discover view ids.')]
class ListSavedViewIssues extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'view_id' => $schema->string()->description('ULID of the saved view')->required(),
            'limit' => $schema->integer()->description('Page size (1-100, default 25)'),
            'offset' => $schema->integer()->description('Items to skip (default 0)'),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $workspace = $this->resolveWorkspace($request);

        $input = $request->validate([
            'view_id' => ['required', 'ulid'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        // Scoped binding: a view of another workspace is simply not found.
        $view = $workspace->savedViews()->findOrFail($input['view_id']);

        // A saved view is nothing but filters: the existing listing action
        // applies the stored definition — search and sort included — and
        // scopes the results to the caller's perimeter (guests included).
        $result = ListIssuesAction::run(
            $workspace,
            $view->filters ?? [],
            $input['limit'] ?? 25,
            $input['offset'] ?? 0,
            $this->currentUser($request),
            $view->sort,
            $view->dir,
        );

        return Response::structured([
            'issues' => $result['issues'],
            'total' => $result['total'],
        ]);
    }
}
