<?php

namespace App\Actions\Workspaces;

use App\Actions\Audit\RecordActivity;
use App\Enums\AuditEventType;
use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Workspaces\TransferOwnershipResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class TransferWorkspaceOwnership
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
     * Hand the workspace ownership to an existing member: the previous
     * owner steps down to a plain member, the target becomes the owner.
     * Exactly one owner before, during and after — the swap runs inside
     * a transaction that locks both membership rows, so concurrent
     * transfers serialize instead of racing to a zero-owner or two-owner
     * workspace. The project rosters are never touched (D7): the
     * previous owner, who never carried team rows, is roster-driven from
     * now on.
     */
    public function handle(Workspace $workspace, User $newOwner, User $actor): void
    {
        if ($actor->id === $newOwner->id) {
            abort(403, 'You cannot transfer the workspace ownership to yourself.');
        }

        DB::transaction(function () use ($workspace, $newOwner, $actor): void {
            // Lock the acting row first, always in the same order: every
            // transfer starts from the (single) owner row, so two
            // concurrent transfers queue here instead of deadlocking.
            $actorRow = Membership::query()
                ->where('workspace_id', $workspace->id)
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->firstOrFail();

            // The authorize() check rode the request-time memo; under the
            // lock the row is the truth. An owner dethroned by a
            // concurrent transfer must not dethrone the fresh one.
            if ($actorRow->role !== WorkspaceRole::Owner) {
                abort(403, 'Only the workspace owner can transfer the ownership.');
            }

            $targetRow = Membership::query()
                ->where('workspace_id', $workspace->id)
                ->where('user_id', $newOwner->id)
                ->lockForUpdate()
                ->firstOrFail();

            $actorRow->update(['role' => WorkspaceRole::Member->value]);
            $targetRow->update(['role' => WorkspaceRole::Owner->value]);

            // The journal rides the transaction: a rolled-back transfer
            // must never leave a trace.
            RecordActivity::run(AuditEventType::WorkspaceOwnershipTransferred, $workspace, $actor, [
                'owner' => [
                    'before' => ['id' => $actor->getKey(), 'name' => $actor->name],
                    'after' => ['id' => $newOwner->getKey(), 'name' => $newOwner->name],
                ],
            ]);
        });

        // Same-request readers (authorization, Inertia shares, scoping)
        // must see the swap: the workspace memoizes roles and the context
        // memoizes project scopes — the previous owner is roster-driven
        // now, the new one unrestricted.
        $workspace->flushMembershipRoleCache();
        app(WorkspaceContext::class)->flushAccessibleProjectScope();
    }

    public function asController(ActionRequest $request, Workspace $workspace, User $user, TransferOwnershipResponder $responder): RedirectResponse
    {
        $this->handle($workspace, $user, $request->user());

        return $responder->transferred($user);
    }
}
