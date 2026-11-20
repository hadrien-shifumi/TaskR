<?php

namespace App\Actions\Knowledge;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Chat;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;

/**
 * "Test the chat" probe for the AI settings page: runs the same probe
 * as the save flow against the submitted (candidate) chat
 * configuration — falling back to the stored API key when the
 * write-only field is left empty — and reports the assistant's reply
 * text and the endpoint latency. The API key is never echoed.
 */
class TestChatSettings
{
    use AsController;

    public function authorize(): bool
    {
        $user = request()->user();
        $workspace = app(WorkspaceContext::class)->get();

        return $user instanceof User
            && $workspace instanceof Workspace
            && $workspace->hasOwner($user);
    }

    public function rules(): array
    {
        return [
            'chat_base_url' => ['required', 'string', 'url', 'max:255'],
            'chat_api_key' => ['nullable', 'string', 'max:255'],
            'chat_model' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @param  array{chat_base_url: string, chat_api_key: ?string, chat_model: string}  $data
     *
     * @throws ValidationException
     */
    public function asController(ActionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['chat_api_key'] = $data['chat_api_key'] ?? null;

        // Delegate to the exact save-time probe, minus the persistence.
        $probe = app(UpdateChatSettings::class)->probeCandidate(
            app(WorkspaceContext::class)->getOrFail(),
            rtrim($data['chat_base_url'], '/'),
            $data['chat_api_key'],
            $data['chat_model'],
        );

        return response()->json([
            'content' => $probe['content'],
            'latencyMs' => $probe['latencyMs'],
        ]);
    }
}
