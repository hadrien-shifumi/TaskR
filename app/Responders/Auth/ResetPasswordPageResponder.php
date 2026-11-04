<?php

namespace App\Responders\Auth;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ResetPasswordPageResponder
{
    /**
     * @param  array{token: string, email: string}  $data
     */
    public function toResponse(Request $request, array $data): Response
    {
        return Inertia::render('Auth/ResetPassword', $data)->toResponse($request);
    }
}
