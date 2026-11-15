<?php

namespace App\Responders\Auth;

use App\Models\User;
use App\Support\ReturnUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LoginResponder
{
    public function toResponse(Request $request, User $user): RedirectResponse
    {
        // Invitation and other guest pages forward their return URL through
        // the login form; only same-app paths are honored (open-redirect guard).
        $requested = ReturnUrl::sanitize($request->input('redirect'));

        if ($requested !== null) {
            return redirect()->to($requested);
        }

        return redirect()->intended(route('home', absolute: false));
    }
}
