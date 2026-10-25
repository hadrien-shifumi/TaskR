<?php

namespace App\Actions\System;

use App\Actions\Projects\GetProjectHealth;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Presenters\Issues\IssuePresenter;
use App\Presenters\Projects\ProjectPresenter;
use App\Responders\System\HomeResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class ShowHomeAction
{
    use AsController;
    use AsObject;

    /**
     * Workspace dashboard: coherent counters with the other screens, the
     * health of every active project, and the issues assigned to the user.
     * A guest gets the same dashboard computed on their perimeter only.
     *
     * @return array<string, mixed>
     */
    public function handle(Workspace $workspace, User $user): array
    {
        // null = unrestricted scope (owner or member); a guest's scope is
        // its roster, so every derived counter and card follows it.
        $scope = app(WorkspaceContext::class)->accessibleProjectIds($user);

        $projects = $workspace->projects()
            ->when($scope !== null, fn ($query) => $query->whereIn('id', $scope))
            ->orderBy('name')
            ->get();
        $healthMap = app(GetProjectHealth::class)->handleForProjects($projects->all());

        $totalIssues = 0;
        $openIssues = 0;
        $overdueIssues = 0;
        $activeProjects = 0;
        $archivedProjects = 0;
        $projectCards = [];

        foreach ($projects as $project) {
            $health = $healthMap[$project->id];

            $totalIssues += $health->totalIssues;
            $openIssues += $health->issuesByCategory['backlog'] + $health->issuesByCategory['in_progress'];
            $overdueIssues += $health->overdueIssues;

            if ($project->archived_at !== null) {
                $archivedProjects++;

                continue;
            }

            $activeProjects++;
            $projectCards[] = ProjectPresenter::homeCard($project, $health);
        }

        $hoursThisWeekQuery = TimeEntry::query()
            ->where('workspace_id', $workspace->id)
            ->whereBetween('worked_at', [now()->startOfWeek(), now()->endOfWeek()]);

        if ($scope !== null) {
            // A guest's week counts only the time logged on their perimeter's issues.
            $hoursThisWeekQuery->whereHas('issue', fn ($issue) => $issue->whereIn('project_id', $scope));
        }

        return [
            'appName' => (string) config('app.name'),
            'stats' => [
                'totalIssues' => $totalIssues,
                'openIssues' => $openIssues,
                'overdueIssues' => $overdueIssues,
                'loggedHoursThisWeek' => (float) $hoursThisWeekQuery->sum('hours'),
                'activeProjects' => $activeProjects,
                'archivedProjects' => $archivedProjects,
            ],
            'projects' => $projectCards,
            'myIssues' => $workspace->issues()
                ->when($scope !== null, fn ($query) => $query->whereIn('project_id', $scope))
                ->unarchived()
                ->whereHas('assignees', fn ($assignees) => $assignees->whereKey($user->getKey()))
                ->with(['project', 'status'])
                ->orderByDesc('created_at')
                ->limit(6)
                ->get()
                ->map(fn ($issue) => IssuePresenter::homeCard($issue))
                ->values()
                ->all(),
        ];
    }

    public function asController(Request $request, HomeResponder $responder): Response
    {
        $workspace = app(WorkspaceContext::class)->getOrFail();
        $user = $request->user();

        \assert($user instanceof User);

        return $responder->toResponse($request, $this->handle($workspace, $user));
    }
}
