<?php

namespace App\Actions\Workspaces;

use App\Actions\Audit\RecordActivity;
use App\Enums\AuditEventType;
use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Workspaces\UpdateMemberRoleResponder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class UpdateMemberWorkspaceRole
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        $user = request()->user();
        $workspace = request()->route('workspace');

        // D8: the workspace owner alone pilots the member/guest toggle.
        return $user instanceof User
            && $workspace instanceof Workspace
            && $workspace->hasOwner($user);
    }

    public function rules(): array
    {
        return [
            // The owner seat never rides this toggle: awarding it is the
            // explicit ownership transfer, a distinct action.
            'role' => ['required', 'string', Rule::in([
                WorkspaceRole::Member->value,
                WorkspaceRole::Guest->value,
            ])],
        ];
    }

    /**
     * Move a member between the two non-owner workspace roles. The
     * project rosters are never touched (D7): a workspace role change
     * rewrites no team line — a guest workspace member stays Manager of
     * the projects they manage.
     *
     * @param  array{role: string}  $data
     */
    public function handle(Workspace $workspace, User $member, array $data, User $actor): Membership
    {
        $membership = Membership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $member->id)
            ->firstOrFail();

        // The owner seat moves only through the explicit transfer.
        if ($membership->role === WorkspaceRole::Owner) {
            abort(403, 'The workspace owner role can only be transferred explicitly.');
        }

        $newRole = WorkspaceRole::from($data['role']);

        if ($membership->role === $newRole) {
            return $membership;
        }

        $previousRole = $membership->role;

        $membership->update(['role' => $newRole->value]);

        // The workspace instance memoizes the resolved roles: without the
        // flush, a read later in the same request serves the pre-write
        // role.
        $workspace->flushMembershipRoleCache($member);

        RecordActivity::run(AuditEventType::MembershipRoleChanged, $workspace, $actor, [
            'member' => ['id' => $member->getKey(), 'name' => $member->name],
            'role' => ['before' => $previousRole->value, 'after' => $newRole->value],
        ]);

        return $membership;
    }

    public function asController(ActionRequest $request, Workspace $workspace, User $user, UpdateMemberRoleResponder $responder): RedirectResponse
    {
        $this->handle($workspace, $user, $request->validated(), $request->user());

        return $responder->updated($user);
    }
}
