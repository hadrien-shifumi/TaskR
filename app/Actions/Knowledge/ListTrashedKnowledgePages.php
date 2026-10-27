<?php

namespace App\Actions\Knowledge;

use App\Models\KnowledgePage;
use App\Models\User;
use App\Models\Workspace;
use App\Presenters\Knowledge\KnowledgePresenter;
use App\Support\WorkspaceContext;
use Lorisleiva\Actions\Concerns\AsObject;

class ListTrashedKnowledgePages
{
    use AsObject;

    /**
     * List all trashed pages of the workspace, with metadata.
     *
     * For guests: only pages within their project scope.
     *
     * @return array<int, array{id: string, title: string, deletedAt: string|null, deletedBy: string|null, deletedReason: string|null, projectId: string|null, projectName: string|null}>
     */
    public function handle(Workspace $workspace, ?User $user = null): array
    {
        $query = KnowledgePage::onlyTrashed()
            ->with(['deletedBy', 'project'])
            ->orderBy('deleted_at', 'desc');

        // Guest scoping: only pages of projects in their roster.
        if ($user !== null) {
            $scope = app(WorkspaceContext::class)->accessibleProjectIds($user);
            if ($scope !== null) {
                $query->whereIn('project_id', $scope);
            }
        }

        return $query->get()
            ->map(fn (KnowledgePage $page): array => KnowledgePresenter::trashedItem($page))
            ->all();
    }
}
