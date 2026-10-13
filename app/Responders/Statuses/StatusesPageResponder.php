<?php

namespace App\Responders\Statuses;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class StatusesPageResponder
{
    /**
     * @param  array{statuses: array<array-key, array<string, mixed>>}  $data
     */
    public function toResponse(Request $request, array $data): Response
    {
        return Inertia::render('Settings/Statuses', $data)->toResponse($request);
    }
}
