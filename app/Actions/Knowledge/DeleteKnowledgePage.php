<?php

namespace App\Actions\Knowledge;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\KnowledgePage;
use App\Models\User;
use App\Responders\Knowledge\KnowledgeRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class DeleteKnowledgePage
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * Move a page to trash (soft delete) instead of destroying it.
     *
     * The page keeps its place in the hierarchy (parent_id unchanged) but
     * disappears from the tree and semantic search (SoftDeletes global scope).
     *
     * @param  string|null  $reason  Why the page is trashed (default: 'manual')
     */
    public function handle(KnowledgePage $page, ?User $user = null, ?string $reason = null): void
    {
        // Out-of-scope pages fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessKnowledgePageAccessible($user, $page);
        }

        // A page with children cannot be trashed — the subtree must be
        // emptied bottom-up first (same rule as before, now for trash).
        if ($page->children()->exists()) {
            abort(409, 'This page still has child pages. Move or delete them first.');
        }

        // Soft delete with metadata: who trashed it and why.
        $page->forceFill([
            'deleted_by_id' => $user?->id,
            'deleted_reason' => $reason ?? 'manual',
        ])->save();

        // SoftDeletes trait: this sets deleted_at and saves.
        $page->delete();
    }

    public function asController(ActionRequest $request, KnowledgePage $page, KnowledgeRedirectResponder $responder): RedirectResponse
    {
        $this->handle($page, $request->user());

        return $responder->deleted();
    }
}
