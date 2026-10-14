<?php

namespace App\Actions\TimeTracking;

use App\Models\Issue;
use App\Models\TimeEntry;
use App\Responders\TimeTracking\TimeTrackingResponder;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class DeleteTimeEntry
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        $entry = $this->currentEntry();

        // If the entry is not found in this issue's context, let asController handle the 404.
        if ($entry === null) {
            return true;
        }

        // Only the author may delete their time entry.
        return request()->user()?->id === $entry->author_id;
    }

    public function handle(TimeEntry $timeEntry): TimeEntry
    {
        $timeEntry->delete();

        return $timeEntry;
    }

    public function asController(Issue $issue, string $timeEntryId, TimeTrackingResponder $responder): RedirectResponse
    {
        $timeEntry = $issue->timeEntries()->findOrFail($timeEntryId);
        $this->handle($timeEntry);

        return $responder->entryDeleted($timeEntry);
    }

    private function currentEntry(): ?TimeEntry
    {
        $issue = request()->route('issue');
        $timeEntryId = request()->route('timeEntry');

        if (! $issue instanceof Issue || ! is_string($timeEntryId)) {
            return null;
        }

        return $issue->timeEntries()->find($timeEntryId);
    }
}
