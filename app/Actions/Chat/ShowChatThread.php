<?php

namespace App\Actions\Chat;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\User;
use App\Presenters\Chat\ChatMessagePresenter;
use App\Presenters\Chat\ChatThreadPresenter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;

/**
 * The full read of one conversation: the thread and its transcript, the
 * settled state the client reloads without Mercure (the streaming
 * fragments live only on the channel). Author-only: someone else's
 * thread fails exactly like a missing one.
 */
class ShowChatThread
{
    use AsController;
    use AuthorizesWorkspaceAccess;

    /**
     * @return array{thread: array<string, mixed>, messages: array<int, array<string, mixed>>}
     */
    public function handle(ChatThread $thread, ?User $user = null): array
    {
        if ($user !== null && $thread->user_id !== $user->id) {
            throw (new ModelNotFoundException)->setModel(ChatThread::class, [$thread->id]);
        }

        return [
            'thread' => ChatThreadPresenter::detail($thread),
            'messages' => $thread->messages()
                ->orderBy('created_at')
                ->get()
                ->map(fn (ChatMessage $message): array => ChatMessagePresenter::present($message))
                ->values()
                ->all(),
        ];
    }

    public function asController(ActionRequest $request, ChatThread $thread): JsonResponse
    {
        return response()->json($this->handle($thread, $request->user()));
    }
}
