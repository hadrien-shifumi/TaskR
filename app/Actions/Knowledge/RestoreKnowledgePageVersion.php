<?php

namespace App\Actions\Knowledge;

use App\Actions\Audit\RecordActivity;
use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\AuditEventType;
use App\Jobs\VectorizeKnowledgePage;
use App\Models\KnowledgePage;
use App\Models\KnowledgePageVersion;
use App\Models\User;
use App\Responders\Knowledge\KnowledgeRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            $before = [
                'title' => $page->title,
                'body' => $page->body,
            ];

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

            // The restoration is journal-worthy even when the content ends
            // up identical: it is an explicit history action. Bodies are
            // truncated like every other page event — the version table
            // keeps the full text.
            RecordActivity::run(AuditEventType::KnowledgePageUpdated, $page, $user, [
                'restored_version_id' => $version->id,
                'title' => ['before' => $before['title'], 'after' => $page->title],
                'body' => [
                    'before' => Str::limit($before['body'], 2000),
                    'after' => Str::limit($page->body, 2000),
                ],
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
