<?php

namespace App\Mcp\Tools;

use App\Actions\Knowledge\SearchKnowledge as SearchKnowledgeAction;
use App\Mcp\Support\AgentTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('search_knowledge')]
#[Description('Semantic search over the knowledge pages of a workspace. Ask a natural-language question; results come ranked by similarity with title, excerpt and score. Use it to locate relevant pages before get_knowledge.')]
class SearchKnowledge extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'query' => $schema->string()->description('Natural-language question to search for')->required(),
            'project_id' => $schema->string()->description('Restrict results to pages attached to this project ULID'),
            'limit' => $schema->integer()->description('Max results (1-50, default 10)'),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $workspace = $this->resolveWorkspace($request);

        $input = $request->validate([
            'query' => ['required', 'string', 'max:500'],
            'project_id' => [
                'nullable', 'ulid',
                Rule::exists('projects', 'id')->where('workspace_id', $workspace->id),
            ],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return Response::structured([
            'results' => SearchKnowledgeAction::run(
                $workspace,
                $input['query'],
                $input['project_id'] ?? null,
                $input['limit'] ?? 10,
                $this->currentUser($request),
            ),
        ]);
    }
}
