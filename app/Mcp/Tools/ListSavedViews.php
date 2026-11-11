<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\AgentTool;
use App\Presenters\SavedViews\SavedViewPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('list_saved_views')]
#[Description('List the saved views of a workspace (id, name, summary, filters, sort, dir, createdBy), alphabetical. Every view is shared workspace-wide; use list_saved_view_issues to read the issues of one.')]
class ListSavedViews extends AgentTool
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

        $views = $workspace->savedViews()
            ->get()
            ->map(fn ($view) => SavedViewPresenter::present($view))
            ->all();

        return Response::structured([
            'views' => $views,
        ]);
    }
}
