<?php

namespace App\Actions\Milestones;

use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use App\Presenters\Milestones\MilestonePresenter;
use App\Support\WorkspaceContext;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Every milestone the given user may see, ordered by title — the
 * listing behind the MCP list_milestones tool.
 *
 * With a project, lists only its milestones and refuses out-of-scope
 * projects exactly like non-existent ones. Without a project, lists
 * the milestones of every project the user may access: the workspace
 * owner sees everything, everyone else is bounded to their accessible
 * projects (an empty scope yields no rows).
 */
class ListAccessibleMilestones
{
    use AsObject;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function handle(?Project $project = null, ?User $user = null): array
    {
        $context = app(WorkspaceContext::class);

        if ($project !== null) {
            // Out-of-scope projects fail exactly like non-existent ones.
            // Without an explicit user this is a trusted internal object run.
            if ($user !== null) {
                $context->abortUnlessProjectAccessible($user, $project);
            }

            return $this->cards($project->milestones()->with('creator')->withIssueCounts()->orderBy('title')->get());
        }

        $query = Milestone::query();

        if ($user !== null) {
            $scope = $context->accessibleProjectIds($user);

            if ($scope === null) {
                // Workspace owner: no restriction.
            } elseif ($scope === []) {
                return [];
            } else {
                $query->whereIn('project_id', $scope);
            }
        }

        return $this->cards($query->with('creator')->withIssueCounts()->orderBy('title')->get());
    }

    /**
     * @param  iterable<int, Milestone>  $milestones
     * @return array<int, array<string, mixed>>
     */
    private function cards(iterable $milestones): array
    {
        return collect($milestones)
            ->map(fn (Milestone $milestone) => MilestonePresenter::card($milestone))
            ->values()
            ->all();
    }
}
