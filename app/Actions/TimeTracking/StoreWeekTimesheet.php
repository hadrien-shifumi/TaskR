<?php

namespace App\Actions\TimeTracking;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Actions\TimeTracking\Concerns\InteractsWithTimesheetWeek;
use App\Models\Issue;
use App\Models\TimeEntry;
use App\Models\User;
use App\Presenters\TimeTracking\TimeEntryPresenter;
use App\Responders\TimeTracking\TimeTrackingResponder;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class StoreWeekTimesheet
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;
    use InteractsWithTimesheetWeek;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * The weekly grid: one line per ticket, the note shared by the line,
     * at most seven filled cells per line.
     */
    public function rules(): array
    {
        return [
            // Strict day format: a datetime-looking week is refused at
            // validation, long before any date parsing in the handler.
            'week' => ['required', 'date_format:Y-m-d'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.issueId' => ['required', 'string'],
            'lines.*.note' => ['nullable', 'string', 'max:5000'],
            'lines.*.days' => ['required', 'array', 'min:1', 'max:7'],
            'lines.*.days.*' => ['numeric', 'min:0.01'],
        ];
    }

    /**
     * Validate the whole grid in one pass — every check happens BEFORE any
     * insert, so a single invalid cell creates nothing. Each filled cell
     * becomes one standard entry through the very same creation path as
     * the dialogue, audit event included.
     *
     * @param  list<array{issueId: string, note: string|null, days: array<string, mixed>}>  $lines
     * @return list<TimeEntry>
     */
    public function handle(User $author, string $week, array $lines): array
    {
        // Defense in depth: HTTP validation already enforces the strict
        // day format, but the object path (::run) skips it — the handler
        // refuses a malformed week all the same, never a server error.
        $weekStart = $this->parseWeekAnchor($week);

        if ($weekStart === null) {
            throw ValidationException::withMessages([
                'week' => __('validation.time_entry.week_invalid'),
            ]);
        }

        $this->abortIfWeekOutOfBounds($weekStart);
        $weekEnd = $weekStart->endOfWeek();

        // Resolve and guard every line's ticket BEFORE anything else: an
        // out-of-scope ticket fails exactly like a nonexistent one.
        $issues = collect($lines)
            ->map(fn (array $line): Issue => Issue::query()->findOrFail($line['issueId']))
            ->each(fn (Issue $issue) => app(WorkspaceContext::class)->abortUnlessIssueAccessible($author, $issue));

        // Flatten the grid into cells, refusing any day outside the week.
        $cells = $this->cells($lines, $issues, $weekStart, $weekEnd);

        // A cell already filled by this author on this ticket and day is
        // refused explicitly — the dialogue and the timer may stack several
        // entries per (author, ticket, day), so an upsert would silently
        // overwrite hours.
        $this->abortIfAnyDuplicate($author, $cells, $weekStart, $weekEnd);

        $created = [];

        DB::transaction(function () use ($cells, $author, &$created): void {
            foreach ($cells as $cell) {
                $created[] = CreateTimeEntry::run($cell['issue'], $author, [
                    'hours' => $cell['hours'],
                    'worked_at' => $cell['date'],
                    'note' => $cell['note'],
                ]);
            }
        });

        return $created;
    }

    public function asController(ActionRequest $request, TimeTrackingResponder $responder)
    {
        $author = $request->user();
        abort_unless($author instanceof User, 401);

        $created = $this->handle($author, (string) $request->validated('week'), $request->validated('lines'));

        // The weekly page posts through Inertia (HTML accept): it comes back
        // to itself with a confirmation flash, errors ride the redirect.
        // The JSON feed keeps its structured payload.
        if ($request->expectsJson()) {
            return response()->json([
                'created' => count($created),
                'entries' => array_map(
                    fn (TimeEntry $entry): array => TimeEntryPresenter::present($entry),
                    $created,
                ),
            ]);
        }

        return $responder->weekStored(count($created));
    }

    /**
     * The filled cells of the grid, in submission order; every day key must
     * be a strict date inside the submitted week, and the refusal names the
     * offending cell.
     *
     * @param  list<array{issueId: string, note: string|null, days: array<string, mixed>}>  $lines
     * @param  Collection<int, Issue>  $issues
     * @return list<array{index: int, issue: Issue, date: string, hours: float, note: string|null}>
     */
    private function cells(array $lines, Collection $issues, CarbonImmutable $weekStart, CarbonImmutable $weekEnd): array
    {
        $cells = [];

        foreach ($lines as $index => $line) {
            foreach ($line['days'] as $day => $hours) {
                $date = $this->parseDayCell($day);

                if ($date === null || $date->lt($weekStart) || $date->gt($weekEnd)) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.days.{$day}" => __('validation.time_entry.day_out_of_week'),
                    ]);
                }

                $cells[] = [
                    'index' => $index,
                    'issue' => $issues->get($index),
                    'date' => $date->toDateString(),
                    'hours' => (float) $hours,
                    'note' => $line['note'] ?? null,
                ];
            }
        }

        return $cells;
    }

    /**
     * One query for the whole week's existing entries of this author, then
     * a refusal naming the first duplicated cell.
     *
     * @param  list<array{index: int, issue: Issue, date: string, hours: float, note: string|null}>  $cells
     */
    private function abortIfAnyDuplicate(User $author, array $cells, CarbonImmutable $weekStart, CarbonImmutable $weekEnd): void
    {
        $issueIds = collect($cells)->pluck('issue.id')->unique()->values();

        $existing = TimeEntry::query()
            ->where('author_id', $author->getKey())
            ->whereIn('issue_id', $issueIds)
            ->whereDate('worked_at', '>=', $weekStart->toDateString())
            ->whereDate('worked_at', '<=', $weekEnd->toDateString())
            ->get(['issue_id', 'worked_at'])
            ->map(fn (TimeEntry $entry): string => $entry->issue_id.'|'.$entry->worked_at->toDateString())
            ->all();

        foreach ($cells as $cell) {
            if (in_array($cell['issue']->id.'|'.$cell['date'], $existing, true)) {
                throw ValidationException::withMessages([
                    "lines.{$cell['index']}.days.{$cell['date']}" => __('validation.time_entry.duplicate_cell'),
                ]);
            }
        }
    }
}
