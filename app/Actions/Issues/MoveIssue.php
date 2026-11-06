<?php

namespace App\Actions\Issues;

use App\Actions\Audit\RecordActivity;
use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\AuditEventType;
use App\Enums\StatusCategory;
use App\Models\Issue;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Status;
use App\Models\User;
use App\Responders\Issues\IssueRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
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
            'cascade_children' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Move an issue to another status of its workspace. With
     * $cascadeChildren, the whole active subtree follows into the target
     * status — descendants first, the moved issue last, each moved issue
     * journaling its own status change. Archived descendants never follow:
     * they left the scope, they are not resurrected.
     *
     * Moving to Done is guarded: every active descendant must already be
     * done or cancelled — unless the cascade moves them along in the same
     * call, which satisfies the guard by construction. A move to the
     * current status stays a full no-op: no cascade, no guard, no event.
     *
     * A guest only ever reaches what their perimeter covers: a descendant
     * in a project outside it is invisible — never cascaded, never
     * blocking.
     */
    public function handle(Issue $issue, string $statusId, ?User $user = null, bool $cascadeChildren = false): Issue
    {
        // Out-of-scope issues fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $issue);
        }

        $target = Status::withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $issue->workspace_id)
            ->findOrFail($statusId);

        $beforeStatus = $issue->status?->name;
        $isSameStatus = $issue->status_id === $target->id;

        // The pre-move snapshot drives both the cascade and the guard:
        // every active descendant the actor may see, status loaded.
        $descendants = $this->activeDescendants($issue, $user);

        if (! $isSameStatus && $cascadeChildren) {
            $this->moveDescendants(
                $descendants->filter(fn (Issue $descendant) => $descendant->status_id !== $target->id),
                $target,
                $user,
            );
        }

        // The Done guard guards a transition, not a rewrite of the same
        // status. With the cascade the descendants just moved into the
        // target, so the guard is satisfied by construction.
        if (! $isSameStatus && ! $cascadeChildren && $target->category === StatusCategory::Done) {
            $this->assertSubtreeClosed($issue, $descendants);
        }

        $issue->update(['status_id' => $target->id]);

        $issue = $issue->refresh();

        // Moving to the same status is a no-op: no transition, no event.
        if ($issue->status?->name !== $beforeStatus) {
            RecordActivity::run(AuditEventType::IssueStatusChanged, $issue, $user, [
                'status' => ['before' => $beforeStatus, 'after' => $issue->status?->name],
            ]);
        }

        // Auto-completion option: once every child of the parent reaches a
        // terminal category, the parent follows — reviewed on every status
        // transition of a child, whatever the caller (web or MCP). The
        // cascaded descendants never trigger it: their whole chain moved
        // with them, re-running it would only drag a parent from the
        // status the user chose to the workspace's first done status.
        PropagateParentCompletion::run($issue, $user);

        return $issue;
    }

    public function asController(ActionRequest $request, Issue $issue, IssueRedirectResponder $responder): RedirectResponse
    {
        $issue = $this->handle(
            $issue,
            $request->validated('status_id'),
            $request->user(),
            (bool) $request->validated('cascade_children', false),
        );

        return $responder->moved($issue);
    }

    /**
     * Every active descendant of the issue, all generations, status
     * loaded — depth-first. A guest keeps only the subtrees of their
     * perimeter: an out-of-scope descendant is dropped with everything
     * below it.
     *
     * @return Collection<int, Issue>
     */
    private function activeDescendants(Issue $issue, ?User $user): Collection
    {
        return app(LoadActiveDescendants::class)->run(collect([$issue]), $user);
    }

    /**
     * Move the given descendants into the target status, each with its own
     * status_changed journal entry — the audit trail stays per ticket.
     */
    private function moveDescendants(Collection $descendants, Status $target, ?User $user): void
    {
        foreach ($descendants as $descendant) {
            $before = $descendant->status?->name;

            $descendant->update(['status_id' => $target->id]);

            RecordActivity::run(AuditEventType::IssueStatusChanged, $descendant->refresh(), $user, [
                'status' => ['before' => $before, 'after' => $target->name],
            ]);
        }
    }

    /**
     * A parent only goes to Done with every active descendant closed
     * (done or cancelled) — archived descendants left the scope.
     */
    private function assertSubtreeClosed(Issue $issue, Collection $descendants): void
    {
        $open = $descendants->first(
            fn (Issue $descendant) => ! in_array($descendant->status?->category, [StatusCategory::Done, StatusCategory::Cancelled], true),
        );

        if ($open !== null) {
            abort(409, "Sub-issue \"{$open->title}\" is still open. Finish it before marking the parent done.");
        }
    }
}
