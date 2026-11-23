<?php

namespace App\Responders\Chat;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ChatbotIndexPageResponder
{
    /**
     * @param  array{threads: array<int, array<string, mixed>>, chatEnabled: bool}  $data
     */
    public function toResponse(Request $request, array $data): Response
    {
        return Inertia::render('Chatbot/Index', $data)->toResponse($request);
    }
}
