<?php

namespace App\Actions\Notifications;

use App\Actions\Projects\GetProjectHealth;
use App\Enums\StatusCategory;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Status;
use App\Presenters\Comments\CommentPresenter;
use App\Presenters\Issues\IssuePresenter;
use App\Support\DigestContent;
use App\Support\DigestContext;
use App\Support\PersonWorkload;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Aggregate everything worth telling an account about one workspace.
 *
 * Read-only: never writes, in particular never touches
 * notification_preferences.last_digest_at — the sending job owns it.
 * The workspace comes from the context; every query is scoped to it, to
 * the projects the user may access (a guest's roster) and to unarchived
 * projects. Dates are computed in the account timezone.
 */
class BuildDigest
{
    use AsObject;

    public function handle(DigestContext $context): DigestContent
    {
        $projectIds = $this->accessibleProjectIds($context);

        if ($projectIds === []) {
            return $this->emptyContent();
        }

        $today = $context->now->startOfDay();
        $windowStart = $context->windowStart();

        return new DigestContent(
            overdueIssues: $this->overdueIssues($context, $projectIds, $today),
            upcomingIssues: $this->upcomingIssues($context, $projectIds, $today),
            newAssignments: $this->newAssignments($context, $projectIds, $windowStart),
            newComments: $this->newComments($context, $projectIds, $windowStart),
            workload: $this->workload($context, $projectIds)->toArray(),
            managerHighlights: $this->managerHighlights($context, $projectIds),
        );
    }

    /**
     * Projects of the workspace the user may see: null means every project
     * of the workspace (owner, member), a list is a guest's roster and an
     * empty list means the digest is empty without a single query.
     *
     * The context workspace is bound for the duration of the call so the
     * resolution cannot depend on whatever workspace the caller had set.
     *
     * @return list<string>|null
     */
    private function accessibleProjectIds(DigestContext $context): ?array
    {
        $workspaceContext = app(WorkspaceContext::class);
        $previous = $workspaceContext->get();
        $workspaceContext->set($context->workspace);

        try {
            return $workspaceContext->accessibleProjectIds($context->user);
        } finally {
            $workspaceContext->set($previous);
        }
    }

    /**
     * My open issues due before today, oldest first, with their delay.
     *
     * @param  list<string>|null  $projectIds
     * @return array<int, array<string, mixed>>
     */
    private function overdueIssues(DigestContext $context, ?array $projectIds, CarbonInterface $today): array
    {
        return $this->openIssuesAssignedTo($context, $projectIds)
            ->whereNotNull('issues.due_date')
            ->whereDate('issues.due_date', '<', $today->toDateString())
            ->orderBy('issues.due_date')
            ->get()
            ->map(fn (Issue $issue) => [
                ...IssuePresenter::homeCard($issue),
                'daysLate' => (int) floor($issue->due_date->startOfDay()->diffInDays($today)),
            ])
            ->all();
    }

    /**
     * My open issues due from today to today + threshold, soonest first.
     *
     * @param  list<string>|null  $projectIds
     * @return array<int, array<string, mixed>>
     */
    private function upcomingIssues(DigestContext $context, ?array $projectIds, CarbonInterface $today): array
    {
        $limit = $today->copy()->addDays($context->thresholdDays());

        return $this->openIssuesAssignedTo($context, $projectIds)
            ->whereNotNull('issues.due_date')
            ->whereDate('issues.due_date', '>=', $today->toDateString())
            ->whereDate('issues.due_date', '<=', $limit->toDateString())
            ->orderBy('issues.due_date')
            ->get()
            ->map(fn (Issue $issue) => [
                ...IssuePresenter::homeCard($issue),
                'daysUntilDue' => (int) floor($today->diffInDays($issue->due_date->startOfDay())),
            ])
            ->all();
    }

    /**
     * Issues assigned to me since the window start, still open.
     *
     * @param  list<string>|null  $projectIds
     * @return array<int, array<string, mixed>>
     */
    private function newAssignments(DigestContext $context, ?array $projectIds, CarbonInterface $windowStart): array
    {
        return $this->openIssuesAssignedTo($context, $projectIds)
            ->where('issue_assignees.assigned_at', '>=', $windowStart->toDateTimeString())
            ->orderByDesc('issue_assignees.assigned_at')
            ->get()
            ->map(fn (Issue $issue) => [
                ...IssuePresenter::homeCard($issue),
                'assignedAt' => $issue->pivot_assigned_at !== null
                    ? CarbonImmutable::parse($issue->pivot_assigned_at)->toISOString()
                    : null,
            ])
            ->all();
    }

    /**
     * Comments written by someone else since the window start, on the
     * issues I follow (assigned to me or created by me).
     *
     * @param  list<string>|null  $projectIds
     * @return array<int, array<string, mixed>>
     */
    private function newComments(DigestContext $context, ?array $projectIds, CarbonInterface $windowStart): array
    {
        $followed = Issue::query()
            ->unarchived()
            ->where(fn (Builder $query) => $query
                ->where('issues.created_by', $context->user->getKey())
                ->orWhereHas('assignees', fn (Builder $assignees) => $assignees
                    ->where('users.id', $context->user->getKey())));

        $this->scopeToProjects($followed, $projectIds);

        return Comment::query()
            ->where('comments.created_at', '>=', $windowStart->toDateTimeString())
            ->where('comments.author_id', '!=', $context->user->getKey())
            ->whereIn('comments.issue_id', $followed->select('issues.id'))
            ->with(['author', 'issue.project', 'issue.status'])
            ->orderByDesc('comments.created_at')
            ->get()
            ->map(fn (Comment $comment) => [
                ...CommentPresenter::present($comment),
                'issue' => [
                    'id' => $comment->issue->id,
                    'title' => $comment->issue->title,
                    'projectName' => $comment->issue->project->name,
                ],
            ])
            ->all();
    }

    /**
     * Remaining work: estimates minus logged hours over my open issues.
     *
     * @param  list<string>|null  $projectIds
     */
    private function workload(DigestContext $context, ?array $projectIds): PersonWorkload
    {
        $issues = $this->openIssuesAssignedTo($context, $projectIds)
            // The logged-hours sum rides the query instead of loading
            // every time entry row of every open issue.
            ->withSum('timeEntries as logged_hours_sum', 'hours')
            ->get();

        $estimatedHours = (float) $issues->sum(fn (Issue $issue) => (float) $issue->estimated_hours);
        $loggedHours = (float) $issues->sum(fn (Issue $issue) => (float) ($issue->logged_hours_sum ?? 0));

        return new PersonWorkload(
            userId: $context->user->getKey(),
            userName: $context->user->name,
            openIssues: $issues->count(),
            totalIssues: $issues->count(),
            overdueIssues: $issues->filter(fn (Issue $issue) => $issue->isOverdue())->count(),
            estimatedHours: round($estimatedHours, 2),
            loggedHours: round($loggedHours, 2),
        );
    }

    /**
     * Manager-only KPIs: late issues plus the gap between the real and the
     * expected completion. A guest is never a manager, even when the
     * project roster says so.
     *
     * @param  list<string>|null  $projectIds
     * @return array<int, array<string, mixed>>
     */
    private function managerHighlights(DigestContext $context, ?array $projectIds): array
    {
        if ($context->workspace->isGuest($context->user)) {
            return [];
        }

        $projects = Project::query()
            ->active()
            ->when($projectIds !== null, fn (Builder $query) => $query->whereIn('id', $projectIds))
            ->orderBy('name')
            ->get()
            ->filter(fn (Project $project) => $project->canManage($context->user))
            ->values()
            ->all();

        if ($projects === []) {
            return [];
        }

        $health = app(GetProjectHealth::class)->handleForProjects($projects);

        $highlights = [];
        foreach ($projects as $project) {
            $projectHealth = $health[$project->id];
            $completion = $projectHealth->completionPercentage;
            $expected = $projectHealth->expectedPercentage;

            // Nothing to highlight: no late issue and no measurable gap.
            if ($projectHealth->overdueIssues === 0
                && ($completion === null || $expected === null || $completion >= $expected)) {
                continue;
            }

            $highlights[] = [
                'projectId' => $project->id,
                'projectName' => $project->name,
                'overdueIssues' => $projectHealth->overdueIssues,
                'completionPercentage' => $completion,
                'expectedPercentage' => $expected,
                'gap' => $completion !== null && $expected !== null
                    ? round($completion - $expected, 1)
                    : 0.0,
                'status' => $projectHealth->status,
            ];
        }

        return $highlights;
    }

    /**
     * Base query: my open issues, with project and status loaded for the
     * presenters. The pivot row is joined so the assignment date is
     * reachable as `pivot_assigned_at`.
     *
     * @param  list<string>|null  $projectIds
     */
    private function openIssuesAssignedTo(DigestContext $context, ?array $projectIds): Builder
    {
        $issues = (new Issue)->newQuery()
            ->whereIn('issues.status_id', $this->openStatusIds())
            ->whereHas('project', fn (Builder $query) => $query->active())
            ->join('issue_assignees', 'issue_assignees.issue_id', '=', 'issues.id')
            ->where('issue_assignees.user_id', $context->user->getKey())
            ->select('issues.*', 'issue_assignees.assigned_at as pivot_assigned_at')
            ->with(['project', 'status'])
            ->unarchived();

        $this->scopeToProjects($issues, $projectIds);

        return $issues;
    }

    /**
     * Ids of the open statuses (backlog, in progress) of the workspace.
     *
     * @return list<string>
     */
    private function openStatusIds(): array
    {
        return Status::query()
            ->whereIn('category', [StatusCategory::Backlog->value, StatusCategory::InProgress->value])
            ->pluck('id')
            ->all();
    }

    /**
     * Restrict a query to the accessible projects; null leaves it open.
     *
     * @param  list<string>|null  $projectIds
     */
    private function scopeToProjects(Builder $query, ?array $projectIds): Builder
    {
        if ($projectIds === null) {
            return $query;
        }

        return $query->whereIn('issues.project_id', $projectIds);
    }

    private function emptyContent(): DigestContent
    {
        return new DigestContent(
            overdueIssues: [],
            upcomingIssues: [],
            newAssignments: [],
            newComments: [],
            workload: (new PersonWorkload(
                userId: 0,
                userName: '',
                openIssues: 0,
                totalIssues: 0,
                overdueIssues: 0,
                estimatedHours: 0.0,
                loggedHours: 0.0,
            ))->toArray(),
            managerHighlights: [],
        );
    }
}
