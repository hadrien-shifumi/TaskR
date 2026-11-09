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
     * For a scoped visitor: only the pages of their perimeter's projects,
     * plus the transverse pages for a scoped member — a full member —
     * never for a guest.
     *
     * @return array<int, array{id: string, title: string, deletedAt: string|null, deletedBy: string|null, deletedReason: string|null, projectId: string|null, projectName: string|null}>
     */
    public function handle(Workspace $workspace, ?User $user = null): array
    {
        $query = KnowledgePage::onlyTrashed()
            ->with(['deletedBy', 'project'])
            ->orderBy('deleted_at', 'desc');

        if ($user !== null) {
            $context = app(WorkspaceContext::class);
            $scope = $context->accessibleProjectIds($user);
            if ($scope !== null) {
                $query->where(function ($query) use ($context, $scope, $user) {
                    $query->whereIn('project_id', $scope);

                    if ($context->seesTransverseKnowledgePages($user)) {
                        $query->orWhereNull('project_id');
                    }
                });
            }
        }

        return $query->get()
            ->map(fn (KnowledgePage $page): array => KnowledgePresenter::trashedItem($page))
            ->all();
    }
}
