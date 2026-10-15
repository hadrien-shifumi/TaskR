<?php

namespace App\Actions\Workspaces;

use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Workspaces\ResendInvitationResponder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class ResendInvitation
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        $user = request()->user();
        $workspace = request()->route('workspace');

        return $user instanceof User
            && $workspace instanceof Workspace
            && $workspace->hasOwner($user);
    }

    /**
     * Resend a pending invitation: the acceptance token is regenerated (the
     * previous link stops working) and the expiration is extended. Resending
     * an expired invitation is the main use case and works the same way.
     *
     * The invitation is addressed by its ULID id — the model binds by token
     * for the public acceptance route — and resolved within the route's
     * workspace, so cross-workspace ids 404.
     */
    public function handle(Workspace $workspace, string $invitationId): Invitation
    {
        $invitation = Invitation::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('id', $invitationId)
            ->firstOrFail();

        if ($invitation->accepted_at !== null) {
            abort(409, 'This invitation has already been accepted.');
        }

        $invitation->update([
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);

        return $invitation;
    }

    public function asController(Workspace $workspace, string $invitation, ResendInvitationResponder $responder): RedirectResponse
    {
        $invitation = $this->handle($workspace, $invitation);

        return $responder->resent($invitation);
    }
}
