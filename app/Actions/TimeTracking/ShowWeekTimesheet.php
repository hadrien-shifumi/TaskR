<?php

namespace App\Actions\TimeTracking;

use App\Actions\TimeTracking\Concerns\InteractsWithTimesheetWeek;
use App\Models\TimeEntry;
use App\Models\User;
use App\Presenters\TimeTracking\TimeEntryPresenter;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class ShowWeekTimesheet
{
    use AsController;
    use AsObject;
    use InteractsWithTimesheetWeek;

    /**
     * The payload shared by the JSON feed and the "My time" page: the
     * entries of the requested week (default: the current week), aggregated
     * per ticket and per day, plus the raw entries. A guest reads its
     * perimeter only, exactly like the export. The retroactivity window
     * applies: a week beyond it fails validation, while an unreadable date
     * simply falls back to the current week.
     *
     * @return array{weekStart: string, weekEnd: string, weekTotal: float, grid: list<array{issueId: string, issueTitle: string, days: array<string, float>, weekTotal: float}>, entries: list<array<string, mixed>>}
     */
    public function handle(User $author, ?string $week): array
    {
        $weekStart = $this->parseWeekAnchor($week) ?? CarbonImmutable::today()->startOfWeek();
        $this->abortIfWeekOutOfBounds($weekStart);
        $weekEnd = $weekStart->endOfWeek();

        $entries = $this->authorEntries($author, $weekStart, $weekEnd);

        return [
            'weekStart' => $weekStart->toDateString(),
            'weekEnd' => $weekEnd->toDateString(),
            'weekTotal' => (float) $entries->sum('hours'),
            'grid' => $this->grid($entries),
            'entries' => $entries
                ->map(fn (TimeEntry $entry) => TimeEntryPresenter::weekEntry($entry))
                ->values()
                ->all(),
        ];
    }

    public function asController(Request $request)
    {
        app(WorkspaceContext::class)->getOrFail();

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json($this->handle($user, $request->query('week')));
    }

    /**
     * The author's entries of the week, within the reader's project scope.
     *
     * @return Collection<int, TimeEntry>
     */
    private function authorEntries(User $user, CarbonImmutable $weekStart, CarbonImmutable $weekEnd): Collection
    {
        $scope = app(WorkspaceContext::class)->accessibleProjectIds($user);

        return TimeEntry::query()
            ->where('author_id', $user->getKey())
            ->when($scope !== null, fn (Builder $entries) => $entries->whereHas(
                'issue',
                fn (Builder $issue) => $issue->whereIn('project_id', $scope),
            ))
            // The ticket reference rides the presentation (grid row name,
            // list view) — one eager load, never a query per entry.
            ->with('issue:id,title')
            // Date-level comparison: the bounds are `Y-m-d` days, while the
            // stored column carries a time part on some drivers (sqlite) —
            // a bare string comparison would exclude the bound day itself.
            ->whereDate('worked_at', '>=', $weekStart->toDateString())
            ->whereDate('worked_at', '<=', $weekEnd->toDateString())
            ->orderBy('worked_at')
            ->orderBy('issue_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * The grid: one row per ticket, the hours summed per day of the week
     * and over the whole week.
     *
     * @param  Collection<int, TimeEntry>  $entries
     * @return list<array{issueId: string, issueTitle: string, days: array<string, float>, weekTotal: float}>
     */
    private function grid(Collection $entries): array
    {
        return $entries
            ->groupBy('issue_id')
            ->map(fn (Collection $issueEntries): array => [
                'issueId' => $issueEntries->first()->issue_id,
                'issueTitle' => $issueEntries->first()->issue?->title ?? '',
                'days' => $issueEntries
                    ->groupBy(fn (TimeEntry $entry): string => $entry->worked_at->toDateString())
                    ->map(fn (Collection $dayEntries): float => (float) $dayEntries->sum('hours'))
                    ->all(),
                'weekTotal' => (float) $issueEntries->sum('hours'),
            ])
            ->values()
            ->all();
    }
}
