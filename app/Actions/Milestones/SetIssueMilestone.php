<?php

namespace App\Actions\Milestones;

use App\Actions\Audit\RecordActivity;
use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Actions\Issues\LoadActiveDescendants;
use App\Enums\AuditEventType;
use App\Models\Issue;
use App\Models\Milestone;
use App\Models\User;
use App\Responders\Issues\IssueRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
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
            'cascade_children' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Attach, move or clear the milestone of an issue. An issue carries
     * at most one milestone (the single column is the invariant), and it
     * must belong to the issue's project — a move replaces, never
     * stacks.
     *
     * With $cascadeChildren and a target milestone, the whole active
     * subtree follows into it — descendants first, the issue last, each
     * moved issue journaling its own milestone_set event. Archived
     * descendants never follow: they left the scope. A descendant already
     * in the target is left untouched (no duplicate journal), and one in
     * another project stays there — the milestone cannot reach it.
     * Detaching never cascades: clearing a milestone stays individual.
     *
     * @throws ValidationException
     */
    public function handle(Issue $issue, ?string $milestoneId, ?User $user = null, bool $cascadeChildren = false): Issue
    {
        // Out-of-scope issues and project-guest actors fail exactly like
        // non-existent ones (404, never a 403): attaching a milestone
        // writes the issue. Without an explicit user this is a trusted
        // internal object run: HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueContributor($user, $issue);
        }

        $milestone = null;

        if ($milestoneId !== null) {
            $milestone = (new Milestone)->withoutWorkspaceScope()->findOrFail($milestoneId);

            if ($milestone->project_id !== $issue->project_id) {
                throw ValidationException::withMessages([
                    'milestone_id' => [__('validation.milestone.issue_same_project')],
                ]);
            }
        }

        $beforeMilestoneId = $issue->milestone_id;

        DB::transaction(function () use ($issue, $milestone, $milestoneId, $beforeMilestoneId, $cascadeChildren, $user): void {
            if ($milestone !== null && $cascadeChildren) {
                $this->cascadeDescendants($issue, $milestone, $user);
            }

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
        });

        return $issue->refresh();
    }

    public function asController(ActionRequest $request, Issue $issue, IssueRedirectResponder $responder): RedirectResponse
    {
        $issue = $this->handle(
            $issue,
            $request->validated('milestone_id'),
            $request->user(),
            (bool) $request->validated('cascade_children', false),
        );

        return $responder->updated($issue);
    }

    /**
     * Bring the active descendants into the target milestone, each with
     * its own milestone_set journal entry — the audit trail stays per
     * ticket. A guest only ever reaches what their perimeter covers: a
     * descendant in a project outside it is invisible — never cascaded.
     */
    private function cascadeDescendants(Issue $issue, Milestone $milestone, ?User $user): void
    {
        $descendants = app(LoadActiveDescendants::class)->run(collect([$issue]), $user);

        foreach ($descendants as $descendant) {
            // Already in the target: nothing to move, nothing to journal.
            if ($descendant->milestone_id === $milestone->id) {
                continue;
            }

            // A milestone belongs to one project: a descendant of another
            // project is out of its reach and is skipped silently.
            if ($descendant->project_id !== $milestone->project_id) {
                continue;
            }

            $before = $descendant->milestone_id;

            $descendant->update(['milestone_id' => $milestone->id]);

            RecordActivity::run(AuditEventType::IssueMilestoneSet, $descendant->refresh(), $user, [
                'milestone_id' => ['before' => $before, 'after' => $milestone->id],
            ]);
        }
    }
}
