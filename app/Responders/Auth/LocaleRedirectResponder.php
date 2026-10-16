<?php

namespace App\Responders\Auth;

use Illuminate\Http\RedirectResponse;

class LocaleRedirectResponder
{
    public function updated(): RedirectResponse
    {
        // The switcher lives in the layout, so the user may be on any page:
        // return them where they came from.
        return back()->with('status', __('flash.locale.updated'));
    }
}
