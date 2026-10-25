<?php

namespace App\Mcp\Tools;

use App\Actions\Issues\ListIssues as ListIssuesAction;
use App\Mcp\Support\AgentTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_issues')]
#[Description('List the issues of a workspace with optional filters. By default archived issues are excluded; pass archived=include for all issues, archived=only for the archive. Use list_projects, list_statuses and list_labels to discover filter values.')]
class ListIssues extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'project' => $schema->string()->description('Filter by project ULID'),
            'status' => $schema->string()->description('Filter by status ULID'),
            'category' => $schema->string()->enum(['backlog', 'in_progress', 'done', 'cancelled'])->description('Filter by status category'),
            'priority' => $schema->string()->enum(['none', 'low', 'high', 'urgent'])->description('Filter by priority'),
            'label' => $schema->string()->description('Filter by label ULID'),
            'assignee' => $schema->string()->description('Filter by assigned user id'),
            'overdue' => $schema->boolean()->description('Only overdue issues when true'),
            'archived' => $schema->string()->enum(['exclude', 'include', 'only'])->description('Archive window: exclude (default) shows only active issues, include shows all, only shows just the archived ones'),
            'limit' => $schema->integer()->description('Page size (1-100, default 25)'),
            'offset' => $schema->integer()->description('Items to skip (default 0)'),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $workspace = $this->resolveWorkspace($request);

        $input = $request->validate([
            'project' => ['nullable', 'ulid'],
            'status' => ['nullable', 'ulid'],
            'category' => ['nullable', 'in:backlog,in_progress,done,cancelled'],
            'priority' => ['nullable', 'in:none,low,high,urgent'],
            'label' => ['nullable', 'ulid'],
            'assignee' => ['nullable', 'integer'],
            'overdue' => ['nullable', 'boolean'],
            'archived' => ['nullable', 'in:exclude,include,only'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $result = ListIssuesAction::run(
            $workspace,
            [
                'project' => $input['project'] ?? null,
                'status' => $input['status'] ?? null,
                'category' => $input['category'] ?? null,
                'priority' => $input['priority'] ?? null,
                'label' => $input['label'] ?? null,
                'assignee' => $input['assignee'] ?? null,
                'overdue' => isset($input['overdue']) && $input['overdue'] ? '1' : null,
                'archived' => $input['archived'] ?? 'exclude',
            ],
            $input['limit'] ?? 25,
            $input['offset'] ?? 0,
            $this->currentUser($request),
        );

        return Response::structured([
            'issues' => $result['issues'],
            'total' => $result['total'],
        ]);
    }
}
