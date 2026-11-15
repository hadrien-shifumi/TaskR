<?php

namespace App\Actions\Workspaces;

use App\Enums\WorkspaceRole;
use App\Exceptions\InvitationEmailMismatchException;
use App\Models\Invitation;
use App\Models\InvitationProject;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\EmailMask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
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

            // The acceptance is reserved to the invited address: any other
            // account is refused before a single row is written, whatever
            // the caller (direct hit, post-login replay, future flows).
            if (strcasecmp($user->email, $invitation->email) !== 0) {
                throw new InvitationEmailMismatchException($invitation->email);
            }

            // The membership carries the invited role; an already-member
            // keeps their current one (no duplicate, no downgrade).
            $membership = Membership::firstOrCreate(
                [
                    'user_id' => $user->getKey(),
                    'workspace_id' => $invitation->workspace_id,
                ],
                ['role' => $invitation->role->value],
            );

            // A guest joins with the projects announced at invitation
            // time, each with its role: one roster row per linked project.
            // The acceptance route has no workspace context, so the rows
            // come from the pivot (an unscoped model), never through the
            // Project global scope.
            if ($invitation->role === WorkspaceRole::Guest) {
                $announced = InvitationProject::query()
                    ->where('invitation_id', $invitation->getKey())
                    ->get();

                $this->joinProjectTeams($invitation->workspace_id, $user->getKey(), $announced);
            }

            // A member joins with the teams announced at invitation time.
            // An already-member keeps their current role (no downgrade),
            // a guest keeps their roster-driven perimeter and the owner
            // stays unrestricted — only a member gains team rows here.
            // An invitation without projects leaves the member teamless:
            // no default full access anymore.
            if ($invitation->role === WorkspaceRole::Member && $membership->role === WorkspaceRole::Member) {
                $announced = InvitationProject::query()
                    ->where('invitation_id', $invitation->getKey())
                    ->get();

                $this->joinProjectTeams($invitation->workspace_id, $user->getKey(), $announced);
            }

            $invitation->update(['accepted_at' => now()]);

            return $invitation->workspace;
        });
    }

    /**
     * Add the user to each announced project's team with the role the
     * invitation carries for that project. The roster row is the real
     * project team and the real access grant; an existing row (manual
     * role) is left untouched.
     *
     * @param  Collection<int, InvitationProject>  $announced
     */
    private function joinProjectTeams(string $workspaceId, int $userId, Collection $announced): void
    {
        foreach ($announced as $project) {
            ProjectMember::firstOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'project_id' => $project->project_id,
                    'user_id' => $userId,
                ],
                ['role' => $project->role->value],
            );
        }
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

            // No workspace context on this route: read the announced
            // projects through the unscoped pivot, then the project names
            // without the global scope. Guests and members alike announce
            // their projects, each with the role the invitee lands with.
            $announced = InvitationProject::query()
                ->where('invitation_id', $invitation->getKey())
                ->get();

            $scopeProjects = $announced->isEmpty()
                ? []
                : Project::withoutWorkspaceScope()
                    ->whereIn('id', $announced->pluck('project_id'))
                    ->orderBy('name')
                    ->get()
                    ->map(fn (Project $project): array => [
                        'name' => $project->name,
                        'role' => $announced->firstWhere('project_id', $project->id)->role->value,
                    ])
                    ->all();

            return Inertia::render('Auth/AcceptInvitation', [
                'invitation' => [
                    'workspaceName' => $workspace->name,
                    'inviterName' => $owner?->name ?? $workspace->name,
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'projects' => $scopeProjects,
                    'acceptUrl' => "/invitations/{$invitation->token}/accept",
                ],
            ])->toResponse(request());
        }

        try {
            $workspace = $this->handle($invitation, $user);
        } catch (InvitationEmailMismatchException) {
            // Explicit refusal page: the invited address is shown masked,
            // nothing was created and the workspace stays closed.
            return Inertia::render('Auth/InvitationEmailMismatch', [
                'invitation' => [
                    'email' => EmailMask::mask($invitation->email),
                ],
            ])->toResponse(request())
                ->setStatusCode(403);
        }

        session(['workspace_id' => $workspace->id]);
        $user->forceFill(['current_workspace_id' => $workspace->id])->saveQuietly();

        return redirect()->route('home')->with('status', __('flash.joined_workspace', ['name' => $workspace->name]));
    }
}
