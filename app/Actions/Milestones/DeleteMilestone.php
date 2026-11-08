<?php

namespace App\Actions\Milestones;

use App\Actions\Audit\RecordActivity;
use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\AuditEventType;
use App\Models\Milestone;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Milestones\MilestoneRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class DeleteMilestone
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * Delete a milestone. Attached issues are detached (they lose their
     * milestone, nothing else), and child milestones climb to the
     * deleted milestone's parent. Nothing is deleted in cascade.
     *
     * @throws AuthorizationException
     */
    public function handle(Milestone $milestone, ?User $user = null): Milestone
    {
        // Out-of-scope milestones fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessMilestoneAccessible($user, $milestone);

            if (! $this->canDelete($milestone, $user)) {
                throw new AuthorizationException('Only project managers and the workspace owner can delete milestones.');
            }
        }

        // The children climb to the deleted milestone's parent (null for
        // a root: they become roots themselves) before the row goes.
        $childrenIds = $milestone->children()->pluck('milestones.id')->sort()->values()->all();
        $milestone->children()->update(['parent_id' => $milestone->parent_id]);

        // The journal is written right before the destructive write: the
        // subject FK requires the milestone to still exist, and the DB
        // then nulls it on delete. The event stays readable through its
        // project and this loss inventory; the issues detach at delete
        // time through the nullOnDelete FK.
        RecordActivity::run(AuditEventType::MilestoneDeleted, $milestone, $user, [
            'title' => $milestone->title,
            'description' => $milestone->description,
            'start_date' => $milestone->start_date?->toDateString(),
            'target_date' => $milestone->target_date?->toDateString(),
            'completed_at' => $milestone->completed_at?->toISOString(),
            'parent_id' => $milestone->parent_id,
            'issues_count' => $milestone->issues()->count(),
            'children' => $childrenIds,
            'children_reparented_to' => $milestone->parent_id,
        ]);

        $milestone->delete();

        return $milestone;
    }

    public function asController(Request $request, Milestone $milestone, MilestoneRedirectResponder $responder): RedirectResponse
    {
        $this->handle($milestone, $request->user());

        return $responder->deleted();
    }

    /**
     * Deletion follows the roster-management permission (project
     * owner/manager plus workspace owner); guests never delete.
     */
    private function canDelete(Milestone $milestone, User $user): bool
    {
        /** @var Workspace $workspace */
        $workspace = $milestone->workspace;

        return ! $workspace->isGuest($user) && $milestone->project->canManage($user);
    }
}
