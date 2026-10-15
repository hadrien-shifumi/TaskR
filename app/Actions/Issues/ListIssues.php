<?php

namespace App\Actions\Issues;

use App\Enums\Priority;
use App\Enums\StatusCategory;
use App\Models\Issue;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsObject;

class ListIssues
{
    use AsObject;

    /**
     * Filterable list across every project of the workspace, presented as
     * plain arrays. Shared by the issues page and the MCP layer.
     *
     * @param  array<string, string|null>  $filters  project|status|category|priority|label|assignee|overdue|q
     * @return array{issues: array<int, array<string, mixed>>, total: int, totalUnfiltered: int}
     */
    public function handle(Workspace $workspace, array $filters = [], ?int $limit = null, int $offset = 0): array
    {
        $query = Issue::query()
            ->with(['project', 'status', 'labels', 'assignees'])
            ->orderByDesc('created_at');

        // Workspace-wide count, before any filter: the issues page shows it
        // next to the filtered count ("visible / total").
        $totalUnfiltered = (clone $query)->toBase()->count();

        $this->applyFilters($query, $filters);

        $total = (clone $query)->toBase()->count();

        if ($offset > 0) {
            $query->offset($offset);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $issues = $query->get()->map(fn (Issue $issue) => $this->present($issue))->values()->all();

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

    /**
     * @return array<string, mixed>
     */
    private function present(Issue $issue): array
    {
        return [
            'id' => $issue->id,
            'title' => $issue->title,
            'project' => ['id' => $issue->project->id, 'name' => $issue->project->name],
            'status' => ['id' => $issue->status->id, 'name' => $issue->status->name, 'category' => $issue->status->category->value],
            'priority' => $issue->priority->value,
            'dueDate' => $issue->due_date?->toISOString(),
            'overdue' => $issue->isOverdue(),
            'assignees' => $issue->assignees->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->values()->all(),
            'labels' => $issue->labels->map(fn ($label) => ['id' => $label->id, 'name' => $label->name, 'color' => $label->color])->values()->all(),
        ];
    }
}
