<?php

namespace App\Actions\Issues;

use App\Models\Issue;
use App\Models\IssueTemplate;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Issues\IssuesPageResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class ShowIssuesPage
{
    use AsController;
    use AsObject;

    /**
     * Filterable list across every project of the workspace.
     *
     * With view=kanban, issues are grouped into one board per active project,
     * each board carrying one column per workspace status (in workspace order).
     *
     * @param  array<string, string|null>  $filters  view|project|status|category|priority|label|assignee|overdue|q
     * @return array<string, mixed>
     */
    public function handle(Workspace $workspace, array $filters = []): array
    {
        $view = $filters['view'] ?? 'list';

        $user = request()->user();
        $scope = $user instanceof User
            ? app(WorkspaceContext::class)->accessibleProjectIds($user)
            : null;

        $data = [
            ...ListIssues::run($workspace, $filters),
            'view' => $view,
            'filters' => array_filter($filters, fn ($value) => $value !== null && $value !== ''),
            'projects' => $workspace->projects()
                ->active()
                ->when($scope !== null, fn ($query) => $query->whereIn('id', $scope))
                ->orderBy('name')
                ->get(['id', 'name']),
            'statuses' => $workspace->statuses()->get(['id', 'name', 'category']),
        ];

        if ($view === 'kanban') {
            $data['projectBoards'] = $this->buildProjectBoards($workspace, $data['issues'], $filters, $scope);
            unset($data['issues'], $data['total']);
        }

        return $data;
    }

    public function asController(Request $request, IssuesPageResponder $responder): Response
    {
        $workspace = app(WorkspaceContext::class)->getOrFail();

        $data = $this->handle($workspace, [
            'view' => $request->query('view'),
            'project' => $request->query('project'),
            'status' => $request->query('status'),
            'category' => $request->query('category'),
            'priority' => $request->query('priority'),
            'label' => $request->query('label'),
            'assignee' => $request->query('assignee'),
            'overdue' => $request->query('overdue'),
            'q' => $request->query('q'),
        ]);

        $data['labels'] = $workspace->labels()->orderBy('name')->get(['id', 'name', 'color']);
        $data['members'] = $workspace->members()->orderBy('name')->get(['users.id', 'users.name']);
        $data['templates'] = $workspace->issueTemplates()
            ->with('labels')
            ->get()
            ->map(fn (IssueTemplate $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'description' => $template->description,
                'priority' => $template->priority?->value,
                'labels' => $template->labels->map(fn ($label) => ['id' => $label->id, 'name' => $label->name])->values()->all(),
            ])
            ->all();

        return $responder->toResponse($request, $data);
    }

    /**
     * Group presented issues into one board per project, each board carrying
     * one column per workspace status (in workspace order, even when empty).
     *
     * @param  array<int, array<string, mixed>>  $issues  Presented issues from ListIssues
     * @param  array<string, string|null>  $filters
     * @param  list<string>|null  $scope  Project ids of the user's perimeter, null when unrestricted
     * @return array<int, array<string, mixed>>
     */
    private function buildProjectBoards(Workspace $workspace, array $issues, array $filters = [], ?array $scope = null): array
    {
        $statuses = $workspace->statuses()->get();

        // Group issues by project id.
        $issuesByProject = collect($issues)->groupBy('project.id');

        // Only active projects get a board, ordered by name.
        // When a project filter is active, restrict to that project.
        $projectsQuery = $workspace->projects()->active()->orderBy('name');
        if (! empty($filters['project'])) {
            $projectsQuery->where('id', $filters['project']);
        }
        if ($scope !== null) {
            $projectsQuery->whereIn('id', $scope);
        }
        $projects = $projectsQuery->get();

        return $projects
            ->map(function ($project) use ($statuses, $issuesByProject) {
                $projectIssues = $issuesByProject->get($project->id, collect());

                // Group this project's issues by status id.
                $issuesByStatus = $projectIssues->groupBy('status.id');

                return [
                    'project' => ['id' => $project->id, 'name' => $project->name],
                    'columns' => $statuses
                        ->map(fn ($status) => [
                            'id' => $status->id,
                            'name' => $status->name,
                            'category' => $status->category->value,
                            'issuesCount' => $issuesByStatus->get($status->id, collect())->count(),
                            'issues' => $issuesByStatus
                                ->get($status->id, collect())
                                ->map(fn (array $issue) => $this->presentKanbanIssue($issue))
                                ->values()
                                ->all(),
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Present an issue for a kanban card (same shape as ListIssues, minus project).
     *
     * @param  array<string, mixed>  $issue
     * @return array<string, mixed>
     */
    private function presentKanbanIssue(array $issue): array
    {
        return [
            'id' => $issue['id'],
            'title' => $issue['title'],
            'priority' => $issue['priority'],
            'dueDate' => $issue['dueDate'],
            'overdue' => $issue['overdue'],
            'assignees' => $issue['assignees'],
            'labels' => $issue['labels'],
        ];
    }
}
