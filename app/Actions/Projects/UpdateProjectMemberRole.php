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

        $target = request()->route('member');
        $currentRole = $target instanceof User ? $project->roleOf($target) : null;
        $newRole = ProjectRole::tryFrom((string) request()->input('role'));

        // Unknown roster line or role: the validation and the handle
        // keep their historical refusals (422 / 404).
        if ($currentRole === null || $newRole === null) {
            return true;
        }

        // Ownership transfer keeps its statu quo: the project owner or
        // the workspace owner transfers, the previous owner is demoted.
        if ($newRole === ProjectRole::Owner) {
            return $project->workspace->hasOwner($user)
                || $project->roleOf($user) === ProjectRole::Owner;
        }

        // N+1 (D9): the target line and the assigned role both sit
        // strictly below the actor — never a pair, never a superior.
        // The workspace owner bypasses (D11); the workspace role of
        // the target never appears (D7).
        return $project->canPilotRole($user, $currentRole)
            && $project->canPilotRole($user, $newRole);
    }

    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(['owner', 'manager', 'member', 'guest'])],
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
