<?php

namespace App\Responders\Statuses;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;

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
