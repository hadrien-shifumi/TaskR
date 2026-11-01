<?php

namespace App\Actions\Comments;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Actions\Mentions\SyncCommentMentions;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\User;
use App\Responders\Comments\CommentRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class AddComment
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:20000'],
        ];
    }

    public function handle(Issue $issue, User $author, string $body): Comment
    {
        // Out-of-scope issues fail exactly like non-existent ones.
        app(WorkspaceContext::class)->abortUnlessIssueAccessible($author, $issue);

        $comment = $issue->comments()->create([
            'author_id' => $author->getKey(),
            'body' => $body,
        ]);

        SyncCommentMentions::run($comment);

        return $comment;
    }

    public function asController(ActionRequest $request, Issue $issue, CommentRedirectResponder $responder): RedirectResponse
    {
        $author = $request->user();
        abort_unless($author instanceof User, 401);

        $comment = $this->handle($issue, $author, $request->validated('body'));

        return $responder->added($comment);
    }
}
