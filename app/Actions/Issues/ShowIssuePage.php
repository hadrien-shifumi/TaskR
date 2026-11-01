<?php

namespace App\Actions\Issues;

use App\Models\AuditEvent;
use App\Models\Issue;
use App\Models\User;
use App\Models\Workspace;
use App\Presenters\Audit\AuditEventPresenter;
use App\Presenters\Comments\CommentPresenter;
use App\Presenters\Issues\IssuePresenter;
use App\Presenters\Labels\LabelPresenter;
use App\Presenters\Projects\ProjectMemberPresenter;
use App\Presenters\Statuses\StatusPresenter;
use App\Presenters\TimeTracking\TimeEntryPresenter;
use App\Responders\Issues\IssuePageResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class ShowIssuePage
{
    use AsController;
    use AsObject;

    /**
     * @param  array<string, mixed>
     */
    public function handle(Workspace $workspace, Issue $issue, ?User $user = null): array
    {
        // Out-of-scope issues fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $issue);
        }

        $issue->load(['project', 'status', 'labels', 'assignees', 'timeEntries.author', 'creator', 'blockedBy.status', 'blocks.status']);
        $issue->loadCount('comments');

        $parent = $issue->parent()
            ->with('status')
            ->first();

        $children = $issue->children()
            ->with(['status', 'assignees'])
            ->orderBy('created_at')
            ->get();

        $comments = $issue->comments()
            ->with('author')
            ->orderBy('created_at')
            ->get();

        $timeEntries = $issue->timeEntries
            ->sortByDesc('worked_at')
            ->sortByDesc('created_at')
            ->values();

        // Descending audit timeline, newest first; the id breaks ties within
        // the same second. Capped at 100 rows to keep the payload bounded.
        $activity = AuditEvent::query()
            ->where('issue_id', $issue->id)
            ->with('actor')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn ($event) => AuditEventPresenter::detail($event))
            ->values()
            ->all();

        $detail = IssuePresenter::detail($issue, $parent, $children);
        $detail['blocks'] = $this->withDependentBlockerIds($detail['blocks'], $issue);

        return [
            'issue' => $detail,
            'comments' => $comments->map(fn ($comment) => CommentPresenter::detail($comment))->values()->all(),
            'timeEntries' => $timeEntries->map(fn ($entry) => TimeEntryPresenter::detail($entry))->values()->all(),
            'activity' => $activity,
            'statuses' => $workspace->statuses()->get()->map(fn ($status) => StatusPresenter::option($status))->all(),
            'members' => $workspace->members()->orderBy('name')->get()->map(fn ($member) => ProjectMemberPresenter::person($member))->all(),
            'labels' => $workspace->labels()->orderBy('name')->get()->map(fn ($label) => LabelPresenter::present($label))->all(),
            'candidates' => $this->candidateParents($issue, $user),
            'dependencyCandidates' => $this->dependencyCandidates($issue, $user),
        ];
    }

    public function asController(Request $request, Issue $issue, IssuePageResponder $responder): Response
    {
        $workspace = app(WorkspaceContext::class)->getOrFail();

        return $responder->toResponse($request, $this->handle($workspace, $issue, $request->user()));
    }

    /**
     * Candidate parents: every issue of the workspace, minus the issue
     * itself. A guest only sees the issues of their perimeter — parent
     * candidates included, or the hierarchy would leak hidden titles.
     *
     * @return array<int, array{id: string, title: string}>
     */
    private function candidateParents(Issue $issue, ?User $user): array
    {
        $scope = $user !== null
            ? app(WorkspaceContext::class)->accessibleProjectIds($user)
            : null;

        return Issue::query()
            ->unarchived()
            ->whereKeyNot($issue->id)
            ->when($scope !== null, fn ($query) => $query->whereIn('project_id', $scope))
            ->orderBy('title')
            ->get(['id', 'title'])
            ->map(fn (Issue $candidate) => ['id' => $candidate->id, 'title' => $candidate->title])
            ->all();
    }

    /**
     * Mirror rows ("blocks") are deletable from this fiche: the sync route
     * replaces the dependent's whole blocker list, so each row carries its
     * blocker ids — the ✕ re-syncs it minus this issue in a single POST.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>
     */
    private function withDependentBlockerIds(array $blocks, Issue $issue): array
    {
        if ($blocks === []) {
            return $blocks;
        }

        $blockerIds = Issue::query()
            ->whereIn('id', collect($blocks)->pluck('id')->all())
            ->with('blockedBy')
            ->get()
            ->mapWithKeys(fn (Issue $dependent) => [
                $dependent->id => $dependent->blockedBy->modelKeys(),
            ]);

        return collect($blocks)
            ->map(fn (array $dependent) => [
                ...$dependent,
                'blockerIds' => $blockerIds[$dependent['id']] ?? [],
            ])
            ->all();
    }

    /**
     * Candidate blockers for the dependencies dialog: every unarchived
     * issue of the workspace, minus the issue itself and its already
     * linked blockers (sync would silently keep them anyway). Guests
     * only see their perimeter, like parent candidates.
     *
     * @return array<int, array{id: string, title: string}>
     */
    private function dependencyCandidates(Issue $issue, ?User $user): array
    {
        $scope = $user !== null
            ? app(WorkspaceContext::class)->accessibleProjectIds($user)
            : null;

        return Issue::query()
            ->unarchived()
            ->whereKeyNot($issue->id)
            ->whereNotIn('id', $issue->blockedBy->modelKeys())
            ->when($scope !== null, fn ($query) => $query->whereIn('project_id', $scope))
            ->orderBy('title')
            ->get(['id', 'title'])
            ->map(fn (Issue $candidate) => ['id' => $candidate->id, 'title' => $candidate->title])
            ->all();
    }
}
