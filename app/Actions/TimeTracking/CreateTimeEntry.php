<?php

namespace App\Actions\TimeTracking;

use App\Actions\Audit\RecordActivity;
use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\AuditEventType;
use App\Models\Issue;
use App\Models\TimeEntry;
use App\Models\User;
use App\Responders\TimeTracking\TimeTrackingResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class CreateTimeEntry
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
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
     * @param  array<string, mixed>|null  $data
     */
    public function handle(Issue $issue, User $author, ?array $data = null, ?float $hours = null, ?string $workedAt = null, ?string $note = null): TimeEntry
    {
        // Out-of-scope issues fail exactly like non-existent ones.
        app(WorkspaceContext::class)->abortUnlessIssueAccessible($author, $issue);

        // Support both calling conventions: handle($issue, $author, ['hours' => ..., ...])
        // and handle($issue, $author, null, $hours, $workedAt, $note)
        if ($data !== null) {
            $hours = (float) $data['hours'];
            $workedAt = $data['worked_at'] ?? null;
            $note = $data['note'] ?? null;
        }

        $entry = $issue->timeEntries()->create([
            'author_id' => $author->getKey(),
            'worked_at' => $workedAt ?? now()->toDateString(),
            'hours' => $hours,
            'note' => $note,
        ]);

        // A time entry is not an audit subject: the journal targets the
        // parent issue and carries the entry details in changes.
        $changes = [
            'time_entry_id' => $entry->id,
            'hours' => ['before' => null, 'after' => (float) $entry->hours],
            'worked_at' => ['before' => null, 'after' => $entry->worked_at->toDateString()],
        ];

        if ($entry->note !== null) {
            $changes['note'] = ['before' => null, 'after' => $entry->note];
        }

        RecordActivity::run(AuditEventType::TimeEntryCreated, $issue, $author, $changes);

        return $entry;
    }

    public function asController(ActionRequest $request, Issue $issue, TimeTrackingResponder $responder): RedirectResponse
    {
        $author = $request->user();
        abort_unless($author instanceof User, 401);

        $entry = $this->handle($issue, $author, $request->validated());

        return $responder->entryCreated($entry);
    }
}
