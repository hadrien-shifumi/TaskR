<?php

namespace App\Responders\Workspaces;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class GeneralSettingsPageResponder
{
    /**
     * Render the general settings page from the action's prepared data.
     *
     * @param  array{parentsAsContainers: bool}  $data
     */
    public function toResponse(Request $request, array $data): Response
    {
        return Inertia::render('Settings/General', $data)->toResponse($request);
    }
}
