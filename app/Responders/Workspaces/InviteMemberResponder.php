<?php

namespace App\Responders\Workspaces;

use App\Models\Invitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class InviteMemberResponder
{
    public function toResponse(Request $request, Invitation $invitation, bool $wasCreated = true): RedirectResponse
    {
        // In production this link is emailed. For now it is exposed for the
        // workspace owner to share manually (dogfood-friendly).
        $link = route('invitations.accept', ['invitation' => $invitation->token], absolute: false);

        // Re-inviting an existing address reactivates its invitation row:
        // the flash says "resent", not "created".
        $message = $wasCreated ? 'flash.invitation.created' : 'flash.invitation.resent';

        return redirect()
            ->back()
            ->with('status', __($message, ['email' => $invitation->email, 'link' => $link]));
    }
}
