<?php

namespace App\Actions\System;

use App\Actions\Projects\GetProjectHealth;
use App\Models\Issue;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
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
     *
     * @return array<string, mixed>
     */
    public function handle(Workspace $workspace, User $user): array
    {
        $projects = $workspace->projects()->orderBy('name')->get();
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
            $projectCards[] = [
                'id' => $project->id,
                'name' => $project->name,
                'health' => $health->toArray(),
            ];
        }

        return [
            'appName' => (string) config('app.name'),
            'stats' => [
                'totalIssues' => $totalIssues,
                'openIssues' => $openIssues,
                'overdueIssues' => $overdueIssues,
                'loggedHoursThisWeek' => (float) TimeEntry::query()
                    ->where('workspace_id', $workspace->id)
                    ->whereBetween('worked_at', [now()->startOfWeek(), now()->endOfWeek()])
                    ->sum('hours'),
                'activeProjects' => $activeProjects,
                'archivedProjects' => $archivedProjects,
            ],
            'projects' => $projectCards,
            'myIssues' => $workspace->issues()
                ->whereHas('assignees', fn ($assignees) => $assignees->whereKey($user->getKey()))
                ->with(['project', 'status'])
                ->orderByDesc('created_at')
                ->limit(6)
                ->get()
                ->map(fn (Issue $issue) => [
                    'id' => $issue->id,
                    'title' => $issue->title,
                    'projectName' => $issue->project->name,
                    'statusName' => $issue->status->name,
                    'statusCategory' => $issue->status->category->value,
                    'priority' => $issue->priority->value,
                    'dueDate' => $issue->due_date?->toDateString(),
                    'overdue' => $issue->isOverdue(),
                ])
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
