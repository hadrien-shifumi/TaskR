<?php

namespace App\Actions\Knowledge;

use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;

/**
 * "Load the models" for the chat settings block: runs the same
 * candidate-pair resolution as the save flow — the write-only fields
 * blank keep the stored chat values, both blank reuse the stored
 * embeddings pair — and answers the provider's OpenAI-standard model
 * list as plain JSON. The API key is never echoed.
 */
class ListChatModels
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
        // Both fields stay nullable: the pair fallback (both left empty)
        // borrows the stored embeddings pair, like the runtime resolution.
        // No model either — the listing exists precisely to pick one.
        return [
            'chat_base_url' => ['nullable', 'string', 'url', 'max:255'],
            'chat_api_key' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @param  array{chat_base_url: ?string, chat_api_key: ?string}  $data
     */
    public function asController(ActionRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Delegate to the exact save-time pair resolution, minus the
        // probe and the persistence.
        $models = app(UpdateChatSettings::class)->listChatCandidateModels(
            app(WorkspaceContext::class)->getOrFail(),
            $data['chat_base_url'] ?? null,
            $data['chat_api_key'] ?? null,
        );

        return response()->json([
            'models' => array_map(
                fn (string $id): array => ['id' => $id],
                $models,
            ),
        ]);
    }
}
