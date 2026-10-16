<?php

namespace App\Mcp\Support;

use App\Models\Issue;

/**
 * Flat, stable serialization of an issue for agent consumption:
 * ULID identifiers, ISO 8601 dates, no Eloquent models.
 */
class IssuePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(Issue $issue): array
    {
        return [
            'id' => $issue->id,
            'workspaceId' => $issue->workspace_id,
            'projectId' => $issue->project_id,
            'title' => $issue->title,
            'description' => $issue->description,
            'statusId' => $issue->status_id,
            'priority' => $issue->priority->value,
            'dueDate' => $issue->due_date?->toISOString(),
            'parentId' => $issue->parent_id,
            'assigneeIds' => $issue->assignees->pluck('id')->values()->all(),
            'labelIds' => $issue->labels->pluck('id')->values()->all(),
            'estimatedHours' => $issue->estimated_hours !== null ? (float) $issue->estimated_hours : null,
            'createdAt' => $issue->created_at->toISOString(),
        ];
    }
}
