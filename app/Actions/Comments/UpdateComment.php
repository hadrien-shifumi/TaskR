<?php

namespace App\Actions\Comments;

use App\Models\Comment;
use App\Models\Issue;
use App\Models\User;
use App\Responders\Comments\CommentRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class UpdateComment
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        $comment = $this->currentComment();

        // Only the author may edit their comment.
        return $comment !== null && request()->user()?->id === $comment->author_id;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:20000'],
        ];
    }

    public function handle(Comment $comment, string $body, ?User $user = null): Comment
    {
        // The underlying issue must stay in the actor's perimeter: a
        // shrunken scope makes old objects fail like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $comment->issue);
        }

        $comment->update(['body' => $body]);

        return $comment->refresh();
    }

    public function asController(ActionRequest $request, Issue $issue, string $commentId, CommentRedirectResponder $responder): RedirectResponse
    {
        $comment = $this->resolveComment($issue, $commentId);
        $comment = $this->handle($comment, $request->validated('body'), $request->user());

        return $responder->updated($comment);
    }

    private function currentComment(): ?Comment
    {
        $issue = request()->route('issue');
        $commentId = request()->route('comment');

        if (! $issue instanceof Issue || ! is_string($commentId)) {
            return null;
        }

        return $this->resolveComment($issue, $commentId);
    }

    private function resolveComment(Issue $issue, string $commentId): Comment
    {
        return $issue->comments()->findOrFail($commentId);
    }
}
