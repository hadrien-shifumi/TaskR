<?php

namespace App\Responders\Workspaces;

use App\Models\Invitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class InviteMemberResponder
{
    public function toResponse(Request $request, Invitation $invitation): RedirectResponse
    {
        // In production this link is emailed. For now it is exposed for the
        // workspace owner to share manually (dogfood-friendly).
        $link = route('invitations.accept', ['invitation' => $invitation->token], absolute: false);

        return redirect()
            ->back()
            ->with('status', __('flash.invitation.created', ['email' => $invitation->email, 'link' => $link]));
    }
}
