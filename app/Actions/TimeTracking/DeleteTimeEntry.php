<?php

namespace App\Actions\TimeTracking;

use App\Actions\Audit\RecordActivity;
use App\Enums\AuditEventType;
use App\Models\Issue;
use App\Models\TimeEntry;
use App\Models\User;
use App\Responders\TimeTracking\TimeTrackingResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $timeEntry->issue);
        }

        $issue = $timeEntry->issue;

        // The entry is about to disappear: keep its values in the journal —
        // time entries have no version history of their own.
        $snapshot = [
            'time_entry_id' => $timeEntry->id,
            'hours' => ['before' => (float) $timeEntry->hours, 'after' => null],
            'worked_at' => ['before' => $timeEntry->worked_at->toDateString(), 'after' => null],
        ];

        if ($timeEntry->note !== null) {
            $snapshot['note'] = ['before' => $timeEntry->note, 'after' => null];
        }

        $timeEntry->delete();

        RecordActivity::run(AuditEventType::TimeEntryDeleted, $issue, $user, $snapshot);

        return $timeEntry;
    }

    public function asController(Request $request, Issue $issue, string $timeEntryId, TimeTrackingResponder $responder): RedirectResponse
    {
        $timeEntry = $issue->timeEntries()->findOrFail($timeEntryId);
        $this->handle($timeEntry, $request->user());

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
