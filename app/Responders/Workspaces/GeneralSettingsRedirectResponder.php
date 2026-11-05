<?php

namespace App\Responders\Workspaces;

use Illuminate\Http\RedirectResponse;

class GeneralSettingsRedirectResponder
{
    public function updated(): RedirectResponse
    {
        return redirect()
            ->route('settings.general')
            ->with('status', __('flash.general_settings.updated'));
    }
}
