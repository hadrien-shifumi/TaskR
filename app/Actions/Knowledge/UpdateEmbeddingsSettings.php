<?php

namespace App\Actions\Knowledge;

use App\Actions\Audit\RecordActivity;
use App\Enums\AuditEventType;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEmbeddingSetting;
use App\Responders\Knowledge\EmbeddingsSettingsRedirectResponder;
use App\Support\Chat;
use App\Support\Embeddings;
use App\Support\WorkspaceContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;

class UpdateEmbeddingsSettings
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
            // Advanced opt-in: ask the provider to return exactly this many
            // dimensions (downscaling providers, e.g. OpenAI). Left empty,
            // the parameter never travels — strict providers reject it.
            'dimensions' => ['nullable', 'integer', 'min:1', 'max:8192'],
        ];
    }

    /**
     * Validate the candidate provider against the real endpoint before
     * storing it (probe), then persist the settings. The probe runs with
     * the submitted config — or the stored API key when the owner left
     * the write-only key field empty — and refuses the configuration
     * when the provider is unreachable or produces vectors of a dimension
     * foreign to this instance's storage.
     *
     * @param  array{base_url: string, api_key: ?string, model: string, dimensions: ?int}  $data
     *
     * @throws ValidationException
     */
    public function handle(Workspace $workspace, ?User $actor, array $data): WorkspaceEmbeddingSetting
    {
        $existing = WorkspaceEmbeddingSetting::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->first();

        $baseUrl = rtrim($data['base_url'], '/');
        $model = $data['model'];
        $optInDimensions = $data['dimensions'] !== null ? (int) $data['dimensions'] : null;

        // An empty write-only key field keeps the stored key.
        $apiKey = $data['api_key'] !== null && $data['api_key'] !== '' ? $data['api_key'] : $existing?->api_key;

        $probe = $this->probeCandidate($workspace, $baseUrl, $apiKey, $model, $optInDimensions);

        return DB::transaction(function () use ($workspace, $actor, $existing, $baseUrl, $apiKey, $model, $optInDimensions, $probe): WorkspaceEmbeddingSetting {
            $setting = WorkspaceEmbeddingSetting::withoutWorkspaceScope()->updateOrCreate(
                ['workspace_id' => $workspace->id],
                [
                    'base_url' => $baseUrl,
                    'api_key' => $apiKey,
                    'model' => $model,
                    // The observed dimension is what the provider actually
                    // returns for this configuration.
                    'dimensions' => $probe['dimensions'],
                    'send_dimensions' => $optInDimensions !== null,
                ],
            );

            RecordActivity::run(AuditEventType::WorkspaceEmbeddingsUpdated, $workspace, $actor, [
                'base_url' => ['before' => $existing?->base_url, 'after' => $baseUrl],
                'model' => ['before' => $existing?->model, 'after' => $model],
                'dimensions' => ['before' => $existing?->dimensions, 'after' => $probe['dimensions']],
                // The key itself is never journalled — only that it changed.
                'api_key_changed' => $apiKey !== $existing?->api_key,
            ]);

            return $setting;
        });
    }

    /**
     * One real call to the candidate endpoint with a probe string, mirroring
     * how every later embedding will run (the opt-in travels only when
     * requested). Anything the provider returns is inspected, never
     * trusted: an unreachable endpoint or a foreign vector dimension
     * refuses the configuration with an actionable message. Shared by
     * the save flow and the "test connection" button.
     *
     * @return array{dimensions: int, latencyMs: int}
     *
     * @throws ValidationException
     */
    public function probeCandidate(Workspace $workspace, string $baseUrl, ?string $apiKey, string $model, ?int $optInDimensions): array
    {
        if ($apiKey === null || $apiKey === '') {
            $apiKey = WorkspaceEmbeddingSetting::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->value('api_key');
        }

        if ($apiKey === null || $apiKey === '') {
            throw ValidationException::withMessages([
                'api_key' => [__('validation.embeddings.api_key_required')],
            ]);
        }

        $client = new Embeddings([
            'base_url' => $baseUrl,
            'api_key' => $apiKey,
            'model' => $model,
            'dimensions' => $optInDimensions ?? 1024,
        ], $optInDimensions !== null);

        try {
            $result = $client->probe();
        } catch (RequestException $exception) {
            /** @var Response $response */
            $response = $exception->response;

            throw ValidationException::withMessages([
                'base_url' => [__('validation.embeddings.probe_unreachable', [
                    'error' => trim($response->body()) !== '' ? $response->body() : $exception->getMessage(),
                ])],
            ]);
        }

        $required = (int) config('knowledge.embeddings.dimensions');

        if ($result['dimensions'] !== $required) {
            throw ValidationException::withMessages([
                'model' => [__('validation.embeddings.dimension_mismatch', [
                    'model' => $model,
                    'returned' => $result['dimensions'],
                    'expected' => $required,
                ])],
            ]);
        }

        return $result;
    }

    /**
     * The candidate provider's model listing for the "load the models"
     * button: the exact candidate-pair resolution of the save flow (a
     * blank write-only key field keeps the stored one, refused with the
     * same actionable message when nothing is stored anywhere), then the
     * shared OpenAI-standard listing. Never persists anything; the key
     * never appears in the answer.
     *
     * @param  string  $baseUrl  The endpoint the owner typed.
     * @param  ?string  $apiKey  The write-only field's content, null/'' to reuse the stored key.
     * @return list<string>
     *
     * @throws ValidationException
     */
    public function listCandidateModels(Workspace $workspace, string $baseUrl, ?string $apiKey): array
    {
        if ($apiKey === null || $apiKey === '') {
            $apiKey = WorkspaceEmbeddingSetting::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->value('api_key');
        }

        if ($apiKey === null || $apiKey === '') {
            throw ValidationException::withMessages([
                'api_key' => [__('validation.embeddings.api_key_required')],
            ]);
        }

        try {
            return Chat::listModels($baseUrl, $apiKey);
        } catch (ConnectionException $exception) {
            throw ValidationException::withMessages([
                'base_url' => [__('validation.embeddings.probe_unreachable', [
                    'error' => $exception->getMessage(),
                ])],
            ]);
        } catch (RequestException $exception) {
            /** @var Response $response */
            $response = $exception->response;

            throw ValidationException::withMessages([
                'base_url' => [__('validation.embeddings.probe_unreachable', [
                    'error' => trim($response->body()) !== '' ? $response->body() : $exception->getMessage(),
                ])],
            ]);
        }
    }

    public function asController(ActionRequest $request, EmbeddingsSettingsRedirectResponder $responder): RedirectResponse
    {
        $data = $request->validated();
        $data['api_key'] = $data['api_key'] ?? null;
        $data['dimensions'] = isset($data['dimensions']) ? (int) $data['dimensions'] : null;

        $this->handle(app(WorkspaceContext::class)->getOrFail(), $request->user(), $data);

        return $responder->updated();
    }
}
