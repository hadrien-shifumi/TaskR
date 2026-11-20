<?php

namespace App\Responders\Knowledge;

use Illuminate\Http\RedirectResponse;

class ChatSettingsRedirectResponder
{
    public function updated(): RedirectResponse
    {
        return redirect()
            ->route('settings.embeddings')
            ->with('status', __('flash.chat.updated'));
    }
}
