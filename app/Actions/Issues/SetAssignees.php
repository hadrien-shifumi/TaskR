<?php

namespace App\Actions\Issues;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
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

    public function rules(): array
    {
        $issue = request()->route('issue');

        $assigneeRule = Rule::exists('memberships', 'user_id')
            ->where('workspace_id', app(WorkspaceContext::class)->id());

        // A guest can only assign the people of the issue's project roster.
        $user = request()->user();
        if ($issue instanceof Issue
            && $user !== null
            && app(WorkspaceContext::class)->accessibleProjectIds($user) !== null) {
            $assigneeRule = Rule::exists('project_members', 'user_id')
                ->where('project_id', $issue->project_id);
        }

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
        // Out-of-scope issues fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $issue);
        }

        $issue->assignees()->sync($userIds);

        // Keep the original assignment date for people already assigned;
        // only a brand-new assignment gets a fresh timestamp. sync() inserts
        // new pivot rows without the extra attribute, hence the second pass.
        $issue->assignees()
            ->wherePivotNull('assigned_at')
            ->updateExistingPivot($userIds, ['assigned_at' => now()->toDateTimeString()]);

        return $issue->refresh();
    }

    public function asController(ActionRequest $request, Issue $issue, IssueRedirectResponder $responder): RedirectResponse
    {
        $issue = $this->handle($issue, $request->validated('assignees'), $request->user());

        return $responder->updated($issue);
    }
}
