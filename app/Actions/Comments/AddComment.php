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

class AddComment
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        $user = request()->user();

        return $user instanceof User && app(WorkspaceContext::class)->canUseWorkspace($user);
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

        return $issue->comments()->create([
            'author_id' => $author->getKey(),
            'body' => $body,
        ]);
    }

    public function asController(ActionRequest $request, Issue $issue, CommentRedirectResponder $responder): RedirectResponse
    {
        $author = $request->user();
        abort_unless($author instanceof User, 401);

        $comment = $this->handle($issue, $author, $request->validated('body'));

        return $responder->added($comment);
    }
}
