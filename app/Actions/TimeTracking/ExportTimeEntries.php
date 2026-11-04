<?php

namespace App\Actions\TimeTracking;

use App\Models\TimeEntry;
use App\Models\User;
use App\Presenters\TimeTracking\TimeEntryExportPresenter;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsController;
use Symfony\Component\HttpFoundation\Response;

class ExportTimeEntries
{
    use AsController;

    /**
     * Download the filtered time entries as CSV or JSON. The perimeter
     * follows the read permissions — a guest only ever exports the
     * entries of the projects of its scope, and the author filter is
     * open to every reader — while the period is mandatory by design:
     * every export is bounded, defaulting to the current month.
     *
     * The volume is counted after the filters and the perimeter,
     * before any row is generated: beyond the configured limit the
     * export is refused with a message inviting to narrow the filters.
     */
    public function asController(Request $request): Response
    {
        // Resolves the current workspace or 404s, like every workspace surface.
        app(WorkspaceContext::class)->getOrFail();

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        [$after, $before] = $this->resolvePeriod($request);

        $query = $this->filteredQuery($user, $this->filters($request), $after, $before);

        $maxRows = (int) config('taskr.export_max_rows', 50_000);

        if ((clone $query)->toBase()->count() > $maxRows) {
            throw ValidationException::withMessages([
                'time_entries' => [__('validation.time_entry.export_too_many', ['max' => number_format($maxRows, 0, ',', ' ')])],
            ]);
        }

        $rows = $query
            ->with([
                'author',
                // The nested constraint receives the BelongsTo relation;
                // with/withSum are forwarded to its query. The per-ticket
                // total covers ALL the entries of the ticket — the
                // reconciliation with the estimate is not window-bound —
                // and rides the eager load: constant query count.
                'issue' => fn ($issue) => $issue
                    ->with('project')
                    ->withSum('timeEntries as logged_hours_sum', 'hours'),
            ])
            ->get()
            ->map(fn (TimeEntry $entry) => TimeEntryExportPresenter::row($entry))
            ->values()
            ->all();

        return $request->query('format') === 'json'
            ? $this->jsonDownload($rows)
            : $this->csvDownload($rows);
    }

    /**
     * The export window: a mandatory, always-bounded period. Each
     * bound falls back to the matching edge of the current month when
     * absent or invalid; a resolved window whose start follows its end
     * falls back to the whole current month. A shared URL never fails,
     * and no export is ever unbounded.
     *
     * @return array{0: string, 1: string}
     */
    private function resolvePeriod(Request $request): array
    {
        $today = CarbonImmutable::today();

        $after = $this->sanitizeDateFilter($request->query('worked_after'))
            ?? $today->startOfMonth()->toDateString();

        $before = $this->sanitizeDateFilter($request->query('worked_before'))
            ?? $today->endOfMonth()->toDateString();

        if ($after > $before) {
            return [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()];
        }

        return [$after, $before];
    }

    /**
     * The fully filtered query — perimeter, project, author and period
     * — the entries of the window over every project the user may
     * read, without any status or archive filter: a completed ticket
     * is exactly what gets billed. The workspace scoping itself comes
     * from the global scope on the resolved context.
     *
     * @param  array<string, int|string|null>  $filters
     */
    private function filteredQuery(User $user, array $filters, string $after, string $before): Builder
    {
        $scope = app(WorkspaceContext::class)->accessibleProjectIds($user);

        return TimeEntry::query()
            ->when($scope !== null, fn (Builder $entries) => $entries->whereHas(
                'issue',
                fn (Builder $issue) => $issue->whereIn('project_id', $scope),
            ))
            ->when($filters['project'] !== null, fn (Builder $entries) => $entries->whereHas(
                'issue',
                fn (Builder $issue) => $issue->where('project_id', $filters['project']),
            ))
            ->when($filters['author'] !== null, fn (Builder $entries) => $entries->where('author_id', $filters['author']))
            // Date-level comparison: the bounds are `Y-m-d` days, while the
            // stored column carries a time part on some drivers (sqlite) —
            // a bare string comparison would exclude the bound day itself.
            ->whereDate('worked_at', '>=', $after)
            ->whereDate('worked_at', '<=', $before)
            ->orderByDesc('worked_at')
            ->orderBy('issue_id')
            ->orderBy('id');
    }

    /**
     * Build the filter array from the request query string. Same
     * convention as the issues export: invalid values are dropped
     * silently, a shared URL must never 422.
     *
     * @return array{project: string|null, author: int|null}
     */
    private function filters(Request $request): array
    {
        return [
            'project' => $this->sanitizeIdFilter($request->query('project')),
            'author' => $this->sanitizeAuthorFilter($request->query('author')),
        ];
    }

    /**
     * Normalize a single-ULID filter read from the query string. Anything that
     * is not a ULID is ignored silently.
     */
    private function sanitizeIdFilter(mixed $value): ?string
    {
        return is_string($value) && Str::isUlid($value) ? $value : null;
    }

    /**
     * Normalize the author filter read from the query string: a
     * positive user id. Anything else is ignored silently.
     */
    private function sanitizeAuthorFilter(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * Normalize a period bound read from the query string. Anything that is
     * not a strict `Y-m-d` date is ignored silently.
     */
    private function sanitizeDateFilter(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (InvalidFormatException|InvalidArgumentException) {
            return null;
        }

        // Rejects overflowing dates such as 2026-13-45, which Carbon rolls over.
        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $value;
    }

    /**
     * CSV download: explicit French headers, comma separator, native
     * escaping, UTF-8 BOM so a spreadsheet renders the accents.
     *
     * Named `csvDownload`, never `csvResponse` or `jsonResponse`: those
     * collide with the controller decorator's response hooks — a private
     * `jsonResponse` is still seen by `method_exists` and breaks any
     * JSON-expecting client with an accessibility TypeError.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function csvDownload(array $rows): Response
    {
        return response()->streamDownload(function () use ($rows): void {
            $stream = fopen('php://output', 'wb');

            fwrite($stream, "\xEF\xBB\xBF");

            fputcsv($stream, TimeEntryExportPresenter::headers(), ',', '"', '\\');

            foreach ($rows as $row) {
                fputcsv($stream, TimeEntryExportPresenter::csvValues($row), ',', '"', '\\');
            }

            fclose($stream);
        }, $this->filename('csv'), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * JSON download: a bare array of structured rows.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function jsonDownload(array $rows): Response
    {
        return response()->streamDownload(function () use ($rows): void {
            echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        }, $this->filename('json'), ['Content-Type' => 'application/json']);
    }

    /**
     * Dated file name: repeated exports stay distinguishable in the
     * downloads folder.
     */
    private function filename(string $extension): string
    {
        return 'saisies-temps-'.now()->format('Y-m-d').'.'.$extension;
    }
}
