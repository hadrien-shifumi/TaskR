<?php

namespace App\Mcp\Tools;

use App\Actions\Issues\SetIssueDependencies as SetIssueDependenciesAction;
use App\Mcp\Support\AgentTool;
use App\Models\Issue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('set_issue_dependencies')]
#[Description('Replace the blockers of an issue: the listed issues must be completed before this one. Pass an empty array to remove all blockers. Blockers must belong to the same workspace, a cycle in the dependency graph is refused, and a guest or a scoped member may only link blockers within their project perimeter.')]
class SetIssueDependencies extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'issue_id' => $schema->string()->description('ULID of the issue whose blockers are set')->required(),
            'blocked_by' => $schema->array()->items($schema->string())->description('ULIDs of issues blocking this one; pass an empty array to remove all blockers')->required(),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $this->resolveWorkspace($request);

        $issue = Issue::query()->findOrFail($request->get('issue_id'));

        $action = app(SetIssueDependenciesAction::class);
        $blockedBy = Validator::validate(
            ['blocked_by' => $request->get('blocked_by')],
            $action->rules(),
        )['blocked_by'];

        $issue = $action->handle($issue, $blockedBy, $this->currentUser($request));

        return Response::structured([
            'issue' => $this->presentIssue($issue->load(['blockedBy', 'blocks'])),
        ]);
    }
}
