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
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class UpdateMilestone
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:50000'],
            'start_date' => ['nullable', 'date'],
            'target_date' => ['nullable', 'date'],
        ];
    }

    /**
     * Edit the descriptive fields of a milestone. The completion date is
     * owned by the manual close action, and the project never moves.
     *
     * @param  array{title: string, description?: string|null, start_date?: string|null, target_date?: string|null}  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Milestone $milestone, array $data, ?User $user = null): Milestone
    {
        // Out-of-scope milestones fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessMilestoneAccessible($user, $milestone);

            if (! $milestone->project->isContributor($user)) {
                throw new AuthorizationException('Only project members and the workspace owner can edit milestones.');
            }
        }

        $startDate = $data['start_date'] ?? null;
        $targetDate = $data['target_date'] ?? null;

        $this->assertDatesCoherent($milestone, $startDate, $targetDate);

        $before = [
            'title' => $milestone->title,
            'description' => $milestone->description,
            'start_date' => $milestone->start_date?->toDateString(),
            'target_date' => $milestone->target_date?->toDateString(),
        ];

        $milestone->update([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'start_date' => $startDate,
            'target_date' => $targetDate,
        ]);

        $this->recordUpdate($milestone, $before, $user);

        return $milestone->refresh();
    }

    public function asController(ActionRequest $request, Milestone $milestone, MilestoneRedirectResponder $responder): RedirectResponse
    {
        $milestone = $this->handle($milestone, $request->validated(), $request->user());

        return $responder->updated($milestone);
    }

    /**
     * The start date precedes (or equals) the target date whenever both
     * are set, and never slides past an existing completion date.
     *
     * @throws ValidationException
     */
    private function assertDatesCoherent(Milestone $milestone, ?string $startDate, ?string $targetDate): void
    {
        if ($startDate !== null && $targetDate !== null
            && Carbon::parse($startDate)->gt(Carbon::parse($targetDate))) {
            throw ValidationException::withMessages([
                'target_date' => [__('validation.milestone.target_date_before_start_date')],
            ]);
        }

        if ($startDate !== null && $milestone->completed_at !== null
            && Carbon::parse($startDate)->gt($milestone->completed_at)) {
            throw ValidationException::withMessages([
                'start_date' => [__('validation.milestone.completed_before_start_date')],
            ]);
        }
    }

    /**
     * Journal the edit as one event with the changed fields. A no-op
     * update (same values) journals nothing.
     *
     * @param  array{title: string, description: ?string, start_date: ?string, target_date: ?string}  $before
     */
    private function recordUpdate(Milestone $milestone, array $before, ?User $user): void
    {
        $changes = [];

        if ($milestone->title !== $before['title']) {
            $changes['title'] = ['before' => $before['title'], 'after' => $milestone->title];
        }

        if ($milestone->description !== $before['description']) {
            $changes['description'] = ['before' => $before['description'], 'after' => $milestone->description];
        }

        $afterStartDate = $milestone->start_date?->toDateString();
        if ($afterStartDate !== $before['start_date']) {
            $changes['start_date'] = ['before' => $before['start_date'], 'after' => $afterStartDate];
        }

        $afterTargetDate = $milestone->target_date?->toDateString();
        if ($afterTargetDate !== $before['target_date']) {
            $changes['target_date'] = ['before' => $before['target_date'], 'after' => $afterTargetDate];
        }

        if ($changes !== []) {
            RecordActivity::run(AuditEventType::MilestoneUpdated, $milestone, $user, $changes);
        }
    }
}
