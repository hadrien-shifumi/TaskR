<?php

namespace App\Mcp\Tools;

use App\Actions\Issues\UpdateIssue as UpdateIssueAction;
use App\Mcp\Support\AgentTool;
use App\Models\Issue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_issue')]
#[Description('Update the title, description, priority or due date of an issue.')]
class UpdateIssue extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'issue_id' => $schema->string()->description('ULID of the issue to update')->required(),
            'title' => $schema->string()->description('New title (max 500 chars)')->required(),
            'description' => $schema->string()->description('Optional long description'),
            'priority' => $schema->string()->enum(['none', 'low', 'high', 'urgent'])->description('Priority'),
            'due_date' => $schema->string()->description('Optional due date (YYYY-MM-DD)'),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $this->resolveWorkspace($request);

        $issue = Issue::query()->findOrFail($request->get('issue_id'));

        $action = app(UpdateIssueAction::class);
        $data = $request->validate($action->rules());

        return Response::structured([
            'issue' => $this->presentIssue($action->handle($issue, $data, $this->currentUser($request))),
        ]);
    }
}
