<?php

namespace App\Responders\System;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;

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
