<?php

namespace App\Responders\Auth;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class TokensPageResponder
{
    /**
     * @param  array{tokens: array<int, array<string, mixed>>}  $data
     */
    public function toResponse(Request $request, array $data): Response
    {
        return Inertia::render('Settings/Tokens', $data)->toResponse($request);
    }
}
