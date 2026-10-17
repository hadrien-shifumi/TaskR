<?php

namespace App\Actions\Projects;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Responders\Projects\ProjectMemberRedirectResponder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class UpdateProjectMemberRole
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        $user = request()->user();
        $project = request()->route('project');

        if (! $user instanceof User || ! $project instanceof Project) {
            return false;
        }

        // Only the project owner (or workspace owner) can change roles.
        $role = $project->roleOf($user);

        if (! $project->workspace->hasOwner($user) && $role !== ProjectRole::Owner) {
            return false;
        }

        // A guest's roster row is their access scope: only the workspace
        // owner pilots guests, even a project owner of another project.
        $target = request()->route('member');

        return ! $target instanceof User
            || ! $project->workspace->isGuest($target)
            || $project->workspace->hasOwner($user);
    }

    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(['owner', 'manager', 'member'])],
        ];
    }

    /**
     * @param  array{role: string}  $data
     */
    public function handle(Project $project, User $member, array $data): ProjectMember
    {
        $newRole = ProjectRole::from($data['role']);

        $projectMember = $project->projectMembers()
            ->where('user_id', $member->getKey())
            ->firstOrFail();

        // A guest is a restricted member: they never own the project team.
        if ($newRole === ProjectRole::Owner && $project->workspace->isGuest($member)) {
            throw ValidationException::withMessages([
                'role' => [__('validation.project_member.guest_cannot_own_project')],
            ]);
        }

        return DB::transaction(function () use ($project, $projectMember, $newRole): ProjectMember {
            // Transferring ownership: the previous owner becomes a regular member.
            if ($newRole === ProjectRole::Owner && $projectMember->role !== ProjectRole::Owner) {
                $currentOwner = $project->projectMembers()
                    ->where('role', ProjectRole::Owner->value)
                    ->first();

                if ($currentOwner !== null) {
                    $currentOwner->update(['role' => ProjectRole::Member->value]);
                }
            }

            $projectMember->update(['role' => $newRole->value]);

            return $projectMember->refresh();
        });
    }

    public function asController(ActionRequest $request, Project $project, User $member, ProjectMemberRedirectResponder $responder): RedirectResponse
    {
        $this->handle($project, $member, $request->validated());

        return $responder->updated($project);
    }
}
