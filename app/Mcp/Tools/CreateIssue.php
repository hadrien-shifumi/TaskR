<?php

namespace App\Mcp\Tools;

use App\Actions\Issues\CreateIssue as CreateIssueAction;
use App\Mcp\Support\AgentTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_issue')]
#[Description('Create an issue in a project of a workspace, with the same rules as the web UI. Returns the created issue with its identifiers.')]
class CreateIssue extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'project_id' => $schema->string()->description('ULID of the target project')->required(),
            'title' => $schema->string()->description('Issue title (max 500 chars)')->required(),
            'description' => $schema->string()->description('Optional long description'),
            'status_id' => $schema->string()->description('ULID of a workspace status; defaults to the first status'),
            'priority' => $schema->string()->enum(['none', 'low', 'high', 'urgent'])->description('Priority (default none)'),
            'due_date' => $schema->string()->description('Optional due date (YYYY-MM-DD)'),
            'assignees' => $schema->array()->items($schema->string())->description('User ids to assign'),
            'label_ids' => $schema->array()->items($schema->string())->description('ULIDs of workspace labels'),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $workspace = $this->resolveWorkspace($request);

        $action = app(CreateIssueAction::class);
        $data = $request->validate($action->rules());
        $data['created_by'] ??= $request->user()?->getKey();

        return Response::structured([
            'issue' => $this->presentIssue($action->handle($workspace, $data, $this->currentUser($request))),
        ]);
    }
}
