<?php

namespace App\Responders\Auth;

use Illuminate\Http\RedirectResponse;

class PasswordRedirectResponder
{
    public function updated(): RedirectResponse
    {
        return redirect()
            ->route('settings.account')
            ->with('status', __('flash.password.updated'));
    }
}
