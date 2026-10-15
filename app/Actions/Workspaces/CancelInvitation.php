<?php

namespace App\Actions\Workspaces;

use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Workspaces\CancelInvitationResponder;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class CancelInvitation
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
     * Cancel a pending invitation: the row is deleted and its acceptance
     * link stops working. An accepted invitation is already consumed and
     * cannot be cancelled; an expired one is still pending and can.
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

        $invitation->delete();

        return $invitation;
    }

    public function asController(Workspace $workspace, string $invitation, CancelInvitationResponder $responder): RedirectResponse
    {
        $invitation = $this->handle($workspace, $invitation);

        return $responder->cancelled($invitation);
    }
}
