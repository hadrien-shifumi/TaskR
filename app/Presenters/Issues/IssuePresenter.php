<?php

namespace App\Presenters\Issues;

use App\Enums\StatusCategory;
use App\Models\Issue;
use BackedEnum;
use Illuminate\Support\Collection;

/**
 * CamelCase array shapes of an issue for Inertia pages. Static methods,
 * one per card variant — the query actions load the relations and
 * delegate every model-to-array mapping here.
 */
class IssuePresenter
{
    /**
     * Full card of the transverse issues list (list view, MCP layer). The
     * knowledge badge rides the loaded knowledgePages relation: the count
     * and the tooltip titles, sorted by title.
     *
     * @return array{id: string, title: string, project: array{id: string, name: string}, status: array{id: string, name: string, category: string}, priority: string, dueDate: string|null, overdue: bool, blocked: bool, unblockable: bool, assignees: array<int, array{id: int, name: string}>, labels: array<int, array{id: string, name: string, color: string}>, knowledgePagesCount: int, knowledgePageTitles: array<int, string>}
     */
    public static function card(Issue $issue, ?Collection $activeDescendants = null): array
    {
        return [
            'id' => $issue->id,
            'title' => $issue->title,
            'project' => ['id' => $issue->project->id, 'name' => $issue->project->name],
            'status' => ['id' => $issue->status->id, 'name' => $issue->status->name, 'category' => $issue->status->category->value],
            'priority' => $issue->priority->value,
            'dueDate' => $issue->due_date?->toISOString(),
            'overdue' => $issue->isOverdue(),
            'blocked' => self::hasOpenBlocker($issue),
            'unblockable' => self::isUnblockable($issue),
            'hasOpenChild' => self::hasOpenChild($issue),
            'descendants' => self::descendantSummary($issue, $activeDescendants),
            'assignees' => self::assignees($issue),
            'labels' => $issue->labels->map(fn ($label) => ['id' => $label->id, 'name' => $label->name, 'color' => $label->color])->values()->all(),
            'knowledgePagesCount' => $issue->knowledgePages->count(),
            'knowledgePageTitles' => self::knowledgePageTitles($issue),
        ];
    }

    /**
     * Kanban card of the transverse view — a card() stripped of the
     * project and status wrappers (the board already carries them).
     *
     * @param  array<string, mixed>  $card  A card() result
     * @return array{id: string, title: string, priority: string, dueDate: string|null, overdue: bool, blocked: bool, unblockable: bool, assignees: array<int, array{id: int, name: string}>, labels: array<int, array{id: string, name: string, color: string}>, knowledgePagesCount: int, knowledgePageTitles: array<int, string>}
     */
    public static function kanbanCard(array $card): array
    {
        return [
            'id' => $card['id'],
            'title' => $card['title'],
            'priority' => $card['priority'],
            'dueDate' => $card['dueDate'],
            'overdue' => $card['overdue'],
            'blocked' => $card['blocked'],
            'unblockable' => $card['unblockable'],
            'hasOpenChild' => $card['hasOpenChild'],
            'descendants' => $card['descendants'],
            'assignees' => $card['assignees'],
            'labels' => $card['labels'],
            'knowledgePagesCount' => $card['knowledgePagesCount'],
            'knowledgePageTitles' => $card['knowledgePageTitles'],
        ];
    }

    /**
     * Card of a project board column. The knowledge badge rides the loaded
     * knowledgePages relation, like card().
     *
     * @return array{id: string, title: string, priority: string, dueDate: string|null, overdue: bool, blocked: bool, unblockable: bool, assignees: array<int, array{id: int, name: string}>, knowledgePagesCount: int, knowledgePageTitles: array<int, string>}
     */
    public static function boardCard(Issue $issue, ?Collection $activeDescendants = null): array
    {
        return [
            'id' => $issue->id,
            'title' => $issue->title,
            'priority' => $issue->priority->value,
            'dueDate' => $issue->due_date?->toDateString(),
            'overdue' => $issue->isOverdue(),
            'blocked' => self::hasOpenBlocker($issue),
            'unblockable' => self::isUnblockable($issue),
            'hasOpenChild' => self::hasOpenChild($issue),
            'descendants' => self::descendantSummary($issue, $activeDescendants),
            'assignees' => self::assignees($issue),
            'knowledgePagesCount' => $issue->knowledgePages->count(),
            'knowledgePageTitles' => self::knowledgePageTitles($issue),
        ];
    }

    /**
     * Card of the dashboard's "my issues" block.
     *
     * @return array{id: string, title: string, projectName: string, statusName: string, statusCategory: string, priority: string, dueDate: string|null, overdue: bool}
     */
    public static function homeCard(Issue $issue): array
    {
        return [
            'id' => $issue->id,
            'title' => $issue->title,
            'projectName' => $issue->project->name,
            'statusName' => $issue->status->name,
            'statusCategory' => $issue->status->category->value,
            'priority' => $issue->priority->value,
            'dueDate' => $issue->due_date?->toDateString(),
            'overdue' => $issue->isOverdue(),
        ];
    }

    /**
     * Full fiche payload of an issue, parent and children included.
     *
     * @param  Collection<int, Issue>|iterable  $children
     * @return array<string, mixed>
     */
    public static function detail(Issue $issue, ?Issue $parent = null, iterable $children = [], ?Collection $activeDescendants = null): array
    {
        return [
            'id' => $issue->id,
            'title' => $issue->title,
            'description' => $issue->description,
            'createdAt' => $issue->created_at?->toISOString(),
            'updatedAt' => $issue->updated_at?->toISOString(),
            'creatorName' => $issue->creator?->name,
            'project' => ['id' => $issue->project->id, 'name' => $issue->project->name],
            'status' => ['id' => $issue->status->id, 'name' => $issue->status->name, 'category' => $issue->status->category->value],
            'priority' => $issue->priority->value,
            'dueDate' => $issue->due_date?->toISOString(),
            'overdue' => $issue->isOverdue(),
            'estimatedHours' => $issue->estimated_hours !== null ? (float) $issue->estimated_hours : null,
            'loggedHours' => $issue->logged_hours,
            'remainingHours' => $issue->remaining_hours,
            'isArchived' => $issue->isArchived(),
            'archivedAt' => $issue->archived_at?->toISOString(),
            'assignees' => self::assignees($issue),
            'labels' => $issue->labels->map(fn ($label) => ['id' => $label->id, 'name' => $label->name, 'color' => $label->color])->values()->all(),
            'parent' => $parent !== null ? self::parentSummary($parent) : null,
            'children' => collect($children)->map(fn (Issue $child) => self::childSummary($child))->values()->all(),
            'descendants' => self::descendantSummary($issue, $activeDescendants),
            'blockedBy' => $issue->blockedBy->map(fn (Issue $blocker) => self::dependencySummary($blocker))->values()->all(),
            'blocks' => $issue->blocks->map(fn (Issue $dependent) => self::dependencySummary($dependent))->values()->all(),
            'knowledgePages' => self::knowledgePages($issue),
            'isBlocked' => self::hasOpenBlocker($issue),
            'isUnblockable' => self::isUnblockable($issue),
            'autoCompleteChildren' => $issue->auto_complete_children,
        ];
    }

    /**
     * Parent teaser shown on the fiche.
     *
     * @return array{id: string, title: string, statusName: string|null}
     */
    public static function parentSummary(Issue $issue): array
    {
        return [
            'id' => $issue->id,
            'title' => $issue->title,
            'statusName' => $issue->status?->name,
        ];
    }

    /**
     * Child teaser listed on the fiche. The status id and category let a
     * screen compare a child with a target status without a round trip.
     *
     * @return array{id: string, title: string, statusId: string|null, statusName: string|null, statusCategory: string|null, assignees: array<int, array{id: int, name: string}>}
     */
    public static function childSummary(Issue $issue): array
    {
        return [
            'id' => $issue->id,
            'title' => $issue->title,
            'statusId' => $issue->status?->id,
            'statusName' => $issue->status?->name,
            'statusCategory' => $issue->status?->category?->value,
            'assignees' => self::assignees($issue),
        ];
    }

    /**
     * Active-descendant summary of an issue: the total and the count per
     * status id. The screens compare it with the target status to decide
     * whether a move must offer to bring the whole subtree along — and the
     * move action enforces its Done guard on the very same set. The flat
     * descendant collection comes from LoadActiveDescendants (archived
     * descendants and out-of-scope subtrees already excluded); a null
     * collection means "nothing to count" — the truthful answer for an
     * archived issue, whose whole subtree left the scope with it.
     *
     * @param  Collection<int, Issue>  $descendants
     * @return array{total: int, byStatus: array<string, int>}
     */
    public static function descendantSummary(Issue $issue, ?Collection $descendants = null): array
    {
        if ($descendants === null || $descendants->isEmpty()) {
            return ['total' => 0, 'byStatus' => []];
        }

        $byParent = $descendants->groupBy('parent_id');

        $total = 0;
        $byStatus = [];
        $parentIds = [$issue->getKey()];

        while ($parentIds !== []) {
            $parentId = array_pop($parentIds);

            foreach ($byParent->get($parentId, collect()) as $descendant) {
                $total++;

                $statusId = $descendant->status?->id;
                if ($statusId !== null) {
                    $byStatus[$statusId] = ($byStatus[$statusId] ?? 0) + 1;
                }

                $parentIds[] = $descendant->getKey();
            }
        }

        return ['total' => $total, 'byStatus' => $byStatus];
    }

    /**
     * Late issue row of the project health page. The rows come from a
     * join, so priority and statusCategory arrive as enums or raw
     * strings depending on hydration.
     *
     * @return array{id: string, title: string, priority: string, dueDate: string|null, statusName: string, statusCategory: string}
     */
    public static function lateIssue(Issue $issue): array
    {
        return [
            'id' => $issue->id,
            'title' => $issue->title,
            'priority' => $issue->priority instanceof BackedEnum ? $issue->priority->value : (string) $issue->priority,
            'dueDate' => $issue->due_date?->toDateString(),
            'statusName' => $issue->statusName,
            'statusCategory' => $issue->statusCategory instanceof BackedEnum ? $issue->statusCategory->value : (string) $issue->statusCategory,
        ];
    }

    /**
     * Dependency teaser listed on the fiche — a parentSummary shape
     * plus the status category the blocked/unblockable badges key on.
     *
     * @return array{id: string, title: string, statusName: string|null, statusCategory: string|null}
     */
    private static function dependencySummary(Issue $blocker): array
    {
        return [
            'id' => $blocker->id,
            'title' => $blocker->title,
            'statusName' => $blocker->status?->name,
            'statusCategory' => $blocker->status?->category?->value,
        ];
    }

    /**
     * Knowledge pages linked to the issue, ordered by title. The relation
     * must be loaded by the querying action; trashed pages are kept and
     * flagged, they only show up when the action loaded them withTrashed.
     *
     * @return array<int, array{id: string, title: string, projectId: string|null, trashed: bool}>
     */
    private static function knowledgePages(Issue $issue): array
    {
        return $issue->knowledgePages
            ->sortBy('title')
            ->map(fn ($page) => [
                'id' => $page->id,
                'title' => $page->title,
                'projectId' => $page->project_id,
                'trashed' => $page->trashed(),
            ])
            ->values()
            ->all();
    }

    /**
     * Titles of the linked knowledge pages, ordered by title — the tooltip
     * of the compact cards' 📖 badge. The relation must be loaded by the
     * querying action.
     *
     * @return array<int, string>
     */
    private static function knowledgePageTitles(Issue $issue): array
    {
        return $issue->knowledgePages
            ->sortBy('title')
            ->map(fn ($page) => $page->title)
            ->values()
            ->all();
    }

    /**
     * An issue is blocked while at least one of its blockers is open,
     * i.e. its status category is neither done nor cancelled — a
     * cancelled blocker releases the dependency, in line with
     * Issue::isOverdue(). The blockedBy relation must be loaded with
     * status by the querying action.
     */
    private static function hasOpenBlocker(Issue $issue): bool
    {
        return $issue->blockedBy->contains(
            fn (Issue $blocker) => ! in_array($blocker->status?->category, [StatusCategory::Done, StatusCategory::Cancelled], true),
        );
    }

    /**
     * A reopened child signal: at least one active child of this issue
     * carries a non-terminal category. The flag rides the `withExists`
     * subquery applied by the listing actions; absent there, it reads false.
     */
    private static function hasOpenChild(Issue $issue): bool
    {
        return (bool) $issue->getAttribute('children_exists');
    }

    /**
     * Every blocker is done or cancelled: the issue can be moved forward.
     * False when there is no blocker at all.
     */
    private static function isUnblockable(Issue $issue): bool
    {
        return $issue->blockedBy->isNotEmpty() && ! self::hasOpenBlocker($issue);
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private static function assignees(Issue $issue): array
    {
        return $issue->assignees->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->values()->all();
    }
}
