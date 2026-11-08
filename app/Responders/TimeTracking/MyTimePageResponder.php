<?php

namespace App\Responders\TimeTracking;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class MyTimePageResponder
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function toResponse(Request $request, array $data): Response
    {
        return Inertia::render('MyTime/Index', $data)->toResponse($request);
    }
}
