<?php

namespace App\Actions\Chat;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\ChatThread;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * A new conversation: the thread carries the workspace and the author
 * (the author-only channel), its title derives from the first message,
 * and the turn starts at once — the 202 contract of StartChatTurn.
 */
class CreateChatThread
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'min:1', 'max:'.StartChatTurn::CONTENT_MAX_LENGTH],
        ];
    }

    /**
     * @return array{threadId: string, userMessageId: string, assistantMessageId: string, status: string}
     */
    public function handle(Workspace $workspace, User $user, string $content): array
    {
        $thread = ChatThread::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'title' => StartChatTurn::titleFrom($content),
        ]);

        return app(StartChatTurn::class)->start($thread, $content);
    }

    public function asController(ActionRequest $request): JsonResponse
    {
        return response()->json(
            $this->handle(
                app(WorkspaceContext::class)->getOrFail(),
                $request->user(),
                strval($request->validated('content')),
            ),
            202,
        );
    }
}
