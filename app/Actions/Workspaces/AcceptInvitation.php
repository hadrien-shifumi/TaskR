<?php

namespace App\Actions\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class AcceptInvitation
{
    use AsController;
    use AsObject;

    public function handle(Invitation $invitation, User $user): Workspace
    {
        return DB::transaction(function () use ($invitation, $user): Workspace {
            $invitation = Invitation::withoutWorkspaceScope()->findOrFail($invitation->id);

            if (! $invitation->isUsable()) {
                abort(410, 'This invitation is no longer valid.');
            }

            Membership::firstOrCreate(
                [
                    'user_id' => $user->getKey(),
                    'workspace_id' => $invitation->workspace_id,
                ],
                ['role' => WorkspaceRole::Member->value],
            );

            $invitation->update(['accepted_at' => now()]);

            return $invitation->workspace;
        });
    }

    public function asController(Invitation $invitation): RedirectResponse|Response
    {
        $user = request()->user();

        if (! $invitation->isUsable()) {
            abort(410, 'This invitation is no longer valid.');
        }

        // Guests cannot accept yet: they get the invitation landing page and
        // are sent through login with the acceptance URL as the return target.
        if (! $user instanceof User) {
            $workspace = $invitation->workspace;
            $owner = $workspace->memberships()
                ->where('role', WorkspaceRole::Owner->value)
                ->first()
                ?->user;

            return Inertia::render('Auth/AcceptInvitation', [
                'invitation' => [
                    'workspaceName' => $workspace->name,
                    'inviterName' => $owner?->name ?? $workspace->name,
                    'email' => $invitation->email,
                    'role' => WorkspaceRole::Member->value,
                    'acceptUrl' => "/invitations/{$invitation->token}/accept",
                ],
            ])->toResponse(request());
        }

        $workspace = $this->handle($invitation, $user);

        session(['workspace_id' => $workspace->id]);

        return redirect()->route('home')->with('status', "You joined {$workspace->name}.");
    }
}
