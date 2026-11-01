<?php

namespace App\Presenters\Mentions;

use App\Models\CommentMention;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Support\Str;

class MentionPresenter
{
    /**
     * Row of the mentions page: who mentioned the account, on which
     * issue, with a one-line preview of the comment.
     *
     * @return array{id: string, type: string, commentId: string, authorName: string, issueId: string, issueTitle: string, excerpt: string, readAt: string|null, createdAt: string|null}
     */
    public static function detail(CommentMention $mention): array
    {
        $comment = $mention->comment;
        $issue = $comment->issue;

        return [
            'id' => $mention->id,
            'type' => $mention->type->value,
            'commentId' => $comment->id,
            'authorName' => $comment->author?->name ?? 'Unknown',
            'issueId' => $issue->id,
            'issueTitle' => $issue->title,
            'excerpt' => self::excerpt($comment->body),
            'readAt' => $mention->read_at?->toISOString(),
            'createdAt' => $mention->created_at?->toISOString(),
        ];
    }

    /**
     * Compact autocomplete list of the comment editor: workspace members
     * then projects, each `{handle, name, type}`, trimmed to the
     * account's reachable perimeter (a guest only gets its roster).
     *
     * @return list<array{handle: string, name: string, type: string}>
     */
    public static function mentionables(Workspace $workspace, User $user): array
    {
        $scope = app(WorkspaceContext::class)->accessibleProjectIds($user);

        $projects = $workspace->projects()
            ->when($scope !== null, fn ($query) => $query->whereIn('id', $scope))
            ->get()
            ->sortBy(fn (Project $project) => mb_strtolower($project->name))
            ->values();

        $users = $scope === null
            ? $workspace->members()->get()
            : User::query()
                ->whereIn('id', ProjectMember::query()
                    ->whereIn('project_id', $projects->pluck('id'))
                    ->pluck('user_id'))
                ->get();

        $users = $users->sortBy(fn (User $member) => mb_strtolower($member->name))->values();

        return [
            ...$users->map(fn (User $member) => ['handle' => $member->handle, 'name' => $member->name, 'type' => 'user'])->all(),
            ...$projects->map(fn (Project $project) => ['handle' => $project->handle, 'name' => $project->name, 'type' => 'project'])->all(),
        ];
    }

    /**
     * One-line preview of a markdown comment body.
     */
    private static function excerpt(string $body): string
    {
        $text = (string) preg_replace('/[#*_`>~\[\]!]/u', '', $body);
        $text = (string) preg_replace('/\s+/u', ' ', trim($text));

        return Str::limit($text, 140);
    }
}
