<?php

namespace App\Actions\Knowledge;

use App\Models\KnowledgePage;
use App\Models\User;
use App\Presenters\Knowledge\KnowledgePresenter;
use App\Support\WorkspaceContext;
use Lorisleiva\Actions\Concerns\AsObject;

class ListKnowledgePageVersions
{
    use AsObject;

    /**
     * List all versions of a knowledge page, most recent first.
     *
     * @return array<int, array{id: string, title: string, editor: string|null, createdAt: string|null}>
     */
    public function handle(KnowledgePage $page, ?User $user = null): array
    {
        // Access check.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessKnowledgePageAccessible($user, $page);
        }

        return $page->versions()
            ->with('editor')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($version) => KnowledgePresenter::versionItem($version))
            ->all();
    }
}
