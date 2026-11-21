<?php

namespace App\Actions\Chat;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\ChatThread;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;

/**
 * The next message of an existing conversation: the thread resolves by
 * route binding (its workspace tenancy included), the author-only guard
 * runs here, and the turn starts — the 202 contract of StartChatTurn.
 */
class PostChatMessage
{
    use AsController;
    use AuthorizesWorkspaceAccess;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return StartChatTurn::contentRules();
    }

    /**
     * @return array{threadId: string, userMessageId: string, assistantMessageId: string, status: string}
     */
    public function handle(ChatThread $thread, string $content, ?User $user = null): array
    {
        // Author-only: someone else's thread fails exactly like a
        // missing one — never a 403 confirming it exists.
        if ($user !== null && $thread->user_id !== $user->id) {
            throw (new ModelNotFoundException)->setModel(ChatThread::class, [$thread->id]);
        }

        return app(StartChatTurn::class)->start($thread, $content);
    }

    public function asController(ActionRequest $request, ChatThread $thread): JsonResponse
    {
        return response()->json(
            $this->handle($thread, strval($request->validated('content')), $request->user()),
            202,
        );
    }
}
