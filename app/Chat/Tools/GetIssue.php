<?php

namespace App\Chat\Tools;

use App\Actions\Issues\GetIssue as GetIssueAction;
use App\Models\Issue;
use App\Models\User;
use App\Models\Workspace;

/**
 * Read one issue in full (description, project, status, priority,
 * labels, assignees, parent and children). The issue resolves inside
 * the turn's workspace; outside the reader's perimeter it fails exactly
 * like a missing one.
 */
class GetIssue extends ChatTool
{
    public function name(): string
    {
        return 'get_issue';
    }

    public function description(): string
    {
        return 'Read the full body of an issue: description, project, status, priority, due date, estimate, hours, labels, assignees, parent and children. Pair with list_issues (which stays light) to explore candidates, then read the specification here.';
    }

    public function schema(): array
    {
        return [
            'issue_id' => [
                'type' => 'string',
                'description' => 'ULID of the issue',
                'required' => true,
            ],
        ];
    }

    protected function rules(Workspace $workspace): array
    {
        return [
            'issue_id' => ['required', 'ulid'],
        ];
    }

    protected function execute(array $validated, Workspace $workspace, User $user): array
    {
        return [
            'issue' => GetIssueAction::run(
                Issue::query()->findOrFail($validated['issue_id']),
                $user,
            ),
        ];
    }
}
