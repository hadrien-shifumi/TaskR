<?php

namespace App\Actions\Issues;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\StatusCategory;
use App\Models\Issue;
use App\Models\User;
use App\Responders\Issues\IssueRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class ArchiveIssue
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * Toggle archive state on a closed issue. Archiving requires a done or
     * cancelled status; the cascade keeps the hierarchy coherent: a child
     * is never more archived than its parent.
     */
    public function handle(Issue $issue, ?User $user = null): Issue
    {
        // Out-of-scope issues fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $issue);

            $workspace = app(WorkspaceContext::class)->getOrFail();
            abort_unless($workspace->hasOwner($user), 403, 'Only the workspace owner may archive or unarchive issues.');
        }

        if ($issue->isArchived()) {
            return $this->unarchive($issue);
        }

        return $this->archive($issue);
    }

    public function asController(ActionRequest $request, Issue $issue, IssueRedirectResponder $responder): RedirectResponse
    {
        $issue = $this->handle($issue, $request->user());

        return $responder->archived($issue);
    }

    private function archive(Issue $issue): Issue
    {
        $status = $issue->status;
        abort_unless(
            $status !== null
                && in_array($status->category, [StatusCategory::Done, StatusCategory::Cancelled], true),
            409,
            'Only done or cancelled issues may be archived. Close the issue first.'
        );

        return DB::transaction(function () use ($issue): Issue {
            $issue->update(['archived_at' => now()]);

            $this->archiveDescendants($issue);

            return $issue->refresh();
        });
    }

    private function archiveDescendants(Issue $issue): void
    {
        $children = $issue->children()->unarchived()->get();

        foreach ($children as $child) {
            $child->update(['archived_at' => now()]);
            $this->archiveDescendants($child);
        }
    }

    private function unarchive(Issue $issue): Issue
    {
        return DB::transaction(function () use ($issue): Issue {
            $issue->update(['archived_at' => null]);

            $this->unarchiveDescendants($issue);

            return $issue->refresh();
        });
    }

    private function unarchiveDescendants(Issue $issue): void
    {
        $children = $issue->children()->archived()->get();

        foreach ($children as $child) {
            $child->update(['archived_at' => null]);
            $this->unarchiveDescendants($child);
        }
    }
}
