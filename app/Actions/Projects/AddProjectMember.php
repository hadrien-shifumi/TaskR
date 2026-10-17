<?php

namespace App\Actions\Projects;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Responders\Projects\ProjectMemberRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class AddProjectMember
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
        $target = User::find(request()->input('user_id'));

        return $target === null
            || ! $project->workspace->isGuest($target)
            || $project->workspace->hasOwner($user);
    }

    public function rules(): array
    {
        $workspace = app(WorkspaceContext::class)->get();

        return [
            'user_id' => [
                'required',
                'integer',
                Rule::exists('memberships', 'user_id')->where('workspace_id', $workspace?->id),
            ],
            'role' => ['nullable', 'string', Rule::in(['owner', 'manager', 'member'])],
        ];
    }

    /**
     * @param  array{user_id: int, role?: string|null}  $data
     */
    public function handle(Project $project, array $data): ProjectMember
    {
        $role = ProjectRole::tryFrom($data['role'] ?? '') ?? ProjectRole::Member;

        // Only one owner per project: reject adding a second owner.
        if ($role === ProjectRole::Owner) {
            $existingOwner = $project->projectMembers()
                ->where('role', ProjectRole::Owner->value)
                ->exists();

            if ($existingOwner) {
                throw ValidationException::withMessages([
                    'role' => [__('validation.project_member.single_owner')],
                ]);
            }
        }

        return $project->projectMembers()->create([
            'user_id' => $data['user_id'],
            'role' => $role->value,
        ]);
    }

    public function asController(ActionRequest $request, Project $project, ProjectMemberRedirectResponder $responder): RedirectResponse
    {
        $this->handle($project, $request->validated());

        return $responder->created($project);
    }
}
