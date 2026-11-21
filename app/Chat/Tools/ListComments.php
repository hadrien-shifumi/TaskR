<?php

namespace App\Chat\Tools;

use App\Actions\Comments\ListComments as ListCommentsAction;
use App\Models\Issue;
use App\Models\User;
use App\Models\Workspace;

/**
 * Read the comments of one issue, oldest first. The issue resolves
 * inside the turn's workspace; outside the reader's perimeter it fails
 * exactly like a missing one.
 */
class ListComments extends ChatTool
{
    public function name(): string
    {
        return 'list_comments';
    }

    public function description(): string
    {
        return 'List the comments of an issue, oldest first.';
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
            'comments' => ListCommentsAction::run(
                Issue::query()->findOrFail($validated['issue_id']),
                $user,
            ),
        ];
    }
}
