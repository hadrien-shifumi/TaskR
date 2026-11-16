<?php

namespace App\Mcp\Tools;

use App\Actions\Issues\SetAssignees as SetAssigneesAction;
use App\Mcp\Support\AgentTool;
use App\Models\Issue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('set_assignees')]
#[Description('Replace the assignees of an issue. Pass an empty array to unassign everyone. Assignee ids must belong to the roster of the issue\'s project.')]
class SetAssignees extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'issue_id' => $schema->string()->description('ULID of the issue')->required(),
            'assignees' => $schema->array()->items($schema->string())->description('User ids to assign (may be empty)')->required(),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $this->resolveWorkspace($request);

        $issue = Issue::query()->findOrFail($request->get('issue_id'));

        $action = app(SetAssigneesAction::class);
        // The action's rules must target the issue's project roster: the
        // tool injects the resolved issue because the HTTP request of the
        // MCP endpoint carries no route binding.
        $assignees = $request->validate($action->rules($issue))['assignees'];

        return Response::structured([
            'issue' => $this->presentIssue($action->handle($issue, $assignees, $this->currentUser($request))),
        ]);
    }
}
