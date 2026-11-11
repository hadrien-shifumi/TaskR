<?php

namespace App\Mcp\Tools;

use App\Actions\Knowledge\CompareKnowledgePageVersions;
use App\Mcp\Support\AgentTool;
use App\Models\KnowledgePage;
use App\Models\KnowledgePageVersion;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('compare_knowledge_versions')]
#[Description('Compare two versions of a knowledge page. Returns both versions with their full content (title, body, editor, timestamp) so you can see the differences.')]
class CompareKnowledgeVersions extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'page_id' => $schema->string()->description('ULID of the knowledge page')->required(),
            'from_version_id' => $schema->string()->description('ULID of the earlier version')->required(),
            'to_version_id' => $schema->string()->description('ULID of the later version')->required(),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $this->resolveWorkspace($request);

        $page = KnowledgePage::query()->findOrFail($request->get('page_id'));
        $from = KnowledgePageVersion::query()->findOrFail($request->get('from_version_id'));
        $to = KnowledgePageVersion::query()->findOrFail($request->get('to_version_id'));

        $comparison = CompareKnowledgePageVersions::run(
            $page,
            $from,
            $to,
            $this->currentUser($request)
        );

        return Response::structured($comparison);
    }
}
