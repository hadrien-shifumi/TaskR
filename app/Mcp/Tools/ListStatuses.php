<?php

namespace App\Mcp\Tools;

use App\Actions\Statuses\ListStatuses as ListStatusesAction;
use App\Mcp\Support\AgentTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('list_statuses')]
#[Description('List the statuses of a workspace with their semantic category (backlog, in_progress, done or cancelled) and position.')]
class ListStatuses extends AgentTool
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

        return Response::structured([
            'statuses' => ListStatusesAction::run($workspace),
        ]);
    }
}
