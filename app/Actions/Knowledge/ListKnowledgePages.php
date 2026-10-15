<?php

namespace App\Actions\Knowledge;

use App\Models\KnowledgePage;
use App\Models\Workspace;
use Lorisleiva\Actions\Concerns\AsObject;

class ListKnowledgePages
{
    use AsObject;

    /**
     * Flat list of the workspace's pages — tree structure stays
     * reconstructable through `parentId`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function handle(Workspace $workspace): array
    {
        return KnowledgePage::query()
            ->orderBy('title')
            ->get()
            ->map(fn (KnowledgePage $page): array => [
                'id' => $page->id,
                'title' => $page->title,
                'parentId' => $page->parent_id,
                'projectId' => $page->project_id,
                'updatedAt' => $page->updated_at?->toISOString(),
            ])
            ->all();
    }
}
