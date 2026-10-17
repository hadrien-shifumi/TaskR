<?php

namespace App\Actions\Workspaces;

use App\Models\Membership;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Workspaces\RemoveMemberResponder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class RemoveMember
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
     * Remove a member from the workspace. The acting owner cannot remove
     * themselves — a workspace always keeps at least its acting owner.
     * The member's project roster rows go with the membership: their access
     * scope disappears with their belonging.
     */
    public function handle(Workspace $workspace, User $member, User $actor): void
    {
        if ($actor->id === $member->id) {
            abort(403, 'You cannot remove yourself from the workspace.');
        }

        DB::transaction(function () use ($workspace, $member): void {
            Membership::query()
                ->where('workspace_id', $workspace->id)
                ->where('user_id', $member->id)
                ->firstOrFail()
                ->delete();

            ProjectMember::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->where('user_id', $member->id)
                ->delete();
        });
    }

    public function asController(Workspace $workspace, User $user, RemoveMemberResponder $responder): RedirectResponse
    {
        $this->handle($workspace, $user, request()->user());

        return $responder->removed($user);
    }
}
