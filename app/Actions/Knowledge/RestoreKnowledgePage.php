<?php

namespace App\Actions\Knowledge;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Jobs\VectorizeKnowledgePage;
use App\Models\KnowledgePage;
use App\Models\Project;
use App\Models\User;
use App\Responders\Knowledge\KnowledgeRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class RestoreKnowledgePage
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * Restore a trashed page.
     *
     * The page reappears in the tree at its original parent if the parent
     * still exists and is not trashed; otherwise it becomes a root page.
     * If the original project was deleted, the page becomes transverse.
     *
     * Restoration triggers re-vectorization for semantic search.
     */
    public function handle(KnowledgePage $page, ?User $user = null): KnowledgePage
    {
        // Only trashed pages can be restored.
        abort_unless($page->trashed(), 404, 'This page is not in the trash.');

        // Access check: same rules as any knowledge page operation.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessKnowledgePageAccessible($user, $page);
        }

        // Check if the original parent still exists and is not trashed.
        // If parent was deleted or is trashed, restore to root (parent_id = null).
        $parentId = $page->parent_id;
        if ($parentId !== null) {
            $parent = KnowledgePage::withTrashed()->find($parentId);
            if ($parent === null || $parent->trashed()) {
                $parentId = null;
            }
        }

        // Check if the original project still exists.
        // If project was deleted, the page becomes transverse (project_id = null).
        $projectId = $page->project_id;
        if ($projectId !== null) {
            $projectExists = Project::where('id', $projectId)->exists();
            if (! $projectExists) {
                $projectId = null;
            }
        }

        // Restore the page: clear deleted_at, deleted_by_id, deleted_reason.
        // Update parent_id and project_id if they changed.
        $page->forceFill([
            'parent_id' => $parentId,
            'project_id' => $projectId,
            'deleted_by_id' => null,
            'deleted_reason' => null,
        ]);
        $page->restore();

        // Re-vectorize for semantic search.
        VectorizeKnowledgePage::dispatch($page);

        return $page->refresh();
    }

    public function asController(ActionRequest $request, KnowledgePage $page, KnowledgeRedirectResponder $responder): RedirectResponse
    {
        $page = $this->handle($page, $request->user());

        return $responder->restored($page);
    }
}
