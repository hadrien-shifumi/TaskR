<?php

namespace App\Actions\Comments;

use App\Models\Issue;
use App\Models\User;
use App\Presenters\Comments\CommentPresenter;
use App\Support\WorkspaceContext;
use Lorisleiva\Actions\Concerns\AsObject;

class ListComments
{
    use AsObject;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function handle(Issue $issue, ?User $user = null): array
    {
        // The underlying issue must stay in the actor's perimeter: a
        // shrunken scope makes old objects fail like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $issue);
        }

        return $issue->comments()
            ->with('author')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($comment) => CommentPresenter::present($comment))
            ->values()
            ->all();
    }
}
