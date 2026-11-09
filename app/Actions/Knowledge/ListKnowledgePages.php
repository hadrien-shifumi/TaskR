<?php

namespace App\Actions\Knowledge;

use App\Models\KnowledgePage;
use App\Models\User;
use App\Models\Workspace;
use App\Presenters\Knowledge\KnowledgePresenter;
use App\Support\WorkspaceContext;
use Lorisleiva\Actions\Concerns\AsObject;

class ListKnowledgePages
{
    use AsObject;

    /**
     * Sidebar scope for "nothing chosen yet": the knowledge index ships an
     * empty tree until the visitor picks a project — or the transverse
     * scope — instead of listing every page at once.
     */
    public const NO_SCOPE = 'none';

    /**
     * Flat list of the workspace's pages — tree structure stays
     * reconstructable through `parentId`. A scoped visitor (a guest's
     * roster, a scoped member's perimeter) only gets the pages attached
     * to their perimeter's projects; a scoped member — a full member —
     * keeps the transverse pages on top.
     *
     * The optional project filter narrows the list further:
     * `'transverse'` keeps only workspace-level pages (project_id null),
     * a project ULID keeps only that project's pages, and `NO_SCOPE`
     * (nothing chosen yet) ships an empty list. The filter is ANDed
     * with the scope — an out-of-scope project yields nothing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function handle(Workspace $workspace, ?User $user = null, ?string $projectFilter = null): array
    {
        if ($projectFilter === self::NO_SCOPE) {
            return [];
        }

        $context = app(WorkspaceContext::class);
        $scope = $user instanceof User
            ? $context->accessibleProjectIds($user)
            : null;
        $seesTransverse = ! $user instanceof User || $context->seesTransverseKnowledgePages($user);

        return KnowledgePage::query()
            ->when($scope !== null, fn ($query) => $query->where(function ($query) use ($scope, $seesTransverse) {
                $query->whereIn('project_id', $scope);

                if ($seesTransverse) {
                    $query->orWhereNull('project_id');
                }
            }))
            ->when($projectFilter === 'transverse', fn ($query) => $query->whereNull('project_id'))
            ->when(
                $projectFilter !== null && $projectFilter !== '' && $projectFilter !== 'transverse',
                fn ($query) => $query->where('project_id', $projectFilter)
            )
            ->orderBy('title')
            ->get()
            ->map(fn (KnowledgePage $page): array => KnowledgePresenter::listItem($page))
            ->all();
    }
}
