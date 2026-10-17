<?php

namespace App\Actions\Comments;

use App\Models\Comment;
use App\Models\Issue;
use App\Models\User;
use App\Responders\Comments\CommentRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class DeleteComment
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        $comment = $this->currentComment();

        // Only the author may delete their comment.
        return $comment !== null && request()->user()?->id === $comment->author_id;
    }

    public function handle(Comment $comment, ?User $user = null): Comment
    {
        // The underlying issue must stay in the actor's perimeter: a
        // shrunken scope makes old objects fail like non-existent ones.
        // Without a resolvable user this is a trusted internal object run.
        $user ??= request()->user();
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $comment->issue);
        }

        $comment->delete();

        return $comment;
    }

    public function asController(Issue $issue, string $commentId, CommentRedirectResponder $responder): RedirectResponse
    {
        $comment = $issue->comments()->findOrFail($commentId);
        $this->handle($comment);

        return $responder->deleted($comment);
    }

    private function currentComment(): ?Comment
    {
        $issue = request()->route('issue');
        $commentId = request()->route('comment');

        if (! $issue instanceof Issue || ! is_string($commentId)) {
            return null;
        }

        return $issue->comments()->find($commentId);
    }
}
