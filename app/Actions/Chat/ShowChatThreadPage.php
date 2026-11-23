<?php

namespace App\Actions\Chat;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\ChatThread;
use App\Models\User;
use App\Responders\Chat\ChatThreadPageResponder;
use App\Support\Chat;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Symfony\Component\HttpFoundation\Response;

/**
 * One conversation's page: the thread and its transcript, read through
 * the same core as the JSON endpoint (author-only — someone else's
 * conversation fails exactly like a missing one), plus the flag saying
 * whether the workspace's assistant is configured — the pages render an
 * explicit banner when it is not, and the flag never exposes a key.
 */
class ShowChatThreadPage
{
    use AsController;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(ChatThread $thread, ?User $user = null): array
    {
        return app(ShowChatThread::class)->handle($thread, $user);
    }

    public function asController(Request $request, ChatThread $thread, ChatThreadPageResponder $responder): Response
    {
        // Instance feature gate (the owner keeps the section asleep):
        // a closed section answers 404 — nothing hints that it exists.
        abort_unless((bool) config('taskr.features.chat'), 404);

        $data = $this->handle($thread, $request->user());

        return $responder->toResponse($request, [
            ...$data,
            'chatEnabled' => Chat::forWorkspace($thread->workspace)->enabled(),
        ]);
    }
}
