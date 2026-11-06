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
#[Description('Move an issue to another status of its workspace. With cascade_children, the whole active sub-tree (descendants at every level) follows into the target status.')]
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
            'cascade_children' => $schema->boolean()->description('Move every active descendant issue to the same status (default false). Archived descendants are never touched. A parent cannot move to a done status while an active descendant is still open, unless the cascade moves it along.'),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $this->resolveWorkspace($request);

        $issue = Issue::query()->findOrFail($request->get('issue_id'));

        $action = app(MoveIssueAction::class);
        $validated = $request->validate($action->rules());

        return Response::structured([
            'issue' => $this->presentIssue($action->handle(
                $issue,
                $validated['status_id'],
                $this->currentUser($request),
                (bool) ($validated['cascade_children'] ?? false),
            )),
        ]);
    }
}
