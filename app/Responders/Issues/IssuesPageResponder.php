<?php

namespace App\Responders\Issues;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;

class IssuesPageResponder
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function toResponse(Request $request, array $data): Response
    {
        return Inertia::render('Issues/Index', $data)->toResponse($request);
    }
}
