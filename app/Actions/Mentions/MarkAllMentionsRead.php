<?php

namespace App\Actions\Mentions;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\CommentMention;
use App\Models\User;
use App\Responders\Mentions\MentionRedirectResponder;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;

class MarkAllMentionsRead
{
    use AsController;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    public function asController(ActionRequest $request, MentionRedirectResponder $responder): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        CommentMention::query()
            ->forAccount($user)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $responder->readAll();
    }
}
