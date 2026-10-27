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

    /**
     * @param  array<string, mixed>  $data
     */
    public function trash(Request $request, array $data): Response
    {
        return Inertia::render('Knowledge/Trash', $data)->toResponse($request);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function versions(Request $request, array $data): Response
    {
        return Inertia::render('Knowledge/Versions', $data)->toResponse($request);
    }
}
