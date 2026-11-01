<?php

namespace App\Responders\Mentions;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class MentionsPageResponder
{
    /**
     * @param  array{mentions: list<array<string, mixed>>, unreadCount: int}  $data
     */
    public function toResponse(Request $request, array $data): Response
    {
        return Inertia::render('Mentions/Index', $data)->toResponse($request);
    }
}
