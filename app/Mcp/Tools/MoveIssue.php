<?php

namespace App\Mcp\Tools;

use App\Actions\Issues\MoveIssue as MoveIssueAction;
use App\Mcp\Support\AgentTool;
use App\Models\Issue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('move_issue')]
#[Description('Move an issue to another status of its workspace.')]
class MoveIssue extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'issue_id' => $schema->string()->description('ULID of the issue to move')->required(),
            'status_id' => $schema->string()->description('ULID of the target status')->required(),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $this->resolveWorkspace($request);

        $issue = Issue::query()->findOrFail($request->get('issue_id'));

        $action = app(MoveIssueAction::class);
        $statusId = $request->validate($action->rules())['status_id'];

        return Response::structured([
            'issue' => $this->presentIssue($action->handle($issue, $statusId, $this->currentUser($request))),
        ]);
    }
}
