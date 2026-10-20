<?php

namespace App\Actions\Projects;

use App\Models\User;
use App\Models\Workspace;
use App\Presenters\Projects\ProjectPresenter;
use App\Support\WorkspaceContext;
use Lorisleiva\Actions\Concerns\AsObject;

class ListProjects
{
    use AsObject;

    /**
     * Every project of the workspace the given user may see, archived ones
     * flagged as such. Full members see everything; a guest only the projects
     * of their roster.
     *
     * @return array<int, array<string, mixed>>
     */
    public function handle(Workspace $workspace, ?User $user = null): array
    {
        $scope = $user instanceof User
            ? app(WorkspaceContext::class)->accessibleProjectIds($user)
            : null;

        return $workspace->projects()
            ->when($scope !== null, fn ($query) => $query->whereIn('id', $scope))
            ->orderBy('name')
            ->get()
            ->map(fn ($project) => ProjectPresenter::listItem($project))
            ->values()
            ->all();
    }
}
