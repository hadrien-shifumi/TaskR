<?php

namespace App\Actions\Workspaces;

use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Models\Invitation;
use App\Models\InvitationProject;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
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

            // The membership carries the invited role; an already-member
            // keeps their current one (no duplicate, no downgrade).
            Membership::firstOrCreate(
                [
                    'user_id' => $user->getKey(),
                    'workspace_id' => $invitation->workspace_id,
                ],
                ['role' => $invitation->role->value],
            );

            // A guest joins with the perimeter announced at invitation
            // time: one roster row per linked project. The acceptance route
            // has no workspace context, so the ids come from the pivot (an
            // unscoped model), never through the Project global scope.
            if ($invitation->role === WorkspaceRole::Guest) {
                $projectIds = InvitationProject::query()
                    ->where('invitation_id', $invitation->getKey())
                    ->pluck('project_id');

                foreach ($projectIds as $projectId) {
                    ProjectMember::firstOrCreate(
                        [
                            'workspace_id' => $invitation->workspace_id,
                            'project_id' => $projectId,
                            'user_id' => $user->getKey(),
                        ],
                        ['role' => ProjectRole::Member->value],
                    );
                }
            }

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

            // No workspace context on this route: read the announced scope
            // through the unscoped pivot, then the project names without the
            // global scope.
            $scopeProjectIds = InvitationProject::query()
                ->where('invitation_id', $invitation->getKey())
                ->pluck('project_id');

            return Inertia::render('Auth/AcceptInvitation', [
                'invitation' => [
                    'workspaceName' => $workspace->name,
                    'inviterName' => $owner?->name ?? $workspace->name,
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'projects' => $invitation->role === WorkspaceRole::Guest
                        ? Project::withoutWorkspaceScope()
                            ->whereIn('id', $scopeProjectIds)
                            ->orderBy('name')
                            ->pluck('name')
                            ->all()
                        : [],
                    'acceptUrl' => "/invitations/{$invitation->token}/accept",
                ],
            ])->toResponse(request());
        }

        $workspace = $this->handle($invitation, $user);

        session(['workspace_id' => $workspace->id]);
        $user->forceFill(['current_workspace_id' => $workspace->id])->saveQuietly();

        return redirect()->route('home')->with('status', __('flash.joined_workspace', ['name' => $workspace->name]));
    }
}
