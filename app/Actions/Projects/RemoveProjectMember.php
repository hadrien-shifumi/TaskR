<?php

namespace App\Actions\Projects;

use App\Enums\ProjectRole;
use App\Models\Project;
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

        return $user instanceof User
            && $project instanceof Project
            && $project->canManage($user);
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

        $projectMember->delete();
    }

    public function asController(ActionRequest $request, Project $project, User $member, ProjectMemberRedirectResponder $responder): RedirectResponse
    {
        $this->handle($project, $member);

        return $responder->deleted($project);
    }
}
