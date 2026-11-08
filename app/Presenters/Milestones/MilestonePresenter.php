<?php

namespace App\Presenters\Milestones;

use App\Models\Issue;
use App\Models\Milestone;
use App\Presenters\Issues\IssuePresenter;

/**
 * CamelCase array shapes of a milestone for Inertia pages and JSON
 * feeds. Static methods, one per shape — the query actions load the
 * relations and delegate every model-to-array mapping here.
 */
class MilestonePresenter
{
    /**
     * Full card of a project's milestone feed (JSON list, the project's
     * Échéances tab, MCP layer). The issue counts ride the withCount
     * columns loaded by the querying action (issues_count,
     * done_issues_count) — unarchived issues only, and "done" follows
     * the status category, like the project health aggregates.
     *
     * @return array{id: string, title: string, description: string|null, projectId: string, parentId: string|null, startDate: string|null, targetDate: string|null, completedAt: string|null, overdue: bool, creatorName: string|null, totalIssues: int, doneIssues: int}
     */
    public static function card(Milestone $milestone): array
    {
        return [
            'id' => $milestone->id,
            'title' => $milestone->title,
            'description' => $milestone->description,
            'projectId' => $milestone->project_id,
            'parentId' => $milestone->parent_id,
            'startDate' => $milestone->start_date?->toDateString(),
            'targetDate' => $milestone->target_date?->toDateString(),
            'completedAt' => $milestone->completed_at?->toISOString(),
            'overdue' => $milestone->isOverdue(),
            'creatorName' => $milestone->creator?->name,
            'totalIssues' => (int) $milestone->issues_count,
            'doneIssues' => (int) $milestone->done_issues_count,
        ];
    }

    /**
     * Option row of the issue dialog's milestone select.
     *
     * @return array{id: string, title: string}
     */
    public static function option(Milestone $milestone): array
    {
        return [
            'id' => $milestone->id,
            'title' => $milestone->title,
        ];
    }

    /**
     * Teaser of the milestone attached to an issue, shown on the issue
     * fiche and cards: the dates and the late flag let a screen render
     * the badge without a round trip.
     *
     * @return array{id: string, title: string, startDate: string|null, targetDate: string|null, completedAt: string|null, overdue: bool}
     */
    public static function summary(Milestone $milestone): array
    {
        return [
            'id' => $milestone->id,
            'title' => $milestone->title,
            'startDate' => $milestone->start_date?->toDateString(),
            'targetDate' => $milestone->target_date?->toDateString(),
            'completedAt' => $milestone->completed_at?->toISOString(),
            'overdue' => $milestone->isOverdue(),
        ];
    }

    /**
     * Full fiche payload of the milestone detail page: the card fields
     * plus the parent teaser, the child cards and the attached issue
     * cards. Same loading contract as card() for the counts.
     *
     * @param  iterable<int, Milestone>  $children
     * @param  iterable<int, Issue>  $issues
     * @return array<string, mixed>
     */
    public static function detail(Milestone $milestone, ?Milestone $parent = null, iterable $children = [], iterable $issues = []): array
    {
        return [
            ...self::card($milestone),
            'parent' => $parent !== null ? ['id' => $parent->id, 'title' => $parent->title] : null,
            'children' => collect($children)->map(fn (Milestone $child) => self::card($child))->values()->all(),
            'issues' => collect($issues)->map(fn (Issue $issue) => IssuePresenter::card($issue))->values()->all(),
        ];
    }
}
