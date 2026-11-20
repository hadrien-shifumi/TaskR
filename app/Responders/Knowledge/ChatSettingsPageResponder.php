<?php

namespace App\Responders\Knowledge;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ChatSettingsPageResponder
{
    /**
     * Render the workspace AI settings page from the action's prepared
     * data. The payload never carries the stored chat API key —
     * `chatHasApiKey` is the only key-related flag.
     *
     * @param  array{chat_base_url: ?string, chat_api_key: ?string, chat_model: ?string}  $data
     */
    public function toResponse(Request $request, array $data): Response
    {
        return Inertia::render('Settings/Embeddings', $data)->toResponse($request);
    }
}
