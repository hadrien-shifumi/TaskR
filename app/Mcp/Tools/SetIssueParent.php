<?php

namespace App\Mcp\Tools;

use App\Actions\Issues\SetIssueParent as SetIssueParentAction;
use App\Mcp\Support\AgentTool;
use App\Models\Issue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('set_issue_parent')]
#[Description('Set or clear the parent of an issue. The parent must belong to the same workspace and must not create a hierarchy cycle.')]
class SetIssueParent extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'issue_id' => $schema->string()->description('ULID of the issue to reparent')->required(),
            'parent_id' => $schema->string()->nullable()->description('ULID of the parent issue, or null to clear the parent'),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $this->resolveWorkspace($request);

        $issue = Issue::query()->findOrFail($request->get('issue_id'));

        $action = app(SetIssueParentAction::class);
        $parentId = $request->validate($action->rules())['parent_id'] ?? null;

        return Response::structured([
            'issue' => $this->presentIssue($action->handle($issue, $parentId)),
        ]);
    }
}
