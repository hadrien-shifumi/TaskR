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
     * Full card of the transverse issues list (list view, MCP layer).
     *
     * @return array{id: string, title: string, project: array{id: string, name: string}, status: array{id: string, name: string, category: string}, priority: string, dueDate: string|null, overdue: bool, blocked: bool, unblockable: bool, assignees: array<int, array{id: int, name: string}>, labels: array<int, array{id: string, name: string, color: string}>}
     */
    public static function card(Issue $issue): array
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
            'assignees' => self::assignees($issue),
            'labels' => $issue->labels->map(fn ($label) => ['id' => $label->id, 'name' => $label->name, 'color' => $label->color])->values()->all(),
        ];
    }

    /**
     * Kanban card of the transverse view — a card() stripped of the
     * project and status wrappers (the board already carries them).
     *
     * @param  array<string, mixed>  $card  A card() result
     * @return array{id: string, title: string, priority: string, dueDate: string|null, overdue: bool, blocked: bool, unblockable: bool, assignees: array<int, array{id: int, name: string}>, labels: array<int, array{id: string, name: string, color: string}>}
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
            'assignees' => $card['assignees'],
            'labels' => $card['labels'],
        ];
    }

    /**
     * Card of a project board column.
     *
     * @return array{id: string, title: string, priority: string, dueDate: string|null, overdue: bool, blocked: bool, unblockable: bool, assignees: array<int, array{id: int, name: string}>}
     */
    public static function boardCard(Issue $issue): array
    {
        return [
            'id' => $issue->id,
            'title' => $issue->title,
            'priority' => $issue->priority->value,
            'dueDate' => $issue->due_date?->toDateString(),
            'overdue' => $issue->isOverdue(),
            'blocked' => self::hasOpenBlocker($issue),
            'unblockable' => self::isUnblockable($issue),
            'assignees' => self::assignees($issue),
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
    public static function detail(Issue $issue, ?Issue $parent = null, iterable $children = []): array
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
            'blockedBy' => $issue->blockedBy->map(fn (Issue $blocker) => self::dependencySummary($blocker))->values()->all(),
            'blocks' => $issue->blocks->map(fn (Issue $dependent) => self::dependencySummary($dependent))->values()->all(),
            'isBlocked' => self::hasOpenBlocker($issue),
            'isUnblockable' => self::isUnblockable($issue),
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
     * Child teaser listed on the fiche.
     *
     * @return array{id: string, title: string, statusName: string|null, assignees: array<int, array{id: int, name: string}>}
     */
    public static function childSummary(Issue $issue): array
    {
        return [
            'id' => $issue->id,
            'title' => $issue->title,
            'statusName' => $issue->status?->name,
            'assignees' => self::assignees($issue),
        ];
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
