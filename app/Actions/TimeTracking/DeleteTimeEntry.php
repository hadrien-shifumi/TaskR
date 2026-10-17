<?php

namespace App\Actions\TimeTracking;

use App\Models\Issue;
use App\Models\TimeEntry;
use App\Models\User;
use App\Responders\TimeTracking\TimeTrackingResponder;
use App\Support\WorkspaceContext;
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

    public function handle(TimeEntry $timeEntry, ?User $user = null): TimeEntry
    {
        // The underlying issue must stay in the actor's perimeter: a
        // shrunken scope makes old objects fail like non-existent ones.
        // Without a resolvable user this is a trusted internal object run.
        $user ??= request()->user();
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $timeEntry->issue);
        }

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
