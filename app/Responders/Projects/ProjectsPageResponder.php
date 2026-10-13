<?php

namespace App\Responders\Projects;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ProjectsPageResponder
{
    /**
     * @param  array{projects: array{active: array<array-key, array<string, mixed>>, archived: array<array-key, array<string, mixed>>}}  $data
     */
    public function toResponse(Request $request, array $data): Response
    {
        return Inertia::render('Projects/Index', $data)->toResponse($request);
    }
}
