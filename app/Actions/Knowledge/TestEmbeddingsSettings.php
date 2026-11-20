<?php

namespace App\Actions\Knowledge;

use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;

/**
 * "Test connection" probe for the embeddings settings page: runs the
 * same probe as the save flow against the submitted (candidate)
 * configuration — falling back to the stored API key when the
 * write-only field is left empty — and reports the observed vector
 * dimension and the endpoint latency. The API key is never echoed.
 */
class TestEmbeddingsSettings
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
            'base_url' => ['required', 'string', 'url', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'model' => ['required', 'string', 'max:255'],
            'dimensions' => ['nullable', 'integer', 'min:1', 'max:8192'],
        ];
    }

    /**
     * @param  array{base_url: string, api_key: ?string, model: string, dimensions: ?int}  $data
     *
     * @throws ValidationException
     */
    public function asController(ActionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['api_key'] = $data['api_key'] ?? null;
        $data['dimensions'] = isset($data['dimensions']) ? (int) $data['dimensions'] : null;

        // Delegate to the exact save-time probe, minus the persistence.
        $probe = app(UpdateEmbeddingsSettings::class)->probeCandidate(
            app(WorkspaceContext::class)->getOrFail(),
            rtrim($data['base_url'], '/'),
            $data['api_key'],
            $data['model'],
            $data['dimensions'],
        );

        return response()->json([
            'dimensions' => $probe['dimensions'],
            'model' => $data['model'],
            'latencyMs' => $probe['latencyMs'],
        ]);
    }
}
