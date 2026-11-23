<?php

namespace App\Actions\Knowledge;

use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;

/**
 * "Load the models" for the embeddings settings block: runs the same
 * candidate-pair resolution as the save flow against the submitted
 * configuration — falling back to the stored API key when the
 * write-only field is left empty — and answers the provider's
 * OpenAI-standard model list as plain JSON. The API key is never
 * echoed, and a provider that exposes no list answers an empty one.
 */
class ListEmbeddingsModels
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
        // No model here: the listing exists precisely to pick one.
        return [
            'base_url' => ['required', 'string', 'url', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @param  array{base_url: string, api_key: ?string}  $data
     */
    public function asController(ActionRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Delegate to the exact save-time candidate resolution, minus
        // the probe and the persistence.
        $models = app(UpdateEmbeddingsSettings::class)->listCandidateModels(
            app(WorkspaceContext::class)->getOrFail(),
            rtrim($data['base_url'], '/'),
            $data['api_key'] ?? null,
        );

        return response()->json([
            'models' => array_map(
                fn (string $id): array => ['id' => $id],
                $models,
            ),
        ]);
    }
}
