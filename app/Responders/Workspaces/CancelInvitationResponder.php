<?php

namespace App\Responders\Workspaces;

use App\Models\Invitation;
use Illuminate\Http\RedirectResponse;

class CancelInvitationResponder
{
    public function cancelled(Invitation $invitation): RedirectResponse
    {
        return redirect()
            ->route('settings.members')
            ->with('status', "Invitation for {$invitation->email} cancelled.");
    }
}
