<?php

namespace App\Actions\Chat;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\ChatThread;
use App\Models\User;
use App\Models\Workspace;
use App\Presenters\Chat\ChatThreadPresenter;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;

/**
 * The threads of the requesting user, most recently active first — the
 * sidebar list of the chat V1. Author-only like every other chat
 * surface: another member's conversations are not this user's data, and
 * the workspace scope keeps this workspace's rows alone.
 */
class ListChatThreads
{
    use AsController;
    use AuthorizesWorkspaceAccess;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function handle(Workspace $workspace, User $user): array
    {
        return ChatThread::query()
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (ChatThread $thread): array => ChatThreadPresenter::listItem($thread))
            ->values()
            ->all();
    }

    public function asController(ActionRequest $request): JsonResponse
    {
        return response()->json([
            'threads' => $this->handle(
                app(WorkspaceContext::class)->getOrFail(),
                $request->user(),
            ),
        ]);
    }
}
