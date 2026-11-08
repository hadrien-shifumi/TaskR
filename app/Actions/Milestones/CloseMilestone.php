<?php

namespace App\Actions\Milestones;

use App\Actions\Audit\RecordActivity;
use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\AuditEventType;
use App\Models\Milestone;
use App\Models\User;
use App\Responders\Milestones\MilestoneRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class CloseMilestone
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * Manually close a milestone: the completion date is set to now and
     * only this action writes it. Closing an already closed milestone is
     * a no-op and journals nothing, and a milestone whose start date is
     * still in the future cannot be closed yet.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Milestone $milestone, ?User $user = null): Milestone
    {
        // Out-of-scope milestones fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessMilestoneAccessible($user, $milestone);

            if (! $milestone->project->isContributor($user)) {
                throw new AuthorizationException('Only project members and the workspace owner can close milestones.');
            }
        }

        if ($milestone->completed_at !== null) {
            return $milestone;
        }

        $completedAt = Carbon::now();

        if ($milestone->start_date !== null && $completedAt->startOfDay()->lt($milestone->start_date)) {
            throw ValidationException::withMessages([
                'completed_at' => [__('validation.milestone.completed_before_start_date')],
            ]);
        }

        $before = $milestone->completed_at?->toISOString();

        $milestone->update(['completed_at' => $completedAt]);

        RecordActivity::run(AuditEventType::MilestoneClosed, $milestone, $user, [
            'completed_at' => ['before' => $before, 'after' => $milestone->completed_at->toISOString()],
        ]);

        return $milestone->refresh();
    }

    public function asController(Request $request, Milestone $milestone, MilestoneRedirectResponder $responder): RedirectResponse
    {
        $milestone = $this->handle($milestone, $request->user());

        return $responder->closed($milestone);
    }
}
