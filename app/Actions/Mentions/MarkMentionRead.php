<?php

namespace App\Actions\Mentions;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\CommentMention;
use App\Models\User;
use App\Responders\Mentions\MentionRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;

class MarkMentionRead
{
    use AsController;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    public function asController(ActionRequest $request, CommentMention $mention, MentionRedirectResponder $responder): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        // Anti-probing: a mention of another account fails exactly like
        // a non-existent one.
        if ($mention->user_id !== $user->getKey()) {
            throw (new ModelNotFoundException)->setModel(CommentMention::class, [$mention->getKey()]);
        }

        // A shrunken roster must fail like the issue never existed.
        app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $mention->comment->issue);

        if ($mention->read_at === null) {
            $mention->update(['read_at' => now()]);
        }

        return $responder->read($mention);
    }
}
