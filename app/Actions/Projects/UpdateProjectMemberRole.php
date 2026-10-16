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

        return $project->workspace->hasOwner($user) || $role === ProjectRole::Owner;
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
