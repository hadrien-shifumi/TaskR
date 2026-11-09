<?php

namespace App\Mcp\Tools;

use App\Actions\Issues\SetIssueKnowledge as SetIssueKnowledgeAction;
use App\Mcp\Support\AgentTool;
use App\Models\Issue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('set_issue_knowledge')]
#[Description('Replace the knowledge pages linked to an issue; pass an empty array to unlink all. Pages must belong to the same workspace and outside the trash; a guest or a scoped member may only link pages within their project perimeter, transverse pages (no project) stay reserved to full members.')]
class SetIssueKnowledge extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'issue_id' => $schema->string()->description('ULID of the issue whose knowledge pages are set')->required(),
            'page_ids' => $schema->array()->items($schema->string())->description('ULIDs of knowledge pages to link; pass an empty array to unlink all')->required(),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $this->resolveWorkspace($request);

        $issue = Issue::query()->findOrFail($request->get('issue_id'));

        $action = app(SetIssueKnowledgeAction::class);
        $pageIds = Validator::validate(
            ['page_ids' => $request->get('page_ids')],
            $action->rules(),
        )['page_ids'];

        $issue = $action->handle($issue, $pageIds, $this->currentUser($request));

        return Response::structured([
            'issue' => $this->presentIssue($issue->load('knowledgePages')),
        ]);
    }
}
