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

        if (! $user instanceof User || ! $project instanceof Project) {
            return false;
        }

        // N+1 (D10): a manager or above onboards strictly lower roles
        // only; the workspace owner bypasses the hierarchy (D11). The
        // workspace role of the invitee never appears (D7). A second
        // owner stays refused for everyone by the single-owner rule.
        $role = ProjectRole::tryFrom((string) request()->input('role')) ?? ProjectRole::Member;

        return $project->canPilotRole($user, $role);
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
            'role' => ['nullable', 'string', Rule::in(['owner', 'manager', 'member', 'guest'])],
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
