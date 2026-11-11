<?php

namespace App\Mcp\Tools;

use App\Actions\Knowledge\GetKnowledgePage;
use App\Mcp\Support\AgentTool;
use App\Models\KnowledgePage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('get_knowledge')]
#[Description('Get the full markdown content of a knowledge page: title, body, parent, project and last editor.')]
class GetKnowledge extends AgentTool
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

        return Response::structured([
            'page' => GetKnowledgePage::run($page, $this->currentUser($request)),
        ]);
    }
}
