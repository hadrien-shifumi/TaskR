<?php

namespace App\Chat\Tools;

use App\Actions\Issues\ListIssues as ListIssuesAction;
use App\Models\User;
use App\Models\Workspace;

/**
 * List the issues of the workspace with the filters a plain conversation
 * can express. Statuses and labels stay out of the filter set: no tool
 * reveals their ULIDs to the model, so a filter on them could only be a
 * hallucination.
 */
class ListIssues extends ChatTool
{
    public function name(): string
    {
        return 'list_issues';
    }

    public function description(): string
    {
        return 'List the issues of a workspace with optional filters. Use list_projects first to discover the project identifiers. Archived issues are excluded by default; pass archived=include or archived=only to see them.';
    }

    public function schema(): array
    {
        return [
            'project' => [
                'type' => 'string',
                'description' => 'Restrict the list to this project ULID (discover it with list_projects)',
            ],
            'category' => [
                'type' => 'string',
                'enum' => ['backlog', 'in_progress', 'done', 'cancelled'],
                'description' => 'Filter by status category',
            ],
            'priority' => [
                'type' => 'string',
                'enum' => ['none', 'low', 'high', 'urgent'],
                'description' => 'Filter by priority',
            ],
            'overdue' => [
                'type' => 'boolean',
                'description' => 'Only overdue issues when true',
            ],
            'blocked' => [
                'type' => 'boolean',
                'description' => 'Only issues blocked by at least one open issue when true',
            ],
            'archived' => [
                'type' => 'string',
                'enum' => ['exclude', 'include', 'only'],
                'description' => 'Archived visibility: exclude (default), include, or only archived issues',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Page size (1-100, default 25)',
            ],
            'offset' => [
                'type' => 'integer',
                'description' => 'Items to skip (default 0)',
            ],
        ];
    }

    protected function rules(Workspace $workspace): array
    {
        return [
            'project' => ['nullable', 'ulid'],
            'category' => ['nullable', 'in:backlog,in_progress,done,cancelled'],
            'priority' => ['nullable', 'in:none,low,high,urgent'],
            'overdue' => ['nullable', 'boolean'],
            'blocked' => ['nullable', 'boolean'],
            'archived' => ['nullable', 'in:exclude,include,only'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ];
    }

    protected function execute(array $validated, Workspace $workspace, User $user): array
    {
        $result = ListIssuesAction::run(
            $workspace,
            [
                'project' => $validated['project'] ?? null,
                'category' => $validated['category'] ?? null,
                'priority' => $validated['priority'] ?? null,
                'overdue' => isset($validated['overdue']) && $validated['overdue'] ? '1' : null,
                'blocked' => isset($validated['blocked']) && $validated['blocked'] ? '1' : null,
                'archived' => $validated['archived'] ?? null,
            ],
            $validated['limit'] ?? 25,
            $validated['offset'] ?? 0,
            $user,
        );

        return [
            'issues' => $result['issues'],
            'total' => $result['total'],
        ];
    }
}
