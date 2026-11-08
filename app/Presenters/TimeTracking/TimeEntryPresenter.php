<?php

namespace App\Presenters\TimeTracking;

use App\Models\TimeEntry;

class TimeEntryPresenter
{
    /**
     * Entry of the MCP time list — the worked date as a date string.
     *
     * @return array{id: string, authorId: int, authorName: string|null, workedAt: string, hours: float, note: string|null}
     */
    public static function present(TimeEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'authorId' => $entry->author_id,
            'authorName' => $entry->author?->name,
            'workedAt' => $entry->worked_at->toDateString(),
            'hours' => (float) $entry->hours,
            'note' => $entry->note,
        ];
    }

    /**
     * Entry of the issue fiche — the worked instant as ISO 8601.
     *
     * @return array{id: string, authorId: int, authorName: string, workedAt: string|null, hours: float, note: string|null}
     */
    public static function detail(TimeEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'authorId' => $entry->author_id,
            'authorName' => $entry->author?->name ?? 'Unknown',
            'workedAt' => $entry->worked_at?->toISOString(),
            'hours' => (float) $entry->hours,
            'note' => $entry->note,
        ];
    }

    /**
     * Entry of the weekly timesheet — carries its ticket reference so the
     * grid rows and the list view name the ticket without another round
     * trip. The issue relation must be eager-loaded by the caller.
     *
     * @return array{id: string, authorId: int, authorName: string|null, issueId: string, issueTitle: string, workedAt: string, hours: float, note: string|null}
     */
    public static function weekEntry(TimeEntry $entry): array
    {
        return [
            ...self::present($entry),
            'issueId' => $entry->issue_id,
            'issueTitle' => $entry->issue?->title ?? '',
        ];
    }
}
