<?php

namespace App\Actions\Chat;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Chat\ChatbotIndexPageResponder;
use App\Support\Chat;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Chatbot section's landing page: the requesting user's
 * conversations, most recently active first — the same list the JSON
 * endpoint serves — next to the section's "new conversation" composer.
 * Whether a chat provider is configured for the workspace travels along
 * (an honest flag only: the key itself never leaves the server), so the
 * pages can say "the assistant is not set up" instead of failing.
 */
class ShowChatbotIndexPage
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
    public function handle(Workspace $workspace, User $user): array
    {
        return [
            'threads' => app(ListChatThreads::class)->handle($workspace, $user),
            'chatEnabled' => Chat::forWorkspace($workspace)->enabled(),
        ];
    }

    public function asController(Request $request, ChatbotIndexPageResponder $responder): Response
    {
        // Instance feature gate (the owner keeps the section asleep):
        // a closed section answers 404 — nothing hints that it exists.
        abort_unless((bool) config('taskr.features.chat'), 404);

        $workspace = app(WorkspaceContext::class)->getOrFail();
        $user = $request->user();
        assert($user instanceof User);

        return $responder->toResponse($request, $this->handle($workspace, $user));
    }
}
