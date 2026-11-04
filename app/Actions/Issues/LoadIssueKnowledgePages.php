<?php

namespace App\Actions\Issues;

use App\Models\Issue;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Shared loader of the knowledge pages linked to an issue: both the MCP
 * get_issue contract (GetIssue) and the web fiche (ShowIssuePage) must
 * expose the same payload — trashed pages included, guest scope applied.
 * The presenter never filters on its own: the actor is known here only.
 *
 * The compact card payloads (list row, kanban strip, project board) share
 * the same semantics through cardConstraint(): one eager load per query,
 * no N+1.
 */
class LoadIssueKnowledgePages
{
    use AsObject;

    /**
     * Eager-load constraint for the compact cards: trashed pages included —
     * the badge must match what the fiche lists — and a guest counts only
     * the pages of their perimeter, transverse pages excluded (the same
     * semantics as canAccessKnowledgePage). Without a user, or for a full
     * member, every linked page counts.
     *
     * @return callable(BelongsToMany<KnowledgePage, Issue>): void
     */
    public static function cardConstraint(?User $user): callable
    {
        $scope = $user instanceof User
            ? app(WorkspaceContext::class)->accessibleProjectIds($user)
            : null;

        return fn ($pages) => $pages
            ->withTrashed()
            ->when($scope !== null, fn ($query) => $query->whereIn('knowledge_pages.project_id', $scope));
    }

    /**
     * Load the linked knowledge pages, trashed ones included: the relation
     * has no withTrashed(), so a trashed page would silently disappear and
     * the trashed flag could never be true. A guest only keeps the pages of
     * their perimeter — out-of-scope pages are dropped, never an error.
     */
    public function handle(Issue $issue, ?User $user): void
    {
        $issue->load(['knowledgePages' => fn ($query) => $query->withTrashed()]);

        if ($user === null) {
            return;
        }

        $context = app(WorkspaceContext::class);

        if ($context->accessibleProjectIds($user) === null) {
            return;
        }

        $issue->setRelation('knowledgePages', $issue->knowledgePages
            ->filter(fn ($page) => $context->canAccessKnowledgePage($user, $page))
            ->values());
    }
}
