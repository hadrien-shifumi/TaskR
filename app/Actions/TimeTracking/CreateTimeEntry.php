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

class CreateTimeEntry
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        $user = request()->user();

        return $user instanceof User && app(WorkspaceContext::class)->canUseWorkspace($user);
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
        // Support both calling conventions: handle($issue, $author, ['hours' => ..., ...])
        // and handle($issue, $author, null, $hours, $workedAt, $note)
        if ($data !== null) {
            $hours = (float) $data['hours'];
            $workedAt = $data['worked_at'] ?? null;
            $note = $data['note'] ?? null;
        }

        return $issue->timeEntries()->create([
            'author_id' => $author->getKey(),
            'worked_at' => $workedAt ?? now()->toDateString(),
            'hours' => $hours,
            'note' => $note,
        ]);
    }

    public function asController(ActionRequest $request, Issue $issue, TimeTrackingResponder $responder): RedirectResponse
    {
        $author = $request->user();
        abort_unless($author instanceof User, 401);

        $entry = $this->handle($issue, $author, $request->validated());

        return $responder->entryCreated($entry);
    }
}
