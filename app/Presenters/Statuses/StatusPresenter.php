<?php

namespace App\Presenters\Statuses;

use App\Models\Status;

class StatusPresenter
{
    /**
     * Row of the MCP status list.
     *
     * @return array{id: string, name: string, category: string, position: int}
     */
    public static function present(Status $status): array
    {
        return [
            'id' => $status->id,
            'name' => $status->name,
            'category' => $status->category->value,
            'position' => $status->position,
        ];
    }

    /**
     * Option of the status filter dropdowns.
     *
     * @return array{id: string, name: string, category: string}
     */
    public static function option(Status $status): array
    {
        return [
            'id' => $status->id,
            'name' => $status->name,
            'category' => $status->category->value,
        ];
    }

    /**
     * Row of the statuses management page. Expects the status hydrated
     * with withCount('issues') and its issues:id,status_id relation.
     *
     * @return array{id: string, name: string, category: string, position: int, issuesCount: int, issueIds: array<int, string>}
     */
    public static function managedList(Status $status): array
    {
        return [
            'id' => $status->id,
            'name' => $status->name,
            'category' => $status->category->value,
            'position' => $status->position,
            'issuesCount' => $status->issues_count,
            'issueIds' => $status->issues->map(fn ($issue) => $issue->id)->values()->all(),
        ];
    }
}
