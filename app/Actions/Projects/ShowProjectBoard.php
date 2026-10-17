<?php

namespace App\Actions\Projects;

use App\Models\Issue;
use App\Models\IssueTemplate;
use App\Models\Project;
use App\Models\User;
use App\Responders\Projects\ProjectBoardResponder;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class ShowProjectBoard
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        $user = request()->user();

        return $user instanceof User && app(WorkspaceContext::class)->canUseWorkspace($user);
    }

    /**
     * Present the kanban board of a project: one column per workspace status
     * (in workspace order, even when empty), each carrying its issues.
     * With $parentsOnly, subtasks are hidden for a macro view of the project.
     * With $assigneeId, only issues assigned to that user are shown.
     *
     * @param  array{assignee?: string|null}  $filters
     * @return array{project: array<string, mixed>, health: array<string, mixed>, columns: array<int, array<string, mixed>>, parentsOnly: bool, filters: array<string, string>, projectPeople: array<int, array<string, mixed>>, currentUserId: int|null, labels: array<int, mixed>, members: array<int, mixed>, templates: array<int, mixed>}
     */
    public function handle(Project $project, bool $parentsOnly = false, array $filters = []): array
    {
        // Out-of-scope projects fail exactly like non-existent ones.
        app(WorkspaceContext::class)->abortUnlessProjectAccessible(request()->user(), $project);

        $workspace = app(WorkspaceContext::class)->getOrFail();
        $currentUser = request()->user();

        $assigneeId = ! empty($filters['assignee']) ? (int) $filters['assignee'] : null;

        $issues = $project->issues()
            ->with(['assignees'])
            ->when($parentsOnly, fn ($query) => $query->whereNull('parent_id'))
            ->when($assigneeId !== null, fn (Builder $query) => $query->whereHas('assignees', fn (Builder $q) => $q->where('users.id', $assigneeId)))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('status_id');

        // People on the project = roster ∪ users already assigned to issues of the project.
        $projectPeople = $this->computeProjectPeople($project);

        return [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'targetDate' => $project->target_date?->toDateString(),
                'createdAt' => $project->created_at?->toISOString(),
            ],
            // Health feeds the pagebar badge and the projection meta.
            'health' => GetProjectHealth::run($project)->toArray(),
            'parentsOnly' => $parentsOnly,
            'filters' => array_filter($filters, fn ($value) => $value !== null && $value !== ''),
            'projectPeople' => $projectPeople,
            'currentUserId' => $currentUser?->id,
            // Creation options for the board's full issue dialog (mirrors ShowIssuesPage).
            'labels' => $workspace->labels()->orderBy('name')->get(['id', 'name', 'color']),
            'members' => $workspace->members()->orderBy('name')->get(['users.id', 'users.name']),
            'templates' => $workspace->issueTemplates()
                ->with('labels')
                ->get()
                ->map(fn (IssueTemplate $template) => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'description' => $template->description,
                    'priority' => $template->priority?->value,
                    'labels' => $template->labels->map(fn ($label) => ['id' => $label->id, 'name' => $label->name])->values()->all(),
                ])
                ->all(),
            'columns' => $workspace->statuses()
                ->get()
                ->map(fn ($status) => [
                    'id' => $status->id,
                    'name' => $status->name,
                    'category' => $status->category->value,
                    'issuesCount' => $issues->get($status->id)?->count() ?? 0,
                    'issues' => ($issues->get($status->id) ?? collect())
                        ->map(fn (Issue $issue) => $this->presentIssue($issue))
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    public function asController(Request $request, Project $project, ProjectBoardResponder $responder): Response
    {
        return $responder->toResponse($request, $this->handle(
            $project,
            $request->boolean('parents'),
            [
                'assignee' => $request->query('assignee'),
            ],
        ));
    }

    /**
     * Compute the list of people on the project: roster members ∪ users
     * already assigned to at least one issue of the project.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function computeProjectPeople(Project $project): array
    {
        // Roster user IDs
        $rosterUserIds = $project->projectMembers()->pluck('user_id')->all();

        // Assignee user IDs (from issue_assignees)
        $assigneeUserIds = \DB::table('issue_assignees')
            ->join('issues', 'issues.id', '=', 'issue_assignees.issue_id')
            ->where('issues.project_id', $project->id)
            ->distinct()
            ->pluck('issue_assignees.user_id')
            ->all();

        $allUserIds = array_unique(array_merge($rosterUserIds, $assigneeUserIds));

        if ($allUserIds === []) {
            return [];
        }

        return \DB::table('users')
            ->whereIn('id', $allUserIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentIssue(Issue $issue): array
    {
        return [
            'id' => $issue->id,
            'title' => $issue->title,
            'priority' => $issue->priority->value,
            'dueDate' => $issue->due_date?->toDateString(),
            'overdue' => $issue->isOverdue(),
            'assignees' => $issue->assignees
                ->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])
                ->values()
                ->all(),
        ];
    }
}
