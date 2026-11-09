<?php

namespace App\Actions\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Workspaces\UpdateMemberScopeResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class UpdateMemberScope
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

    public function rules(): array
    {
        // HTTP resolves the workspace from the route binding, agent tools
        // from the request-scoped WorkspaceContext.
        $workspace = request()->route('workspace') ?? app(WorkspaceContext::class)->get();

        return [
            // The full replacement list: empty restores the default full
            // workspace access, every id must be a project of this workspace.
            'project_ids' => ['nullable', 'array'],
            'project_ids.*' => [
                'ulid',
                Rule::exists('projects', 'id')->where('workspace_id', $workspace?->id),
            ],
        ];
    }

    /**
     * Replace the project perimeter granted to a member. An empty list
     * restores the default full workspace access. Guests keep their
     * roster-driven perimeter and the owner is unrestricted by definition,
     * so both are refused as targets.
     *
     * @param  array<int, string>  $projectIds
     */
    public function handle(Workspace $workspace, User $member, array $projectIds): void
    {
        $membership = Membership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $member->id)
            ->firstOrFail();

        if ($membership->role !== WorkspaceRole::Member) {
            throw ValidationException::withMessages([
                'role' => [__('validation.member_scope.members_only')],
            ]);
        }

        $projectIds = array_values($projectIds);

        DB::transaction(function () use ($membership, $projectIds): void {
            $membership->update(['is_scoped' => $projectIds !== []]);
            $membership->syncProjectScope($projectIds);
        });

        // The request may already have resolved this member's scope (list
        // rendering, guards): drop the memo so the fresh rows win.
        app(WorkspaceContext::class)->flushAccessibleProjectScope($member);
    }

    public function asController(ActionRequest $request, Workspace $workspace, User $user, UpdateMemberScopeResponder $responder): RedirectResponse
    {
        $this->handle($workspace, $user, array_values($request->validated()['project_ids'] ?? []));

        return $responder->updated($user);
    }
}
