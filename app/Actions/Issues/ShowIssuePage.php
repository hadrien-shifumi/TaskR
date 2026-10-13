<?php

namespace App\Actions\Issues;

use App\Models\Issue;
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
     * @return array<string, mixed>
     */
    public function handle(Workspace $workspace, Issue $issue): array
    {
        $issue->load(['project', 'status', 'labels', 'assignees']);
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

        return [
            'issue' => [
                'id' => $issue->id,
                'title' => $issue->title,
                'description' => $issue->description,
                'project' => ['id' => $issue->project->id, 'name' => $issue->project->name],
                'status' => ['id' => $issue->status->id, 'name' => $issue->status->name, 'category' => $issue->status->category->value],
                'priority' => $issue->priority->value,
                'dueDate' => $issue->due_date?->toISOString(),
                'overdue' => $issue->isOverdue(),
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
            'statuses' => $workspace->statuses()->get(['id', 'name', 'category']),
            'members' => $workspace->members()->orderBy('name')->get(['users.id', 'users.name']),
            'labels' => $workspace->labels()->orderBy('name')->get(['id', 'name', 'color']),
            'candidates' => Issue::query()
                ->whereKeyNot($issue->id)
                ->orderBy('title')
                ->get(['id', 'title']),
        ];
    }

    public function asController(Request $request, Issue $issue, IssuePageResponder $responder): Response
    {
        $workspace = app(WorkspaceContext::class)->getOrFail();

        return $responder->toResponse($request, $this->handle($workspace, $issue));
    }
}
