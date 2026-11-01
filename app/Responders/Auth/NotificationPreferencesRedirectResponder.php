<?php

namespace App\Responders\Auth;

use Illuminate\Http\RedirectResponse;

class NotificationPreferencesRedirectResponder
{
    public function updated(): RedirectResponse
    {
        return redirect()
            ->route('settings.notifications')
            ->with('status', __('flash.notification_preferences.updated'));
    }
}
