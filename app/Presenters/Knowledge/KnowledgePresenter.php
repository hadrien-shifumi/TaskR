<?php

namespace App\Presenters\Knowledge;

use App\Models\KnowledgePage;
use App\Models\KnowledgePageVersion;

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
     * @return array{id: string, title: string, body: string, parentId: string|null, projectId: string|null, parentChain: array<int, array{id: string, title: string}>, lastEditor: string|null, updatedAt: string|null}
     */
    public static function detail(KnowledgePage $page): array
    {
        return [
            'id' => $page->id,
            'title' => $page->title,
            'body' => $page->body,
            'parentId' => $page->parent_id,
            'projectId' => $page->project_id,
            'parentChain' => $page->ancestorChain(),
            'lastEditor' => $page->lastEditor?->name,
            'updatedAt' => $page->updated_at?->toISOString(),
        ];
    }

    /**
     * Trashed page row for the trash list.
     *
     * @return array{id: string, title: string, deletedAt: string|null, deletedBy: string|null, deletedReason: string|null, projectId: string|null, projectName: string|null}
     */
    public static function trashedItem(KnowledgePage $page): array
    {
        return [
            'id' => $page->id,
            'title' => $page->title,
            'deletedAt' => $page->deleted_at?->toISOString(),
            'deletedBy' => $page->deletedBy?->name,
            'deletedReason' => $page->deleted_reason,
            'projectId' => $page->project_id,
            'projectName' => $page->project?->name,
        ];
    }

    /**
     * Version item for the history list.
     *
     * @return array{id: string, title: string, editor: string|null, createdAt: string|null}
     */
    public static function versionItem(KnowledgePageVersion $version): array
    {
        return [
            'id' => $version->id,
            'title' => $version->title,
            'editor' => $version->editor?->name,
            'createdAt' => $version->created_at?->toISOString(),
        ];
    }

    /**
     * Full version detail for the preview modal.
     *
     * @return array{id: string, title: string, body: string, editor: string|null, createdAt: string|null}
     */
    public static function versionDetail(KnowledgePageVersion $version): array
    {
        return [
            'id' => $version->id,
            'title' => $version->title,
            'body' => $version->body,
            'editor' => $version->editor?->name,
            'createdAt' => $version->created_at?->toISOString(),
        ];
    }
}
