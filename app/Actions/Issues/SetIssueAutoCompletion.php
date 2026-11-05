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
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class SetIssueAutoCompletion
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
            'enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * Turn the parent's auto-completion option on or off. A no-op toggle
     * (same value) writes nothing; every real change journals its own
     * activation or deactivation event.
     */
    public function handle(Issue $issue, bool $enabled, ?User $user = null): Issue
    {
        // Out-of-scope issues fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $issue);
        }

        $before = $issue->auto_complete_children;

        if ($before === $enabled) {
            return $issue;
        }

        $issue->update(['auto_complete_children' => $enabled]);

        RecordActivity::run(
            $enabled ? AuditEventType::IssueAutoCompletionEnabled : AuditEventType::IssueAutoCompletionDisabled,
            $issue,
            $user,
            ['auto_complete_children' => ['before' => $before, 'after' => $enabled]],
        );

        return $issue->refresh();
    }

    public function asController(ActionRequest $request, Issue $issue, IssueRedirectResponder $responder): RedirectResponse
    {
        $this->handle($issue, $request->boolean('enabled'), $request->user());

        return $responder->updated($issue);
    }
}
