<?php

namespace App\Actions\Comments;

use App\Models\Comment;
use App\Models\Issue;
use App\Responders\Comments\CommentRedirectResponder;
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

    public function handle(Comment $comment, string $body): Comment
    {
        $comment->update(['body' => $body]);

        return $comment->refresh();
    }

    public function asController(ActionRequest $request, Issue $issue, string $commentId, CommentRedirectResponder $responder): RedirectResponse
    {
        $comment = $this->resolveComment($issue, $commentId);
        $comment = $this->handle($comment, $request->validated('body'));

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
