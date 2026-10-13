<?php

namespace App\Responders\Issues;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;

class IssuePageResponder
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function toResponse(Request $request, array $data): Response
    {
        return Inertia::render('Issues/Show', $data)->toResponse($request);
    }
}
