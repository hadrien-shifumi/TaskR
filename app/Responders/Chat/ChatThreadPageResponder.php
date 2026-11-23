<?php

namespace App\Responders\Chat;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ChatThreadPageResponder
{
    /**
     * @param  array{thread: array<string, mixed>, messages: array<int, array<string, mixed>>, chatEnabled: bool}  $data
     */
    public function toResponse(Request $request, array $data): Response
    {
        return Inertia::render('Chatbot/Show', $data)->toResponse($request);
    }
}
