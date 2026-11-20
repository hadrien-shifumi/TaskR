<?php

namespace App\Actions\Knowledge;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\Workspace;
use App\Models\WorkspaceEmbeddingSetting;
use App\Responders\Knowledge\EmbeddingsSettingsPageResponder;
use App\Support\Chat;
use App\Support\Embeddings;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class ShowEmbeddingsSettingsPage
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * Present the workspace's AI settings: the saved embeddings
     * configuration (never its API key — a `hasApiKey` boolean instead),
     * the effectively active provider following the fallback chain
     * (workspace chat settings > stored embeddings pair > none) and the
     * chat configuration (endpoint, key, model). The chat is simply
     * disabled when no chat settings exist — never a 500.
     *
     * @return array{settings: ?array<string, mixed>, activeProvider: array<string, mixed>, chatProvider: array<string, mixed>, requiredDimensions: int}
     */
    public function handle(Workspace $workspace): array
    {
        $setting = WorkspaceEmbeddingSetting::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->first();

        $active = Embeddings::forWorkspace($workspace)->describe();

        $chat = Chat::forWorkspace($workspace)->describe();

        return [
            'settings' => $setting === null ? null : [
                'baseUrl' => $setting->base_url,
                'model' => $setting->model,
                'dimensions' => (int) $setting->dimensions,
                'sendDimensions' => (bool) $setting->send_dimensions,
                'hasApiKey' => $setting->api_key !== null && $setting->api_key !== '',
                // The chat block rides the same saved row (one row per
                // workspace) — same guarantees, never the key itself.
                'chatBaseUrl' => $setting->chat_base_url,
                'chatModel' => $setting->chat_model,
                'chatHasApiKey' => $setting->chat_api_key !== null && $setting->chat_api_key !== '',
            ],
            'activeProvider' => [
                'source' => $setting !== null ? 'workspace' : $active['source'],
                'baseUrl' => $active['baseUrl'],
                'model' => $active['model'],
                'dimensions' => $active['dimensions'],
                'hasApiKey' => $active['hasApiKey'],
            ],
            'chatProvider' => [
                'source' => $chat['source'],
                'baseUrl' => $chat['baseUrl'],
                'model' => $chat['model'],
                'hasApiKey' => $chat['hasApiKey'],
            ],
            'requiredDimensions' => (int) config('knowledge.embeddings.dimensions'),
        ];
    }

    public function asController(Request $request, EmbeddingsSettingsPageResponder $responder): Response
    {
        $workspace = app(WorkspaceContext::class)->getOrFail();

        return $responder->toResponse($request, $this->handle($workspace));
    }
}
