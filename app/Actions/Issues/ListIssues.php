<?php

namespace App\Actions\Issues;

use App\Enums\Priority;
use App\Enums\StatusCategory;
use App\Models\Issue;
use App\Models\User;
use App\Models\Workspace;
use App\Presenters\Issues\IssuePresenter;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsObject;

class ListIssues
{
    use AsObject;

    /**
     * Filterable list across every project of the workspace the user may
     * see, presented as plain arrays. Shared by the issues page and the
     * MCP layer. A guest only sees the issues of their perimeter, and the
     * workspace-wide count never leaks: totalUnfiltered counts within the
     * perimeter as well.
     *
     * @param  array<string, string|null>  $filters  project|status|category|priority|label|assignee|overdue|q
     * @return array{issues: array<int, array<string, mixed>>, total: int, totalUnfiltered: int}
     */
    public function handle(Workspace $workspace, array $filters = [], ?int $limit = null, int $offset = 0, ?User $user = null): array
    {
        $scope = $user instanceof User
            ? app(WorkspaceContext::class)->accessibleProjectIds($user)
            : null;

        $query = Issue::query()
            ->with(['project', 'status', 'labels', 'assignees'])
            ->when($scope !== null, fn (Builder $issues) => $issues->whereIn('project_id', $scope))
            ->orderByDesc('created_at');

        // Archived visibility: excluded by default (every active view), or
        // explicitly included / listed only. Applied before the unfiltered
        // count: the "total" shown next to filters is the active total.
        $archived = $filters['archived'] ?? 'exclude';
        if ($archived === 'only') {
            $query->archived();
        } elseif ($archived !== 'include') {
            $query->unarchived();
        }

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

        $issues = $query->get()->map(fn (Issue $issue) => IssuePresenter::card($issue))->values()->all();

        return [
            'issues' => $issues,
            'total' => $total,
            'totalUnfiltered' => $totalUnfiltered,
        ];
    }

    /**
     * @param  array<string, string|null>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['q'])) {
            $query->where('title', 'like', '%'.$filters['q'].'%');
        }

        if (! empty($filters['project'])) {
            $query->where('project_id', $filters['project']);
        }

        if (! empty($filters['status'])) {
            $query->where('status_id', $filters['status']);
        }

        if (! empty($filters['category'])) {
            $query->whereHas('status', fn (Builder $status) => $status->where('category', StatusCategory::from($filters['category'])));
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', Priority::from($filters['priority']));
        }

        if (! empty($filters['label'])) {
            $query->whereHas('labels', fn (Builder $labels) => $labels->whereKey($filters['label']));
        }

        if (! empty($filters['assignee'])) {
            $query->whereHas('assignees', fn (Builder $assignees) => $assignees->whereKey($filters['assignee']));
        }

        if (($filters['overdue'] ?? null) === '1' || ($filters['overdue'] ?? null) === 'true') {
            $query->overdue();
        }
    }
}
