<?php

namespace App\Actions\Milestones;

use App\Actions\Audit\RecordActivity;
use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\AuditEventType;
use App\Models\Milestone;
use App\Models\Project;
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

class CreateMilestone
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
     * Create a milestone on a project. Writing milestones is reserved to
     * the project roster and the workspace owner; guests never write.
     *
     * @param  array{title: string, description?: string|null, start_date?: string|null, target_date?: string|null}  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Project $project, array $data, ?User $user = null): Milestone
    {
        // Out-of-scope projects fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessProjectAccessible($user, $project);

            if (! $project->isContributor($user)) {
                throw new AuthorizationException('Only project members and the workspace owner can create milestones.');
            }
        }

        $this->assertDatesCoherent($data['start_date'] ?? null, $data['target_date'] ?? null);

        $milestone = Milestone::create([
            'project_id' => $project->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'target_date' => $data['target_date'] ?? null,
            'created_by' => $user?->getKey(),
        ]);

        $this->recordCreation($milestone, $user);

        return $milestone->refresh();
    }

    public function asController(ActionRequest $request, Project $project, MilestoneRedirectResponder $responder): RedirectResponse
    {
        $milestone = $this->handle($project, $request->validated(), $request->user());

        return $responder->created($milestone);
    }

    /**
     * The start date precedes (or equals) the target date whenever both
     * are set — a persisted-state-free rule, but a cross-field one, so
     * it lives here rather than in rules().
     *
     * @throws ValidationException
     */
    private function assertDatesCoherent(?string $startDate, ?string $targetDate): void
    {
        if ($startDate === null || $targetDate === null) {
            return;
        }

        if (Carbon::parse($startDate)->gt(Carbon::parse($targetDate))) {
            throw ValidationException::withMessages([
                'target_date' => [__('validation.milestone.target_date_before_start_date')],
            ]);
        }
    }

    /**
     * Journal the creation with the created fields (before null). Empty
     * optional fields (missing description or dates) stay out of the
     * diff to keep the event light.
     */
    private function recordCreation(Milestone $milestone, ?User $user): void
    {
        $changes = [
            'title' => ['before' => null, 'after' => $milestone->title],
        ];

        if ($milestone->description !== null) {
            $changes['description'] = ['before' => null, 'after' => $milestone->description];
        }

        if ($milestone->start_date !== null) {
            $changes['start_date'] = ['before' => null, 'after' => $milestone->start_date->toDateString()];
        }

        if ($milestone->target_date !== null) {
            $changes['target_date'] = ['before' => null, 'after' => $milestone->target_date->toDateString()];
        }

        RecordActivity::run(AuditEventType::MilestoneCreated, $milestone, $user, $changes);
    }
}
