<?php

namespace App\Actions\Knowledge;

use App\Models\KnowledgePage;
use App\Models\User;
use App\Support\WorkspaceContext;
use Lorisleiva\Actions\Concerns\AsObject;

class GetKnowledgePage
{
    use AsObject;

    /**
     * @return array<string, mixed>
     */
    public function handle(KnowledgePage $page, ?User $user = null): array
    {
        // Out-of-scope pages fail exactly like non-existent ones.
        // Without a resolvable user this is a trusted internal object run.
        $user ??= request()->user();
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessKnowledgePageAccessible($user, $page);
        }

        $page->loadMissing('lastEditor');

        return [
            'id' => $page->id,
            'title' => $page->title,
            'body' => $page->body,
            'parentId' => $page->parent_id,
            'projectId' => $page->project_id,
            'lastEditor' => $page->lastEditor?->name,
            'updatedAt' => $page->updated_at?->toISOString(),
        ];
    }
}
