<?php

namespace App\Responders\Projects;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ProjectMemberActivityResponder
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function toResponse(Request $request, array $data): Response
    {
        return Inertia::render('Projects/MemberActivity', $data)->toResponse($request);
    }
}
