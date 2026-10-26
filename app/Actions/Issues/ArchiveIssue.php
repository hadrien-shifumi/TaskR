<?php

namespace App\Actions\Issues;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\StatusCategory;
use App\Models\Issue;
use App\Models\User;
use App\Responders\Issues\IssueRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class ArchiveIssue
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserIsOwner();
    }

    /**
     * Toggle the archive state of an issue, cascading down the whole
     * subtree: archiving marks the issue and every descendant, restoring
     * clears them all. A child is never more archived than its parent.
     */
    public function handle(Issue $issue, ?User $user = null): Issue
    {
        // Out-of-scope issues fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $issue);
        }

        if ($issue->isArchived()) {
            return $this->restore($issue);
        }

        $this->assertArchivable($issue);

        $archivedAt = now();
        $this->selfAndDescendants($issue)->each(fn (Issue $target) => $target->update(['archived_at' => $archivedAt]));

        return $issue->refresh();
    }

    public function asController(Request $request, Issue $issue, IssueRedirectResponder $responder): RedirectResponse
    {
        $issue = $this->handle($issue, $request->user());

        return $issue->isArchived()
            ? $responder->archived($issue)
            : $responder->unarchived($issue);
    }

    /**
     * Archiving is reserved to closed issues (done or cancelled), closed
     * subtrees (no open descendant), and already-archived ancestors — a
     * child may never be more archived than its parent.
     */
    private function assertArchivable(Issue $issue): void
    {
        if (! $this->isClosed($issue)) {
            abort(409, 'Only done or cancelled issues can be archived.');
        }

        $openDescendant = $this->selfAndDescendants($issue)
            ->first(fn (Issue $target) => ! $this->isClosed($target));

        if ($openDescendant !== null) {
            abort(409, "Sub-issue \"{$openDescendant->title}\" is still open. Close it before archiving.");
        }

        $ancestor = $issue->parent;
        while ($ancestor !== null) {
            if (! $ancestor->isArchived()) {
                abort(409, "Parent issue \"{$ancestor->title}\" is not archived. Archive the parent instead.");
            }

            $ancestor = $ancestor->parent;
        }
    }

    /**
     * Restoring clears the whole subtree: a parent never comes back with
     * its children still archived.
     */
    private function restore(Issue $issue): Issue
    {
        $this->selfAndDescendants($issue)->each(fn (Issue $target) => $target->update(['archived_at' => null]));

        return $issue->refresh();
    }

    /**
     * The issue plus every descendant, depth-first.
     *
     * @return Collection<int, Issue>
     */
    private function selfAndDescendants(Issue $issue)
    {
        $all = collect([$issue]);

        foreach ($issue->children as $child) {
            $all = $all->merge($this->selfAndDescendants($child));
        }

        return $all;
    }

    private function isClosed(Issue $issue): bool
    {
        return $issue->status?->category === StatusCategory::Done
            || $issue->status?->category === StatusCategory::Cancelled;
    }

    protected function currentUserIsOwner(): bool
    {
        $user = request()->user();
        $workspace = $this->currentWorkspace();

        return $user instanceof User && $workspace !== null && $workspace->hasOwner($user);
    }
}
