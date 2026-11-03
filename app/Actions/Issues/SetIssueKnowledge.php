<?php

namespace App\Actions\Issues;

use App\Actions\Audit\RecordActivity;
use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\AuditEventType;
use App\Models\Issue;
use App\Models\KnowledgePage;
use App\Models\User;
use App\Responders\Issues\IssueRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class SetIssueKnowledge
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
            'page_ids' => ['present', 'array'],
            // No distinct rule: duplicates in the payload are collapsed by
            // sync(). whereNull excludes trashed pages, which Rule::exists
            // alone would still accept (it ignores SoftDeletes).
            'page_ids.*' => [
                'ulid',
                Rule::exists('knowledge_pages', 'id')
                    ->where('workspace_id', app(WorkspaceContext::class)->id())
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * Replace the full set of knowledge pages linked to the issue. Pages
     * live in the same workspace and outside the trash; a guest may only
     * link pages inside their perimeter, and transverse pages (no project)
     * stay reserved to full members.
     *
     * @param  array<int, string>  $pageIds
     */
    public function handle(Issue $issue, array $pageIds, ?User $user = null): Issue
    {
        // Out-of-scope issues fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $issue);
        }

        if ($pageIds !== []) {
            $this->abortUnlessPagesInGuestScope($pageIds, $user);
        }

        $before = $issue->knowledgePages()->withTrashed()->pluck('knowledge_pages.id')->sort()->values()->all();

        $issue->knowledgePages()->sync($pageIds);

        $after = $issue->knowledgePages()->withTrashed()->pluck('knowledge_pages.id')->sort()->values()->all();

        $this->recordLinkDiff($issue, $before, $after, $user);

        return $issue->refresh();
    }

    public function asController(ActionRequest $request, Issue $issue, IssueRedirectResponder $responder): RedirectResponse
    {
        $issue = $this->handle($issue, $request->validated('page_ids'), $request->user());

        return $responder->updated($issue);
    }

    /**
     * A guest may only link knowledge pages inside their project perimeter;
     * the rule's workspace scope has already excluded other workspaces and
     * trashed pages. Transverse pages (no project) stay full-member-only.
     *
     * @param  array<int, string>  $pageIds
     */
    private function abortUnlessPagesInGuestScope(array $pageIds, ?User $user): void
    {
        if ($user === null || app(WorkspaceContext::class)->accessibleProjectIds($user) === null) {
            return;
        }

        $pages = (new KnowledgePage)->withoutWorkspaceScope()->findOrFail($pageIds);

        foreach ($pages as $page) {
            if (! app(WorkspaceContext::class)->canAccessKnowledgePage($user, $page)) {
                throw ValidationException::withMessages([
                    'page_ids' => [__('validation.issue.knowledge_page_out_of_scope')],
                ]);
            }
        }
    }

    /**
     * Journal the sync as two diff events: one for the linked pages, one
     * for the unlinked ones. A sync that changes nothing journals nothing.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    private function recordLinkDiff(Issue $issue, array $before, array $after, ?User $user): void
    {
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        if ($added !== []) {
            RecordActivity::run(AuditEventType::IssueKnowledgeLinked, $issue, $user, [
                'knowledge_pages_added' => $added,
            ]);
        }

        if ($removed !== []) {
            RecordActivity::run(AuditEventType::IssueKnowledgeUnlinked, $issue, $user, [
                'knowledge_pages_removed' => $removed,
            ]);
        }
    }
}
