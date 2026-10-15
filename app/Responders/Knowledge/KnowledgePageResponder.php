<?php

namespace App\Responders\Knowledge;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class KnowledgePageResponder
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function toResponse(Request $request, array $data): Response
    {
        return Inertia::render('Knowledge/Index', $data)->toResponse($request);
    }
}
