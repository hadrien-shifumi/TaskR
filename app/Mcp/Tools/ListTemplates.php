<?php

namespace App\Mcp\Tools;

use App\Actions\Templates\ShowTemplatesPage as ShowTemplatesPageAction;
use App\Mcp\Support\AgentTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('list_templates')]
#[Description('List the issue templates of a workspace, with their labels.')]
class ListTemplates extends AgentTool
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

        $result = ShowTemplatesPageAction::run($workspace);

        return Response::structured([
            'templates' => $result['templates'],
        ]);
    }
}
