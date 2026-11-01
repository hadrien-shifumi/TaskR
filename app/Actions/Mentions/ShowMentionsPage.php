<?php

namespace App\Actions\Mentions;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\CommentMention;
use App\Models\User;
use App\Presenters\Mentions\MentionPresenter;
use App\Responders\Mentions\MentionsPageResponder;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Symfony\Component\HttpFoundation\Response;

class ShowMentionsPage
{
    use AsController;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * Mentions of the account: unread first, then the most recently read
     * ones, within the account's reachable perimeter.
     *
     * @return array{mentions: list<array<string, mixed>>, unreadCount: int}
     */
    public function handle(User $user): array
    {
        $mentions = CommentMention::query()
            ->forAccount($user)
            ->with('comment.author', 'comment.issue')
            ->orderByRaw('(read_at is null) desc')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return [
            'mentions' => $mentions
                ->map(fn (CommentMention $mention) => MentionPresenter::detail($mention))
                ->all(),
            'unreadCount' => CommentMention::query()
                ->forAccount($user)
                ->whereNull('read_at')
                ->count(),
        ];
    }

    public function asController(Request $request, MentionsPageResponder $responder): Response
    {
        $user = $request->user();
        assert($user instanceof User);

        return $responder->toResponse($request, $this->handle($user));
    }
}
