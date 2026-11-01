<?php

namespace App\Responders\Auth;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class NotificationSettingsPageResponder
{
    /**
     * @param  array{preferences: array<string, mixed>, timezones: array<int, string>}  $data
     */
    public function toResponse(Request $request, array $data): Response
    {
        return Inertia::render('Settings/Notifications', $data)->toResponse($request);
    }
}
