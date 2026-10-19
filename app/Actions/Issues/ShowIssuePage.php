<?php

namespace App\Actions\Issues;

use App\Models\Issue;
use App\Models\User;
use App\Models\Workspace;
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
            'issue' => [
                'id' => $issue->id,
                'title' => $issue->title,
                'description' => $issue->description,
                'createdAt' => $issue->created_at?->toISOString(),
                'creatorName' => $issue->creator?->name,
                'project' => ['id' => $issue->project->id, 'name' => $issue->project->name],
                'status' => ['id' => $issue->status->id, 'name' => $issue->status->name, 'category' => $issue->status->category->value],
                'priority' => $issue->priority->value,
                'dueDate' => $issue->due_date?->toISOString(),
                'overdue' => $issue->isOverdue(),
                'estimatedHours' => $issue->estimated_hours !== null ? (float) $issue->estimated_hours : null,
                'loggedHours' => $issue->logged_hours,
                'remainingHours' => $issue->remaining_hours,
                'assignees' => $issue->assignees->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->values()->all(),
                'labels' => $issue->labels->map(fn ($label) => ['id' => $label->id, 'name' => $label->name, 'color' => $label->color])->values()->all(),
                'parent' => $parent ? [
                    'id' => $parent->id,
                    'title' => $parent->title,
                    'statusName' => $parent->status?->name,
                ] : null,
                'children' => $children->map(fn (Issue $child) => [
                    'id' => $child->id,
                    'title' => $child->title,
                    'statusName' => $child->status?->name,
                    'assignees' => $child->assignees->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->values()->all(),
                ])->values()->all(),
            ],
            'comments' => $comments->map(fn ($comment) => [
                'id' => $comment->id,
                'body' => $comment->body,
                'authorId' => $comment->author_id,
                'authorName' => $comment->author?->name ?? 'Unknown',
                'createdAt' => $comment->created_at?->toISOString(),
            ])->values()->all(),
            'timeEntries' => $timeEntries->map(fn ($entry) => [
                'id' => $entry->id,
                'authorId' => $entry->author_id,
                'authorName' => $entry->author?->name ?? 'Unknown',
                'workedAt' => $entry->worked_at?->toISOString(),
                'hours' => (float) $entry->hours,
                'note' => $entry->note,
            ])->values()->all(),
            'statuses' => $workspace->statuses()->get(['id', 'name', 'category']),
            'members' => $workspace->members()->orderBy('name')->get(['users.id', 'users.name']),
            'labels' => $workspace->labels()->orderBy('name')->get(['id', 'name', 'color']),
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
     * @return array<int, Issue>
     */
    private function candidateParents(Issue $issue, ?User $user): array
    {
        $scope = $user !== null
            ? app(WorkspaceContext::class)->accessibleProjectIds($user)
            : null;

        return Issue::query()
            ->whereKeyNot($issue->id)
            ->when($scope !== null, fn ($query) => $query->whereIn('project_id', $scope))
            ->orderBy('title')
            ->get(['id', 'title'])
            ->all();
    }
}
