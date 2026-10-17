<?php

namespace App\Actions\Projects;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Responders\Projects\ProjectMemberRedirectResponder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class RemoveProjectMember
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        $user = request()->user();
        $project = request()->route('project');

        if (! $user instanceof User
            || ! $project instanceof Project
            || ! $project->canManage($user)) {
            return false;
        }

        // A guest's roster row is their access scope: only the workspace
        // owner pilots guests. Project managers keep managing classic members.
        $target = request()->route('member');

        return ! $target instanceof User
            || ! $project->workspace->isGuest($target)
            || $project->workspace->hasOwner($user);
    }

    public function handle(Project $project, User $member): void
    {
        $projectMember = $project->projectMembers()
            ->where('user_id', $member->getKey())
            ->firstOrFail();

        // The project owner cannot be removed from the roster.
        if ($projectMember->role === ProjectRole::Owner) {
            throw ValidationException::withMessages([
                'user_id' => [__('validation.project_member.cannot_remove_owner')],
            ]);
        }

        // A guest keeps at least one project: removing their whole scope
        // goes through the workspace removal.
        if ($project->workspace->isGuest($member)) {
            $remainingScope = ProjectMember::withoutWorkspaceScope()
                ->where('workspace_id', $project->workspace_id)
                ->where('user_id', $member->getKey())
                ->count();

            if ($remainingScope <= 1) {
                throw ValidationException::withMessages([
                    'user_id' => [__('validation.project_member.cannot_remove_last_guest_project')],
                ]);
            }
        }

        $projectMember->delete();
    }

    public function asController(ActionRequest $request, Project $project, User $member, ProjectMemberRedirectResponder $responder): RedirectResponse
    {
        $this->handle($project, $member);

        return $responder->deleted($project);
    }
}
