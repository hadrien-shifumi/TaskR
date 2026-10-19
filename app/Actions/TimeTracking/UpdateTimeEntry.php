<?php

namespace App\Actions\TimeTracking;

use App\Models\Issue;
use App\Models\TimeEntry;
use App\Models\User;
use App\Responders\TimeTracking\TimeTrackingResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class UpdateTimeEntry
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

        // Only the author may edit their time entry.
        return request()->user()?->id === $entry->author_id;
    }

    public function rules(): array
    {
        return [
            'hours' => ['required', 'numeric', 'min:0.01'],
            'worked_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(TimeEntry $timeEntry, array $data, ?User $user = null): TimeEntry
    {
        // The underlying issue must stay in the actor's perimeter: a
        // shrunken scope makes old objects fail like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $timeEntry->issue);
        }

        $timeEntry->update([
            'hours' => (float) $data['hours'],
            'worked_at' => $data['worked_at'] ?? $timeEntry->worked_at,
            'note' => $data['note'] ?? null,
        ]);

        return $timeEntry->refresh();
    }

    public function asController(ActionRequest $request, Issue $issue, string $timeEntryId, TimeTrackingResponder $responder): RedirectResponse
    {
        $timeEntry = $this->resolveEntry($issue, $timeEntryId);
        $timeEntry = $this->handle($timeEntry, $request->validated(), $request->user());

        return $responder->entryUpdated($timeEntry);
    }

    private function currentEntry(): ?TimeEntry
    {
        $issue = request()->route('issue');
        $timeEntryId = request()->route('timeEntry');

        if (! $issue instanceof Issue || ! is_string($timeEntryId)) {
            return null;
        }

        return $this->resolveEntry($issue, $timeEntryId);
    }

    private function resolveEntry(Issue $issue, string $timeEntryId): TimeEntry
    {
        return $issue->timeEntries()->findOrFail($timeEntryId);
    }
}
