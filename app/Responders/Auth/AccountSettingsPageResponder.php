<?php

namespace App\Responders\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class AccountSettingsPageResponder
{
    public function toResponse(Request $request, User $user): Response
    {
        return Inertia::render('Settings/Account')->toResponse($request);
    }
}
