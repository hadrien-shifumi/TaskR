<?php

namespace App\Support;

use App\Models\User;
use App\Models\Workspace;

/**
 * Builds the human-readable snapshot of a saved view's filters.
 *
 * The summary is generated once, at creation, in the creator's locale —
 * a memory of the saved context. Resolved names (project, milestone,
 * status, labels, assignee) are looked up within the workspace; an
 * unknown id contributes nothing. Dates stay in the unambiguous Y-m-d
 * form.
 *
 * Visibility (D2): the project and milestone names are resolved only for
 * a viewer who may see their project. The stored summary keeps the
 * creator's copy; read paths recompute it per reader, so a shared view
 * never names a project outside someone's teams.
 */
class SavedViewSummary
{
    /**
     * @param  array<string, string|array<int, string>|null>  $filters
     */
    public static function make(Workspace $workspace, array $filters, ?string $sort, ?string $dir, ?User $viewer = null): ?string
    {
        $parts = [];

        if (! empty($filters['project'])) {
            $name = self::visibleProjectName($workspace, (string) $filters['project'], $viewer);

            if ($name !== null) {
                $parts[] = __('saved_view.summary.project', ['value' => $name]);
            }
        }

        if (! empty($filters['status'])) {
            $name = $workspace->statuses()->whereKey($filters['status'])->value('name');

            if ($name !== null) {
                $parts[] = __('saved_view.summary.status', ['value' => $name]);
            }
        }

        if (! empty($filters['milestone'])) {
            $name = self::visibleMilestoneName($workspace, (string) $filters['milestone'], $viewer);

            if ($name !== null) {
                $parts[] = __('saved_view.summary.milestone', ['value' => $name]);
            }
        }

        if (! empty($filters['category'])) {
            $parts[] = __('saved_view.summary.category', ['value' => __('saved_view.categories.'.$filters['category'])]);
        }

        if (! empty($filters['priority'])) {
            $parts[] = __('saved_view.summary.priority', ['value' => __('saved_view.priorities.'.$filters['priority'])]);
        }

        if (! empty($filters['label'])) {
            $names = $workspace->labels()->whereKey((array) $filters['label'])->pluck('name', 'id');

            $resolved = array_values(array_filter(
                array_map(fn (string $id) => $names[$id] ?? null, (array) $filters['label']),
            ));

            if ($resolved !== []) {
                $parts[] = __('saved_view.summary.labels', ['values' => implode(', ', $resolved)]);
            }
        }

        if (! empty($filters['assignee'])) {
            $name = $workspace->members()->whereKey($filters['assignee'])->value('name');

            if ($name !== null) {
                $parts[] = __('saved_view.summary.assignee', ['value' => $name]);
            }
        }

        if (! empty($filters['overdue'])) {
            $parts[] = __('saved_view.summary.overdue');
        }

        if (! empty($filters['blocked'])) {
            $parts[] = __('saved_view.summary.blocked');
        }

        $parts = [...$parts, ...self::dueDateParts($filters)];

        if (! empty($filters['q'])) {
            $parts[] = __('saved_view.summary.search', ['value' => $filters['q']]);
        }

        if ($sort !== null) {
            $value = __('saved_view.sorts.'.$sort);

            if ($dir !== null) {
                $value .= ' ('.__('saved_view.directions.'.$dir).')';
            }

            $parts[] = __('saved_view.summary.sort', ['value' => $value]);
        }

        if ($parts === []) {
            return null;
        }

        return implode(' · ', $parts);
    }

    /**
     * The filtered project's name, resolved only when the viewer may
     * see the project: their roster covers it, or they are the
     * unrestricted workspace owner. A null viewer (trusted internal
     * call) keeps the historical unscoped resolution.
     */
    private static function visibleProjectName(Workspace $workspace, string $projectId, ?User $viewer): ?string
    {
        if ($viewer !== null) {
            $scope = app(WorkspaceContext::class)->accessibleProjectIds($viewer);

            if ($scope !== null && ! in_array($projectId, $scope, true)) {
                return null;
            }
        }

        return $workspace->projects()->whereKey($projectId)->value('name');
    }

    /**
     * The filtered milestone's title, resolved only when its project
     * belongs to the workspace and the viewer may see that project —
     * the same D2 rule as the project name. A null viewer (trusted
     * internal call) keeps the unscoped resolution.
     */
    private static function visibleMilestoneName(Workspace $workspace, string $milestoneId, ?User $viewer): ?string
    {
        $query = $workspace->projects()
            ->join('milestones', 'milestones.project_id', '=', 'projects.id')
            ->where('milestones.id', $milestoneId)
            ->select('projects.id as project_id', 'milestones.title as milestone_title');

        if ($viewer !== null) {
            $scope = app(WorkspaceContext::class)->accessibleProjectIds($viewer);

            if ($scope !== null) {
                $query->whereIn('projects.id', $scope);
            }
        }

        $row = $query->first();

        return $row?->milestone_title;
    }

    /**
     * @param  array<string, string|array<int, string>|null>  $filters
     * @return list<string>
     */
    private static function dueDateParts(array $filters): array
    {
        $after = $filters['due_after'] ?? null;
        $before = $filters['due_before'] ?? null;

        if ($after !== null && $before !== null) {
            return [__('saved_view.summary.due_between', ['after' => $after, 'before' => $before])];
        }

        if ($before !== null) {
            return [__('saved_view.summary.due_before', ['date' => $before])];
        }

        if ($after !== null) {
            return [__('saved_view.summary.due_after', ['date' => $after])];
        }

        return [];
    }
}
