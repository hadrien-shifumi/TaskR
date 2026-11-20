<?php

namespace App\Responders\Knowledge;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EmbeddingsSettingsPageResponder
{
    /**
     * Render the workspace embeddings settings page from the action's
     * prepared data. The payload never carries the stored API key —
     * `hasApiKey` is the only key-related flag.
     *
     * @param  array{settings: ?array<string, mixed>, activeProvider: array<string, mixed>, requiredDimensions: int}  $data
     */
    public function toResponse(Request $request, array $data): Response
    {
        return Inertia::render('Settings/Embeddings', $data)->toResponse($request);
    }
}
