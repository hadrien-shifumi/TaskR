<?php

namespace App\Actions\Issues;

use App\Models\Issue;
use App\Models\User;
use App\Models\Workspace;
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

        $issue->load(['project', 'status', 'labels', 'assignees', 'timeEntries.author', 'creator']);
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

        return [
            'issue' => IssuePresenter::detail($issue, $parent, $children),
            'isOwner' => $user !== null && $workspace->hasOwner($user),
            'comments' => $comments->map(fn ($comment) => CommentPresenter::detail($comment))->values()->all(),
            'timeEntries' => $timeEntries->map(fn ($entry) => TimeEntryPresenter::detail($entry))->values()->all(),
            'statuses' => $workspace->statuses()->get()->map(fn ($status) => StatusPresenter::option($status))->all(),
            'members' => $workspace->members()->orderBy('name')->get()->map(fn ($member) => ProjectMemberPresenter::person($member))->all(),
            'labels' => $workspace->labels()->orderBy('name')->get()->map(fn ($label) => LabelPresenter::present($label))->all(),
            'candidates' => $this->candidateParents($issue, $user),
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
}
