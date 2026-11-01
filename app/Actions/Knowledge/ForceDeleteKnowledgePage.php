<?php

namespace App\Actions\Knowledge;

use App\Actions\Audit\RecordActivity;
use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\AuditEventType;
use App\Models\KnowledgePage;
use App\Models\User;
use App\Responders\Knowledge\KnowledgeRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class ForceDeleteKnowledgePage
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * Permanently delete a trashed page (hard delete).
     *
     * Unlike DeleteKnowledgePage (soft delete), this action:
     * - Only works on trashed pages (404 if not trashed)
     * - Does NOT check for children (force delete removes the page regardless)
     * - Actually removes the row from the database
     *
     * Note: if the page has children, they become orphaned (parent_id set to null
     * by the DB foreign key nullOnDelete). This is intentional: force delete is
     * the "nuclear option" from the trash UI.
     */
    public function handle(KnowledgePage $page, ?User $user = null): void
    {
        // Only trashed pages can be force deleted.
        abort_unless($page->trashed(), 404, 'This page is not in the trash.');

        // Access check.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessKnowledgePageAccessible($user, $page);
        }

        // Hard delete — actually removes the row.
        // The SoftDeletes trait provides forceDelete() for this.

        // The journal is written right before the destructive write: the
        // subject FK requires the page to still exist, and the DB then
        // nulls it on delete. The definitive inventory preserves what the
        // purge destroys (versions cascade away with the page).
        RecordActivity::run(AuditEventType::KnowledgePageDeleted, $page, $user, [
            'title' => $page->title,
            'body' => Str::limit($page->body, 2000),
            'versions_count' => $page->versions()->count(),
            'definitive' => true,
        ]);

        $page->forceDelete();
    }

    public function asController(ActionRequest $request, KnowledgePage $page, KnowledgeRedirectResponder $responder): RedirectResponse
    {
        $this->handle($page, $request->user());

        return $responder->forceDeleted();
    }
}
