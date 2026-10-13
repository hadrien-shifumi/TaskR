<?php

namespace App\Responders\System;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class HomeResponder
{
    /**
     * @param  array{appName: string}  $data
     */
    public function toResponse(Request $request, array $data): Response
    {
        return Inertia::render('Home', $data)->toResponse($request);
    }
}
