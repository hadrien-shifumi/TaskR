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
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class DeleteIssue
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * Children become root issues (parent_id set to null by the FK).
     */
    public function handle(Issue $issue, ?User $user = null): Issue
    {
        // Out-of-scope issues fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $issue);
        }

        // The journal is written right before the destructive write: the
        // subject FK requires the issue to still exist, and the DB then
        // nulls it on delete. The event stays readable through its
        // project and this loss inventory.
        RecordActivity::run(AuditEventType::IssueDeleted, $issue, $user, [
            'comments_count' => $issue->comments()->count(),
            'time_entries_count' => $issue->timeEntries()->count(),
            'title' => $issue->title,
            'description' => $issue->description,
            'status' => $issue->status?->name,
            'priority' => $issue->priority->value,
            'due_date' => $issue->due_date?->toDateString(),
            'estimated_hours' => $issue->estimated_hours !== null ? (float) $issue->estimated_hours : null,
            'labels' => $issue->labels()->pluck('labels.id')->sort()->values()->all(),
            'assignees' => $issue->assignees()->pluck('users.id')->sort()->values()->all(),
            'parent_id' => $issue->parent_id,
        ]);

        $issue->delete();

        return $issue;
    }

    public function asController(Request $request, Issue $issue, IssueRedirectResponder $responder): RedirectResponse
    {
        $this->handle($issue, $request->user());

        return $responder->deleted();
    }
}
