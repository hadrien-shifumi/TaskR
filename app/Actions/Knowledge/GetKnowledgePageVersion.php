<?php

namespace App\Actions\Knowledge;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\KnowledgePage;
use App\Models\KnowledgePageVersion;
use App\Models\User;
use App\Presenters\Knowledge\KnowledgePresenter;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class GetKnowledgePageVersion
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * Full content of a single version, for preview in a modal.
     *
     * @return array{id: string, title: string, body: string, editor: string|null, createdAt: string|null}
     */
    public function handle(KnowledgePage $page, KnowledgePageVersion $version, ?User $user = null): array
    {
        // Access check on the parent page — out-of-scope pages fail
        // exactly like non-existent ones. Without an explicit user this
        // is a trusted internal object run: HTTP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessKnowledgePageAccessible($user, $page);
        }

        // The version must belong to this page.
        abort_unless($version->page_id === $page->id, 404, 'Version not found for this page.');

        $version->loadMissing('editor');

        return KnowledgePresenter::versionDetail($version);
    }

    public function asController(Request $request, KnowledgePage $page, KnowledgePageVersion $version): JsonResponse
    {
        $data = $this->handle($page, $version, $request->user());

        return response()->json($data);
    }
}
