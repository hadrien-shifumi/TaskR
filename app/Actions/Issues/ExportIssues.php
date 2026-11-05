<?php

namespace App\Actions\Issues;

use App\Models\Issue;
use App\Presenters\Issues\IssueExportPresenter;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsController;
use Symfony\Component\HttpFoundation\Response;

class ExportIssues
{
    use AsController;

    /** Formats served by the export route; anything else falls back to csv. */
    private const FORMATS = ['csv', 'json'];

    /**
     * Download the filtered ticket list as CSV or JSON. The filters are
     * read and sanitized exactly like the issues page (same names, same
     * silent normalization), then applied through ListIssues::filteredQuery
     * — the single source of truth shared with the page — so what is on
     * screen is what gets exported. The perimeter follows the read
     * permissions: a guest only ever exports the projects of its scope.
     *
     * The volume is counted after the filters and the perimeter, before any
     * row is generated: beyond the configured limit the export is refused
     * with a message inviting to narrow the filters.
     */
    public function asController(Request $request): Response
    {
        $workspace = app(WorkspaceContext::class)->getOrFail();

        $query = app(ListIssues::class)->filteredQuery($workspace, $this->filters($request), $request->user());

        $maxRows = (int) config('taskr.export_max_rows', 50_000);

        if ((clone $query)->toBase()->count() > $maxRows) {
            throw ValidationException::withMessages([
                'issues' => [__('validation.issue.export_too_many', ['max' => number_format($maxRows, 0, ',', ' ')])],
            ]);
        }

        $rows = $query
            ->with(['project', 'status', 'labels', 'assignees', 'creator', 'parent'])
            ->withSum('timeEntries as logged_hours_sum', 'hours')
            ->get()
            ->map(fn (Issue $issue) => IssueExportPresenter::row($issue))
            ->values()
            ->all();

        return in_array($request->query('format'), self::FORMATS, true) && $request->query('format') === 'json'
            ? $this->jsonDownload($rows)
            : $this->csvDownload($rows);
    }

    /**
     * Build the filter array from the request query string. Keep in sync
     * with ShowIssuesPage::asController — same names, same normalization:
     * a shared URL must never 422, invalid values are dropped silently.
     *
     * @return array<string, string|array<int, string>|null>
     */
    private function filters(Request $request): array
    {
        return [
            'project' => $request->query('project'),
            'status' => $this->sanitizeIdFilter($request->query('status')),
            'category' => $request->query('category'),
            'priority' => $request->query('priority'),
            'label' => $this->sanitizeLabelFilter($request->query('label')),
            'assignee' => $request->query('assignee'),
            'overdue' => $request->query('overdue'),
            'blocked' => $request->query('blocked'),
            'due_before' => $this->sanitizeDateFilter($request->query('due_before')),
            'due_after' => $this->sanitizeDateFilter($request->query('due_after')),
            'q' => $request->query('q'),
            'sort' => $this->sanitizeSort($request->query('sort')),
            'dir' => $this->sanitizeDir($request->query('dir')),
        ];
    }

    /**
     * Normalize the sort key read from the query string. Unknown keys are
     * ignored silently — the default order applies.
     */
    private function sanitizeSort(mixed $value): ?string
    {
        return is_string($value) && in_array($value, ['created', 'priority', 'due_date', 'status', 'project'], true)
            ? $value
            : null;
    }

    /**
     * Normalize the sort direction read from the query string. Unknown
     * directions are ignored silently — the default direction applies.
     */
    private function sanitizeDir(mixed $value): ?string
    {
        return is_string($value) && in_array($value, ['asc', 'desc'], true) ? $value : null;
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
     * Normalize the label filter read from the query string: a single ULID,
     * a `label[]` list, or a comma-separated list all work. Invalid values
     * are dropped silently — a shared URL must never 422.
     *
     * @return string|array<int, string>|null
     */
    private function sanitizeLabelFilter(mixed $label): string|array|null
    {
        $values = is_array($label) ? $label : explode(',', (string) $label);

        $ids = array_values(array_filter(
            array_map(fn (mixed $value) => is_string($value) ? trim($value) : '', $values),
            fn (string $value) => $value !== '' && Str::isUlid($value),
        ));

        if ($ids === []) {
            return null;
        }

        return count($ids) === 1 ? $ids[0] : $ids;
    }

    /**
     * Normalize a due-date bound read from the query string. Anything that is
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

            fputcsv($stream, IssueExportPresenter::headers(), ',', '"', '\\');

            foreach ($rows as $row) {
                fputcsv($stream, IssueExportPresenter::csvValues($row), ',', '"', '\\');
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
        return 'tickets-'.now()->format('Y-m-d').'.'.$extension;
    }
}
