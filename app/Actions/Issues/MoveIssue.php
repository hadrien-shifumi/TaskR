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

class MoveIssue
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
            'status_id' => [
                'required', 'ulid',
                Rule::exists('statuses', 'id')->where('workspace_id', app(WorkspaceContext::class)->id()),
            ],
        ];
    }

    /**
     * Any status of the workspace is reachable; no transition constraints.
     */
    public function handle(Issue $issue, string $statusId, ?User $user = null): Issue
    {
        // Out-of-scope issues fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $issue);
        }

        $beforeStatus = $issue->status?->name;

        $issue->update(['status_id' => $statusId]);

        $issue = $issue->refresh();

        // Moving to the same status is a no-op: no transition, no event.
        if ($issue->status?->name !== $beforeStatus) {
            RecordActivity::run(AuditEventType::IssueStatusChanged, $issue, $user, [
                'status' => ['before' => $beforeStatus, 'after' => $issue->status?->name],
            ]);
        }

        // Auto-completion option: once every child of the parent reaches a
        // terminal category, the parent follows — reviewed on every status
        // transition of a child, whatever the caller (web or MCP).
        PropagateParentCompletion::run($issue, $user);

        return $issue;
    }

    public function asController(ActionRequest $request, Issue $issue, IssueRedirectResponder $responder): RedirectResponse
    {
        $issue = $this->handle($issue, $request->validated('status_id'), $request->user());

        return $responder->moved($issue);
    }
}
