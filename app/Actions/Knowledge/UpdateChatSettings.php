<?php

namespace App\Actions\Knowledge;

use App\Actions\Audit\RecordActivity;
use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\AuditEventType;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEmbeddingSetting;
use App\Responders\Knowledge\ChatSettingsRedirectResponder;
use App\Support\Chat;
use App\Support\WorkspaceContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;

/**
 * Save the workspace's chat provider: validate the candidate chat
 * configuration against the real endpoint (the effective pair — the chat's
 * own pair, or the stored embeddings pair the runtime reuses within the
 * group's fallback), then persist it on the same
 * `workspace_embedding_settings` row as the embeddings provider (one row
 * per workspace). The API key is encrypted at rest and never echoed — the
 * audit journal only reports that it changed.
 */
class UpdateChatSettings
{
    use AsController;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        $user = request()->user();
        $workspace = app(WorkspaceContext::class)->get();

        return $user instanceof User
            && $workspace instanceof Workspace
            && $workspace->hasOwner($user);
    }

    /**
     * Both write-only fields are the pair suffix: with both left empty (the
     * pair fallback the owner's mutualisation choice asks for) the stored
     * pair is cleared and the runtime reuses the stored embeddings pair;
     * otherwise a blank field keeps the stored one, like the embeddings
     * write-only key.
     *
     * @return array{chat_base_url: ?string, chat_api_key: ?string, chat_model: string}
     */
    public function rules(): array
    {
        return [
            'chat_base_url' => ['nullable', 'string', 'url', 'max:255'],
            'chat_api_key' => ['nullable', 'string', 'max:255'],
            'chat_model' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @param  array{chat_base_url: ?string, chat_api_key: ?string, chat_model: string}  $data
     *
     * @throws ValidationException
     */
    public function handle(Workspace $workspace, ?User $actor, array $data): WorkspaceEmbeddingSetting
    {
        $existing = WorkspaceEmbeddingSetting::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->first();

        $urlBlank = $data['chat_base_url'] === null || $data['chat_base_url'] === '';
        $keyBlank = $data['chat_api_key'] === null || $data['chat_api_key'] === '';

        if ($urlBlank && $keyBlank) {
            // The pair fallback: the runtime resolution reuses the stored
            // embeddings pair, and the persisted columns stay cleared (null)
            // — the mutualisation lives in Chat::forWorkspaceId, never in
            // the row. The probe below validates the effective pair.
            $chatBaseUrl = $existing?->base_url;
            $chatApiKey = $existing?->api_key;
            $persistBaseUrl = null;
            $persistApiKey = null;
        } else {
            // The write-only semantics per field: a blank field keeps the
            // stored one.
            $persistBaseUrl = $urlBlank ? $existing?->chat_base_url : rtrim($data['chat_base_url'], '/');
            $persistApiKey = $keyBlank ? $existing?->chat_api_key : $data['chat_api_key'];
            $chatBaseUrl = $persistBaseUrl;
            $chatApiKey = $persistApiKey;
        }

        $chatModel = $data['chat_model'];

        // The pair the chat resolves stays explicit: a blank kept field with
        // nothing stored anywhere is an incomplete pair — refused with an
        // actionable message, never a 500, so the probe never runs against
        // a blank endpoint.
        if ($chatBaseUrl === null || $chatBaseUrl === '') {
            throw ValidationException::withMessages([
                'chat_base_url' => [__('validation.chat.base_url_required')],
            ]);
        }

        if ($chatApiKey === null || $chatApiKey === '') {
            throw ValidationException::withMessages([
                'chat_api_key' => [__('validation.chat.api_key_required')],
            ]);
        }

        $probe = $this->probeCandidate($workspace, $chatBaseUrl, $chatApiKey, $chatModel);

        return DB::transaction(function () use ($workspace, $actor, $existing, $persistBaseUrl, $persistApiKey, $chatModel): WorkspaceEmbeddingSetting {
            $setting = WorkspaceEmbeddingSetting::withoutWorkspaceScope()->updateOrCreate(
                ['workspace_id' => $workspace->id],
                [
                    'chat_base_url' => $persistBaseUrl,
                    'chat_api_key' => $persistApiKey,
                    'chat_model' => $chatModel,
                ],
            );

            RecordActivity::run(AuditEventType::WorkspaceChatUpdated, $workspace, $actor, [
                'chat_base_url' => ['before' => $existing?->chat_base_url, 'after' => $persistBaseUrl],
                'chat_model' => ['before' => $existing?->chat_model, 'after' => $chatModel],
                'api_key_changed' => $persistApiKey !== $existing?->chat_api_key,
            ]);

            return $setting;
        });
    }

    /**
     * One real call to the effective chat endpoint with a short probe
     * message, mirroring how every later completion will run: the pair
     * handle()'s resolution resolved (never a blank one — the required
     * checks ran before it). Anything the provider returns is inspected,
     * never trusted: an unreachable endpoint refuses the configuration with
     * an actionable message. Shared by the save flow and the "test" button.
     *
     * A blank write-only key field probes with the stored chat key — the
     * same fallback the embeddings probe applies to its own key, so the
     * test button never needs the typed key.
     *
     * @throws ValidationException
     */
    public function probeCandidate(Workspace $workspace, string $chatBaseUrl, ?string $chatApiKey, string $chatModel): array
    {
        if ($chatApiKey === null || $chatApiKey === '') {
            $chatApiKey = WorkspaceEmbeddingSetting::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->value('chat_api_key');
        }

        if ($chatApiKey === null || $chatApiKey === '') {
            throw ValidationException::withMessages([
                'chat_api_key' => [__('validation.chat.api_key_required')],
            ]);
        }

        $client = new Chat([
            'base_url' => $chatBaseUrl,
            'api_key' => $chatApiKey,
            'model' => $chatModel,
        ]);

        try {
            $result = $client->probe();
        } catch (RequestException $exception) {
            /** @var Response $response */
            $response = $exception->response;

            throw ValidationException::withMessages([
                'chat_base_url' => [__('validation.chat.probe_unreachable', [
                    'error' => trim($response->body()) !== '' ? $response->body() : $exception->getMessage(),
                ])],
            ]);
        }

        return $result;
    }

    /**
     * The candidate chat pair's model listing for the "load the models"
     * button: the exact pair resolution of the save flow — the write-only
     * fields blank per field keep the stored chat value, both blank reuse
     * the stored embeddings pair — refused with the same actionable
     * messages when the resolved pair is incomplete. Never persists
     * anything; the key never appears in the answer.
     *
     * @param  ?string  $chatBaseUrl  The write-only endpoint field's content.
     * @param  ?string  $chatApiKey  The write-only key field's content.
     * @return list<string>
     *
     * @throws ValidationException
     */
    public function listChatCandidateModels(Workspace $workspace, ?string $chatBaseUrl, ?string $chatApiKey): array
    {
        $existing = WorkspaceEmbeddingSetting::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->first();

        $urlBlank = $chatBaseUrl === null || $chatBaseUrl === '';
        $keyBlank = $chatApiKey === null || $chatApiKey === '';

        if ($urlBlank && $keyBlank) {
            // The pair fallback: the listing runs against the stored
            // embeddings pair, exactly what the runtime resolution reuses.
            $chatBaseUrl = $existing?->base_url;
            $chatApiKey = $existing?->api_key;
        } else {
            // The write-only semantics per field: a blank field keeps the
            // stored chat one.
            $chatBaseUrl = $urlBlank ? $existing?->chat_base_url : rtrim((string) $chatBaseUrl, '/');
            $chatApiKey = $keyBlank ? $existing?->chat_api_key : $chatApiKey;
        }

        if ($chatBaseUrl === null || $chatBaseUrl === '') {
            throw ValidationException::withMessages([
                'chat_base_url' => [__('validation.chat.base_url_required')],
            ]);
        }

        if ($chatApiKey === null || $chatApiKey === '') {
            throw ValidationException::withMessages([
                'chat_api_key' => [__('validation.chat.api_key_required')],
            ]);
        }

        try {
            return Chat::listModels($chatBaseUrl, $chatApiKey);
        } catch (ConnectionException $exception) {
            throw ValidationException::withMessages([
                'chat_base_url' => [__('validation.chat.probe_unreachable', [
                    'error' => $exception->getMessage(),
                ])],
            ]);
        } catch (RequestException $exception) {
            /** @var Response $response */
            $response = $exception->response;

            throw ValidationException::withMessages([
                'chat_base_url' => [__('validation.chat.probe_unreachable', [
                    'error' => trim($response->body()) !== '' ? $response->body() : $exception->getMessage(),
                ])],
            ]);
        }
    }

    public function asController(ActionRequest $request, ChatSettingsRedirectResponder $responder): RedirectResponse
    {
        $data = $request->validated();
        $data['chat_api_key'] = $data['chat_api_key'] ?? null;

        $this->handle(app(WorkspaceContext::class)->getOrFail(), $request->user(), $data);

        return $responder->updated();
    }
}
