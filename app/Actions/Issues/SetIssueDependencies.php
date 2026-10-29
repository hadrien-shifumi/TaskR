<?php

namespace App\Actions\Issues;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\Issue;
use App\Models\User;
use App\Responders\Issues\IssueRedirectResponder;
use App\Support\IssueDependencyGraph;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class SetIssueDependencies
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
            'blocked_by' => ['present', 'array'],
            'blocked_by.*' => [
                'ulid', 'distinct',
                Rule::exists('issues', 'id')->where('workspace_id', app(WorkspaceContext::class)->id()),
            ],
        ];
    }

    /**
     * Replace the full blocker set. Cross-project links are allowed inside
     * the workspace, cycles are refused and a guest may only link within
     * their perimeter. The dependency alerts, it never forbids: removing a
     * link is possible from either side of it.
     *
     * @param  array<int, string>  $blockedByIds
     */
    public function handle(Issue $issue, array $blockedByIds, ?User $user = null): Issue
    {
        // Out-of-scope issues fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $issue);
        }

        if (in_array($issue->id, $blockedByIds, true)) {
            abort(422, 'An issue cannot block itself.');
        }

        if ($blockedByIds !== []) {
            $this->abortUnlessBlockersInGuestScope($blockedByIds, $user);

            if (IssueDependencyGraph::wouldCreateCycle($issue, $blockedByIds)) {
                abort(422, 'This blocker would create a cycle in the dependencies.');
            }
        }

        $issue->blockedBy()->sync($blockedByIds);

        return $issue->refresh();
    }

    public function asController(ActionRequest $request, Issue $issue, IssueRedirectResponder $responder): RedirectResponse
    {
        $issue = $this->handle($issue, $request->validated('blocked_by'), $request->user());

        return $responder->updated($issue);
    }

    /**
     * A guest may only link blockers inside their project perimeter; the
     * rule's workspace scope has already excluded other workspaces.
     *
     * @param  array<int, string>  $blockedByIds
     */
    private function abortUnlessBlockersInGuestScope(array $blockedByIds, ?User $user): void
    {
        if ($user === null || app(WorkspaceContext::class)->accessibleProjectIds($user) === null) {
            return;
        }

        $blockers = (new Issue)->withoutWorkspaceScope()->findOrFail($blockedByIds);

        foreach ($blockers as $blocker) {
            if (! app(WorkspaceContext::class)->canAccessIssue($user, $blocker)) {
                throw ValidationException::withMessages([
                    'blocked_by' => [__('validation.issue.blocker_out_of_scope')],
                ]);
            }
        }
    }
}
