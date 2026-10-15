<?php

namespace App\Responders\Auth;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LoginResponder
{
    public function toResponse(Request $request, User $user): RedirectResponse
    {
        // Invitation and other guest pages forward their return URL through
        // the login form; only same-app paths are honored (open-redirect guard).
        $requested = $request->input('redirect');

        if (is_string($requested) && str_starts_with($requested, '/') && ! str_starts_with($requested, '//')) {
            return redirect()->to($requested);
        }

        return redirect()->intended(route('home', absolute: false));
    }
}
