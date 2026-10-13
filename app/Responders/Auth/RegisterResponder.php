<?php

namespace App\Responders\Auth;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RegisterResponder
{
    public function toResponse(Request $request, User $user): RedirectResponse
    {
        return redirect()->intended(route('home', absolute: false));
    }
}
