<?php

namespace App\Responders\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;

class RegisterPageResponder
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function toResponse(Request $request, array $data): Response
    {
        return Inertia::render('Auth/Register', $data)->toResponse($request);
    }
}
