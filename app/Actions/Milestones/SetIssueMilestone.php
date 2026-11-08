<?php

namespace App\Actions\Milestones;

use App\Actions\Audit\RecordActivity;
use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\AuditEventType;
use App\Models\Issue;
use App\Models\Milestone;
use App\Models\User;
use App\Responders\Issues\IssueRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class SetIssueMilestone
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
            'milestone_id' => [
                'nullable', 'ulid',
                Rule::exists('milestones', 'id')->where('workspace_id', app(WorkspaceContext::class)->id()),
            ],
        ];
    }

    /**
     * Attach, move or clear the milestone of an issue. An issue carries
     * at most one milestone (the single column is the invariant), and it
     * must belong to the issue's project — a move replaces, never
     * stacks.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Issue $issue, ?string $milestoneId, ?User $user = null): Issue
    {
        // Out-of-scope issues fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $issue);

            if (! $issue->project->isContributor($user)) {
                throw new AuthorizationException('Only project members and the workspace owner can attach milestones.');
            }
        }

        if ($milestoneId !== null) {
            $milestone = (new Milestone)->withoutWorkspaceScope()->findOrFail($milestoneId);

            if ($milestone->project_id !== $issue->project_id) {
                throw ValidationException::withMessages([
                    'milestone_id' => [__('validation.milestone.issue_same_project')],
                ]);
            }
        }

        $beforeMilestoneId = $issue->milestone_id;

        $issue->update(['milestone_id' => $milestoneId]);

        if ($milestoneId !== null && $milestoneId !== $beforeMilestoneId) {
            RecordActivity::run(AuditEventType::IssueMilestoneSet, $issue, $user, [
                'milestone_id' => ['before' => $beforeMilestoneId, 'after' => $milestoneId],
            ]);
        } elseif ($milestoneId === null && $beforeMilestoneId !== null) {
            RecordActivity::run(AuditEventType::IssueMilestoneRemoved, $issue, $user, [
                'milestone_id' => ['before' => $beforeMilestoneId, 'after' => null],
            ]);
        }

        return $issue->refresh();
    }

    public function asController(ActionRequest $request, Issue $issue, IssueRedirectResponder $responder): RedirectResponse
    {
        $issue = $this->handle($issue, $request->validated('milestone_id'), $request->user());

        return $responder->updated($issue);
    }
}
