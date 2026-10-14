<?php

namespace App\Actions\TimeTracking;

use App\Models\Issue;
use Lorisleiva\Actions\Concerns\AsObject;

class ListTimeEntries
{
    use AsObject;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function handle(Issue $issue): array
    {
        return $issue->timeEntries()
            ->with('author')
            ->orderByDesc('worked_at')
            ->get()
            ->map(fn ($entry) => [
                'id' => $entry->id,
                'authorId' => $entry->author_id,
                'authorName' => $entry->author?->name,
                'workedAt' => $entry->worked_at->toDateString(),
                'hours' => (float) $entry->hours,
                'note' => $entry->note,
            ])
            ->values()
            ->all();
    }
}
