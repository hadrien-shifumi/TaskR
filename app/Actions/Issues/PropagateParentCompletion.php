<?php

namespace App\Actions\Issues;

use App\Actions\Audit\RecordActivity;
use App\Enums\AuditEventType;
use App\Enums\StatusCategory;
use App\Models\Issue;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Status;
use App\Models\User;
use Lorisleiva\Actions\Concerns\AsObject;

class PropagateParentCompletion
{
    use AsObject;

    /**
     * Auto-completion option, set on a parent issue: once every active
     * child reached a terminal category (done or cancelled), the parent
     * follows into the workspace's first done status — or into the
     * cancelled status when every child was cancelled. A reopened child
     * never reopens the parent. The cascade climbs level by level while
     * the option is enabled at each one.
     *
     * Trusted internal object run, called from MoveIssue::handle: the
     * actor journals the automatic completion, null for system moves.
     */
    public function handle(Issue $child, ?User $user = null): void
    {
        if ($child->parent_id === null) {
            return;
        }

        $parent = (new Issue)->withoutGlobalScope(WorkspaceScope::class)->find($child->parent_id);

        if ($parent === null
            || $parent->workspace_id !== $child->workspace_id
            || ! $parent->auto_complete_children
        ) {
            return;
        }

        $target = $this->targetStatusFor($parent);

        if ($target === null || $target->is($parent->status)) {
            return;
        }

        $before = $parent->status?->name;

        $parent->update(['status_id' => $target->id]);

        RecordActivity::run(AuditEventType::IssueAutoCompleted, $parent, $user, [
            'status' => ['before' => $before, 'after' => $target->name],
        ]);

        $this->handle($parent, $user);
    }

    /**
     * The status the parent must follow, or null when the propagation is
     * a no-op: an open child, no active child at all, or a workspace
     * without the matching terminal status.
     */
    private function targetStatusFor(Issue $parent): ?Status
    {
        $children = (new Issue)
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('parent_id', $parent->getKey())
            ->where('workspace_id', $parent->workspace_id)
            ->whereNull('archived_at')
            ->with('status')
            ->get();

        if ($children->isEmpty()) {
            return null;
        }

        $categories = $children->map(fn (Issue $child) => $child->status?->category);

        if ($categories->contains(fn (?StatusCategory $category) => ! in_array($category, [StatusCategory::Done, StatusCategory::Cancelled], true))) {
            return null;
        }

        $category = $categories->every(fn (?StatusCategory $c) => $c === StatusCategory::Cancelled)
            ? StatusCategory::Cancelled
            : StatusCategory::Done;

        return Status::withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $parent->workspace_id)
            ->where('category', $category)
            ->orderBy('position')
            ->first();
    }
}
