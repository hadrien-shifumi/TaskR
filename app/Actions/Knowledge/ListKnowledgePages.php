<?php

namespace App\Actions\Knowledge;

use App\Models\KnowledgePage;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Lorisleiva\Actions\Concerns\AsObject;

class ListKnowledgePages
{
    use AsObject;

    /**
     * Flat list of the workspace's pages — tree structure stays
     * reconstructable through `parentId`. A guest only gets the pages
     * attached to their perimeter's projects; transverse pages and other
     * projects' pages are absent.
     *
     * @return array<int, array<string, mixed>>
     */
    public function handle(Workspace $workspace, ?User $user = null): array
    {
        $scope = $user instanceof User
            ? app(WorkspaceContext::class)->accessibleProjectIds($user)
            : null;

        return KnowledgePage::query()
            ->when($scope !== null, fn ($query) => $query->whereIn('project_id', $scope))
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
