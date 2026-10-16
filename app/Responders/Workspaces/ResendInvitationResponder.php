<?php

namespace App\Responders\Workspaces;

use App\Models\Invitation;
use Illuminate\Http\RedirectResponse;

class ResendInvitationResponder
{
    /**
     * Same dogfood contract as the invite flow: there is no emailing yet,
     * the owner shares the fresh acceptance link manually.
     */
    public function resent(Invitation $invitation): RedirectResponse
    {
        $link = route('invitations.accept', ['invitation' => $invitation->token], absolute: false);

        return redirect()
            ->route('settings.members')
            ->with('status', __('flash.invitation.resent', ['email' => $invitation->email, 'link' => $link]));
    }
}
