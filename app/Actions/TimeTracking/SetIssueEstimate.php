<?php

namespace App\Actions\TimeTracking;

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

    public function authorize(): bool
    {
        $user = request()->user();

        return $user instanceof User && app(WorkspaceContext::class)->canUseWorkspace($user);
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

        $issue->update(['estimated_hours' => $estimatedHours]);

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
