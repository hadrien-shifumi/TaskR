<?php

namespace App\Actions\Projects;

use App\Models\Issue;
use App\Models\IssueTemplate;
use App\Models\Project;
use App\Models\User;
use App\Responders\Projects\ProjectBoardResponder;
use App\Support\WorkspaceContext;
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

        return $user instanceof User && app(WorkspaceContext::class)->isMember($user);
    }

    /**
     * Present the kanban board of a project: one column per workspace status
     * (in workspace order, even when empty), each carrying its issues.
     * With $parentsOnly, subtasks are hidden for a macro view of the project.
     *
     * @return array{project: array<string, mixed>, health: array<string, mixed>, columns: array<int, array<string, mixed>>, parentsOnly: bool, labels: array<int, mixed>, members: array<int, mixed>, templates: array<int, mixed>}
     */
    public function handle(Project $project, bool $parentsOnly = false): array
    {
        $workspace = app(WorkspaceContext::class)->getOrFail();

        $issues = $project->issues()
            ->with(['assignees'])
            ->when($parentsOnly, fn ($query) => $query->whereNull('parent_id'))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('status_id');

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
        return $responder->toResponse($request, $this->handle($project, $request->boolean('parents')));
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
