<?php

namespace App\Actions\Comments;

use App\Actions\Audit\RecordActivity;
use App\Enums\AuditEventType;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\User;
use App\Responders\Comments\CommentRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $comment->issue);
        }

        $issue = $comment->issue;
        $snapshot = [
            'comment_id' => $comment->id,
            'body' => ['before' => $comment->body, 'after' => null],
        ];

        $comment->delete();

        // The comment is gone: the journal keeps its last body on the
        // parent issue — comments have no version history of their own.
        RecordActivity::run(AuditEventType::CommentDeleted, $issue, $user, $snapshot);

        return $comment;
    }

    public function asController(Request $request, Issue $issue, string $commentId, CommentRedirectResponder $responder): RedirectResponse
    {
        $comment = $issue->comments()->findOrFail($commentId);
        $this->handle($comment, $request->user());

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
