<?php

namespace App\Actions\Issues;

use App\Enums\Priority;
use App\Enums\StatusCategory;
use App\Models\Issue;
use App\Models\User;
use App\Models\Workspace;
use App\Presenters\Issues\IssuePresenter;
use App\Support\IssueFilterSanitizer;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsObject;

class ListIssues
{
    use AsObject;

    /**
     * Crockford base32 alphabet of a ULID, without the ambiguous I, L, O and U.
     * A search text made only of these characters may be an identifier.
     */
    private const ULID_ALPHABET = '0-9A-HJKMNP-TV-Z';

    /** Shortest identifier prefix the search resolves to a ticket. */
    private const ULID_PREFIX_MIN_LENGTH = 7;

    /**
     * Filterable list across every project of the workspace the user may
     * see, presented as plain arrays. Shared by the issues page and the
     * MCP layer. A guest only sees the issues of their perimeter, and the
     * workspace-wide count never leaks: totalUnfiltered counts within the
     * perimeter as well.
     *
     * @param  array<string, string|array<int, string>|null>  $filters  project|status|category|priority|label|assignee|overdue|blocked|due_before|due_after|q|sort|dir
     * @param  string|null  $sort  created (default)|priority|due_date|status|project — invalid values are ignored
     * @param  string|null  $dir  asc|desc — invalid values are ignored (desc for created, asc otherwise)
     * @return array{issues: array<int, array<string, mixed>>, total: int, totalUnfiltered: int}
     */
    public function handle(Workspace $workspace, array $filters = [], ?int $limit = null, int $offset = 0, ?User $user = null, ?string $sort = null, ?string $dir = null): array
    {
        $query = $this->scopedQuery($workspace, $filters, $user, $sort, $dir)
            ->with(['project', 'status', 'labels', 'assignees', 'blockedBy.status'])
            ->with(['knowledgePages' => LoadIssueKnowledgePages::cardConstraint($user)])
            // The reopened-child signal: a scalar EXISTS subquery, never a
            // per-card query. Archived children left the parent's scope, and
            // a terminal child does not reopen the work.
            ->withExists([
                'children' => fn (Builder $query) => $query
                    ->whereNull('archived_at')
                    ->whereHas('status', fn (Builder $status) => $status->whereNotIn('category', [
                        StatusCategory::Done,
                        StatusCategory::Cancelled,
                    ])),
            ]);

        // Scope-wide count, before any other filter: the issues page shows
        // it next to the filtered count ("visible / total").
        $totalUnfiltered = (clone $query)->toBase()->count();

        $this->applyFilters($query, $filters);

        $total = (clone $query)->toBase()->count();

        if ($offset > 0) {
            $query->offset($offset);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $issues = $query->get();

        // The active-descendant summary rides every card: the kanban view
        // needs it to offer the cascade before a parent moves. One query
        // per depth level, never one per card.
        $descendants = app(LoadActiveDescendants::class)->run($issues, $user);

        $issues = $issues->map(fn (Issue $issue) => IssuePresenter::card($issue, $descendants))->values()->all();

        return [
            'issues' => $issues,
            'total' => $total,
            'totalUnfiltered' => $totalUnfiltered,
        ];
    }

    /**
     * The fully filtered query — perimeter, archived visibility, sort and
     * data filters — the single source of truth shared by the list
     * presentation and the exports: an export always matches the filters
     * of the page. Eager loads stay with the consumer.
     *
     * @param  array<string, string|array<int, string>|null>  $filters  project|status|category|priority|label|assignee|overdue|blocked|due_before|due_after|q|sort|dir
     */
    public function filteredQuery(Workspace $workspace, array $filters = [], ?User $user = null, ?string $sort = null, ?string $dir = null): Builder
    {
        $query = $this->scopedQuery($workspace, $filters, $user, $sort, $dir);

        $this->applyFilters($query, $filters);

        return $query;
    }

    /**
     * The scoped, visibility-aware, sorted query — perimeter, archived
     * handling and ordering, everything but the data filters.
     *
     * @param  array<string, string|array<int, string>|null>  $filters
     */
    private function scopedQuery(Workspace $workspace, array $filters, ?User $user, ?string $sort, ?string $dir): Builder
    {
        $scope = $user instanceof User
            ? app(WorkspaceContext::class)->accessibleProjectIds($user)
            : null;

        $query = Issue::query()
            ->when($scope !== null, fn (Builder $issues) => $issues->whereIn('project_id', $scope));

        $this->applySort($query, $sort ?? ($filters['sort'] ?? null), $dir ?? ($filters['dir'] ?? null));

        // Archived visibility: excluded by default (every active view), or
        // explicitly included / listed only. Applied before the unfiltered
        // count: the "total" shown next to filters is the active total.
        $archived = $filters['archived'] ?? 'exclude';
        if ($archived === 'only') {
            $query->whereNotNull('issues.archived_at');
        } elseif ($archived !== 'include') {
            $query->whereNull('issues.archived_at');
        }

        return $query;
    }

    /**
     * Order the list. `created` (the default) keeps the historical newest
     * first order; `priority` follows the enum order (none < low < high <
     * urgent); `status` follows the workspace position; `project` follows
     * the project name. A null due date always sorts last. `created_at`
     * (desc) is the secondary key so equal values stay stable.
     */
    private function applySort(Builder $query, mixed $sort, mixed $dir): void
    {
        $key = is_string($sort) && in_array($sort, IssueFilterSanitizer::SORT_KEYS, true) ? $sort : 'created';

        $direction = is_string($dir) && in_array($dir, ['asc', 'desc'], true)
            ? $dir
            : ($key === 'created' ? 'desc' : 'asc');

        if ($key === 'status') {
            $query->leftJoin('statuses', 'statuses.id', '=', 'issues.status_id')
                ->orderBy('statuses.position', $direction);
        } elseif ($key === 'project') {
            $query->leftJoin('projects', 'projects.id', '=', 'issues.project_id')
                ->orderBy('projects.name', $direction);
        } elseif ($key === 'priority') {
            $query->orderByRaw($this->priorityOrderExpression().' '.$direction);
        } elseif ($key === 'due_date') {
            $query->orderByRaw('issues.due_date is null')
                ->orderBy('issues.due_date', $direction);
        } else {
            $query->orderBy('issues.created_at', $direction);
        }

        if ($key !== 'created') {
            $query->orderByDesc('issues.created_at');
        }

        $query->orderBy('issues.id');
    }

    /**
     * Portable CASE expression mapping the priority enum onto its case
     * order (none < low < high < urgent).
     */
    private function priorityOrderExpression(): string
    {
        $cases = collect(Priority::cases())
            ->map(fn (Priority $priority, int $index) => "when '{$priority->value}' then {$index}")
            ->implode(' ');

        return "case issues.priority {$cases} else 0 end";
    }

    /**
     * Free-text search. The title search always applies, raw (historical
     * behavior). When the text looks like a ULID — complete or a prefix of at
     * least seven characters from the Crockford alphabet — the identifier is
     * matched too, so the value of the "copy identifier" button can be pasted
     * back. LIKE wildcards of the prefix branch are escaped.
     */
    private function applySearch(Builder $query, string $search): void
    {
        $query->where(function (Builder $matches) use ($search): void {
            $matches->where('issues.title', 'like', '%'.$search.'%');

            $identifier = strtoupper($search);

            if (! $this->looksLikeUlid($identifier)) {
                return;
            }

            $matches->orWhere('issues.id', 'like', $this->escapeLike($identifier).'%');
        });
    }

    /**
     * A complete ULID (26 characters) or a prefix of at least seven
     * characters, made only of Crockford base32 characters.
     */
    private function looksLikeUlid(string $identifier): bool
    {
        $length = strlen($identifier);

        if ($length < self::ULID_PREFIX_MIN_LENGTH || $length > 26) {
            return false;
        }

        return preg_match('/^['.self::ULID_ALPHABET.']+$/', $identifier) === 1;
    }

    /**
     * Escape the LIKE wildcards so a pasted identifier matches literally.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @param  array<string, string|array<int, string>|null>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['q'])) {
            $this->applySearch($query, (string) $filters['q']);
        }

        if (! empty($filters['project'])) {
            $query->where('issues.project_id', $filters['project']);
        }

        if (! empty($filters['status'])) {
            $query->where('issues.status_id', $filters['status']);
        }

        if (! empty($filters['category'])) {
            $query->whereHas('status', fn (Builder $status) => $status->where('category', StatusCategory::from($filters['category'])));
        }

        if (! empty($filters['priority'])) {
            $query->where('issues.priority', Priority::from($filters['priority']));
        }

        if (! empty($filters['label'])) {
            // A single ULID (MCP) or a list of ULIDs (multi-select): a ticket
            // matches as soon as it carries at least one selected label.
            $labelIds = (array) $filters['label'];
            $query->whereHas('labels', fn (Builder $labels) => $labels->whereKey($labelIds));
        }

        if (! empty($filters['assignee'])) {
            $query->whereHas('assignees', fn (Builder $assignees) => $assignees->whereKey($filters['assignee']));
        }

        if (! empty($filters['due_after'])) {
            $query->whereDate('issues.due_date', '>=', $filters['due_after']);
        }

        if (! empty($filters['due_before'])) {
            $query->whereDate('issues.due_date', '<=', $filters['due_before']);
        }

        if (($filters['overdue'] ?? null) === '1' || ($filters['overdue'] ?? null) === 'true') {
            $query->overdue();
        }

        if (($filters['blocked'] ?? null) === '1' || ($filters['blocked'] ?? null) === 'true') {
            $query->whereHas('blockedBy', fn (Builder $blockers) => $blockers->whereHas(
                'status',
                fn (Builder $statuses) => $statuses->whereNotIn('category', [StatusCategory::Done, StatusCategory::Cancelled]),
            ));
        }
    }
}
