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
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class SetMilestoneParent
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
            'parent_id' => [
                'nullable', 'ulid',
                Rule::exists('milestones', 'id')->where('workspace_id', app(WorkspaceContext::class)->id()),
            ],
        ];
    }

    /**
     * Attach or detach the parent milestone. The parent must belong to
     * the same project — unlike issues, whose hierarchy crosses projects
     * — and may not be one of the milestone's own descendants (cycle).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Milestone $milestone, ?string $parentId, ?User $user = null): Milestone
    {
        // Out-of-scope milestones fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessMilestoneAccessible($user, $milestone);

            if (! $milestone->project->isContributor($user)) {
                throw new AuthorizationException('Only project members and the workspace owner can organize milestones.');
            }
        }

        if ($parentId !== null) {
            if ($parentId === $milestone->id) {
                abort(422, 'A milestone cannot be its own parent.');
            }

            $parent = (new Milestone)->withoutWorkspaceScope()->findOrFail($parentId);

            if ($parent->project_id !== $milestone->project_id) {
                abort(422, __('validation.milestone.parent_same_project'));
            }

            if (in_array($milestone->id, $parent->ancestorIds(), true)) {
                abort(422, 'This parent would create a cycle in the hierarchy.');
            }
        }

        $beforeParentId = $milestone->parent_id;

        $milestone->update(['parent_id' => $parentId]);

        if ($parentId !== null && $parentId !== $beforeParentId) {
            RecordActivity::run(AuditEventType::MilestoneParentSet, $milestone, $user, [
                'parent_id' => ['before' => $beforeParentId, 'after' => $parentId],
            ]);
        } elseif ($parentId === null && $beforeParentId !== null) {
            RecordActivity::run(AuditEventType::MilestoneParentRemoved, $milestone, $user, [
                'parent_id' => ['before' => $beforeParentId, 'after' => null],
            ]);
        }

        return $milestone->refresh();
    }

    public function asController(ActionRequest $request, Milestone $milestone, MilestoneRedirectResponder $responder): RedirectResponse
    {
        $milestone = $this->handle($milestone, $request->validated('parent_id'), $request->user());

        return $responder->updated($milestone);
    }
}
