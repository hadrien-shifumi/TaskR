<?php

namespace App\Mcp\Tools;

use App\Actions\Issues\GetIssue as GetIssueAction;
use App\Mcp\Support\AgentTool;
use App\Models\Issue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_issue')]
#[Description('Read the full body of an issue: description, project, status, priority, due date, estimate, hours, labels, assignees, parent and children. Pair with list_issues (which stays light) to explore candidates, then read the specification here.')]
class GetIssue extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'issue_id' => $schema->string()->description('ULID of the issue')->required(),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $this->resolveWorkspace($request);

        $issue = Issue::query()->findOrFail($request->get('issue_id'));

        return Response::structured([
            'issue' => GetIssueAction::run($issue, $this->currentUser($request)),
        ]);
    }
}
