<?php

namespace App\Actions\TimeTracking;

use App\Actions\Audit\RecordActivity;
use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\AuditEventType;
use App\Models\Issue;
use App\Models\User;
use App\Responders\TimeTracking\TimeTrackingResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class SetIssueEstimate
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
            'estimated_hours' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function handle(Issue $issue, ?float $estimatedHours, ?User $user = null): Issue
    {
        // Out-of-scope issues fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $issue);
        }

        $before = $issue->estimated_hours !== null ? (float) $issue->estimated_hours : null;

        $issue->update(['estimated_hours' => $estimatedHours]);

        $after = $issue->estimated_hours !== null ? (float) $issue->estimated_hours : null;

        // Setting the same estimate journals nothing.
        if ($after !== $before) {
            RecordActivity::run(AuditEventType::IssueEstimateChanged, $issue, $user, [
                'estimated_hours' => ['before' => $before, 'after' => $after],
            ]);
        }

        return $issue->refresh();
    }

    public function asController(ActionRequest $request, Issue $issue, TimeTrackingResponder $responder): RedirectResponse
    {
        $estimatedHours = $request->validated('estimated_hours');
        $estimatedHours = $estimatedHours !== null ? (float) $estimatedHours : null;

        $issue = $this->handle($issue, $estimatedHours, $request->user());

        return $responder->estimateSet($issue);
    }
}
