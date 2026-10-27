<?php

namespace App\Actions\Knowledge;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Jobs\VectorizeKnowledgePage;
use App\Models\KnowledgePage;
use App\Models\KnowledgePageVersion;
use App\Models\User;
use App\Responders\Knowledge\KnowledgeRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class RestoreKnowledgePageVersion
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * Restore a previous version of a knowledge page.
     *
     * IMPORTANT: Restoring a version does NOT delete any version. Instead:
     * 1. The page content is updated to match the selected version
     * 2. A NEW version is created capturing this restoration
     *
     * This preserves the full history: the restoration itself is a new
     * version, so you can always see "what changed when".
     */
    public function handle(
        KnowledgePage $page,
        KnowledgePageVersion $version,
        User $user,
    ): KnowledgePage {
        // Access checks.
        app(WorkspaceContext::class)->abortUnlessKnowledgePageAccessible(
            $user,
            $page,
        );

        // The version must belong to this page.
        abort_unless(
            $version->page_id === $page->id,
            404,
            'This version does not belong to this page.',
        );

        return DB::transaction(function () use ($page, $version, $user) {
            // Update the page content to match the version.
            $page->update([
                'title' => $version->title,
                'body' => $version->body,
                'last_editor_id' => $user->id,
            ]);

            // Create a NEW version capturing this restoration.
            // The new version has the same content as the restored version,
            // but a new timestamp and the current user as editor.
            KnowledgePageVersion::create([
                'page_id' => $page->id,
                'title' => $version->title,
                'body' => $version->body,
                'editor_id' => $user->id,
                'created_at' => now(),
            ]);

            // Re-vectorize for semantic search.
            VectorizeKnowledgePage::dispatch($page);

            return $page->refresh();
        });
    }

    public function asController(
        ActionRequest $request,
        KnowledgePage $page,
        KnowledgePageVersion $version,
        KnowledgeRedirectResponder $responder,
    ): RedirectResponse {
        $page = $this->handle($page, $version, $request->user());

        return $responder->versionRestored($page);
    }
}
