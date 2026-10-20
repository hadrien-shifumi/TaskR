<?php

namespace App\Presenters\Knowledge;

use App\Models\KnowledgePage;

class KnowledgePresenter
{
    /**
     * Row of the sidebar tree — the tree stays reconstructable
     * through parentId.
     *
     * @return array{id: string, title: string, parentId: string|null, projectId: string|null, updatedAt: string|null}
     */
    public static function listItem(KnowledgePage $page): array
    {
        return [
            'id' => $page->id,
            'title' => $page->title,
            'parentId' => $page->parent_id,
            'projectId' => $page->project_id,
            'updatedAt' => $page->updated_at?->toISOString(),
        ];
    }

    /**
     * Selected page of the knowledge editor.
     *
     * @return array{id: string, title: string, body: string, parentId: string|null, projectId: string|null, lastEditor: string|null, updatedAt: string|null}
     */
    public static function detail(KnowledgePage $page): array
    {
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
