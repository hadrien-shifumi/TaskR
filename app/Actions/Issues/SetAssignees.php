<?php

namespace App\Actions\Issues;

use App\Actions\Audit\RecordActivity;
use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\AuditEventType;
use App\Models\Issue;
use App\Models\User;
use App\Responders\Issues\IssueRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class SetAssignees
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * Assignable = the issue's project roster, for every actor and
     * every channel (web, agents): the workspace owner included. An
     * out-of-team assignee could not even see the ticket, and
     * assignable means a team line. The issue can be injected by
     * object callers (MCP tools) — the HTTP fallback reads the route
     * binding.
     */
    public function rules(?Issue $issue = null): array
    {
        $issue ??= request()->route('issue');

        $assigneeRule = Rule::exists('project_members', 'user_id')
            ->where('project_id', $issue?->project_id);

        return [
            'assignees' => ['present', 'array'],
            'assignees.*' => [$assigneeRule],
        ];
    }

    /**
     * Replace the full assignee set; members only, any count.
     *
     * @param  array<int, string>  $userIds
     */
    public function handle(Issue $issue, array $userIds, ?User $user = null): Issue
    {
        // Out-of-scope issues and project-guest actors fail exactly like
        // non-existent ones — a project guest is nameable as a target
        // but never assigns anyone. Without an explicit user this is a
        // trusted internal object run: HTTP and MCP callers always pass
        // the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueContributor($user, $issue);
        }

        $before = $issue->assignees()->pluck('users.id')->sort()->values()->all();

        $issue->assignees()->sync($userIds);

        // Keep the original assignment date for people already assigned;
        // only a brand-new assignment gets a fresh timestamp. sync() inserts
        // new pivot rows without the extra attribute, hence the second pass.
        $issue->assignees()
            ->wherePivotNull('assigned_at')
            ->updateExistingPivot($userIds, ['assigned_at' => now()->toDateTimeString()]);

        $this->recordAssignmentDiff($issue, $before, $user);

        return $issue->refresh();
    }

    /**
     * Journal the sync as two diff events: one for the added assignees,
     * one for the removed ones. A sync that changes nothing journals
     * nothing.
     *
     * @param  list<string>  $before
     */
    private function recordAssignmentDiff(Issue $issue, array $before, ?User $user): void
    {
        $after = $issue->assignees()->pluck('users.id')->sort()->values()->all();

        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        if ($added !== []) {
            RecordActivity::run(AuditEventType::IssueAssigned, $issue, $user, [
                'assignees_added' => $added,
            ]);
        }

        if ($removed !== []) {
            RecordActivity::run(AuditEventType::IssueUnassigned, $issue, $user, [
                'assignees_removed' => $removed,
            ]);
        }
    }

    public function asController(ActionRequest $request, Issue $issue, IssueRedirectResponder $responder): RedirectResponse
    {
        $issue = $this->handle($issue, $request->validated('assignees'), $request->user());

        return $responder->updated($issue);
    }
}
