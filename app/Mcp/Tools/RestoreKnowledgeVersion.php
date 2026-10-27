<?php

namespace App\Mcp\Tools;

use App\Actions\Knowledge\RestoreKnowledgePageVersion;
use App\Mcp\Support\AgentTool;
use App\Models\KnowledgePage;
use App\Models\KnowledgePageVersion;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('restore_knowledge_version')]
#[Description('Restore a knowledge page to a previous version. This creates a NEW version capturing the restoration (it does not delete any version). The page content is updated to match the selected version.')]
class RestoreKnowledgeVersion extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'page_id' => $schema->string()->description('ULID of the knowledge page')->required(),
            'version_id' => $schema->string()->description('ULID of the version to restore')->required(),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $this->resolveWorkspace($request);

        $page = KnowledgePage::query()->findOrFail($request->get('page_id'));
        $version = KnowledgePageVersion::query()->findOrFail($request->get('version_id'));

        $restored = RestoreKnowledgePageVersion::run(
            $page,
            $version,
            $this->currentUser($request)
        );

        return Response::structured([
            'restored' => true,
            'pageId' => $restored->id,
            'title' => $restored->title,
            'body' => $restored->body,
            'restoredFromVersionId' => $version->id,
        ]);
    }
}
