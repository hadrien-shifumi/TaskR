<?php

namespace App\Actions\Issues;

use App\Enums\Priority;
use App\Enums\StatusCategory;
use App\Models\Issue;
use App\Models\IssueTemplate;
use App\Models\Workspace;
use App\Responders\Issues\IssuesPageResponder;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
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
     * @param  array<string, string|null>  $filters  project|status|category|priority|label|assignee|overdue
     * @return array<string, mixed>
     */
    public function handle(Workspace $workspace, array $filters = []): array
    {
        $query = Issue::query()
            ->with(['project', 'status', 'labels', 'assignees'])
            ->orderByDesc('created_at');

        $this->applyFilters($query, $filters);

        $issues = $query->get()->map(fn (Issue $issue) => $this->present($issue))->values()->all();

        return [
            'issues' => $issues,
            'filters' => array_filter($filters, fn ($value) => $value !== null && $value !== ''),
            'projects' => $workspace->projects()->active()->orderBy('name')->get(['id', 'name']),
            'statuses' => $workspace->statuses()->get(['id', 'name', 'category']),
        ];
    }

    public function asController(Request $request, IssuesPageResponder $responder): Response
    {
        $workspace = app(WorkspaceContext::class)->getOrFail();

        $data = $this->handle($workspace, [
            'project' => $request->query('project'),
            'status' => $request->query('status'),
            'category' => $request->query('category'),
            'priority' => $request->query('priority'),
            'label' => $request->query('label'),
            'assignee' => $request->query('assignee'),
            'overdue' => $request->query('overdue'),
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
     * @param  array<string, string|null>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['project'])) {
            $query->where('project_id', $filters['project']);
        }

        if (! empty($filters['status'])) {
            $query->where('status_id', $filters['status']);
        }

        if (! empty($filters['category'])) {
            $query->whereHas('status', fn (Builder $status) => $status->where('category', StatusCategory::from($filters['category'])));
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', Priority::from($filters['priority']));
        }

        if (! empty($filters['label'])) {
            $query->whereHas('labels', fn (Builder $labels) => $labels->whereKey($filters['label']));
        }

        if (! empty($filters['assignee'])) {
            $query->whereHas('assignees', fn (Builder $assignees) => $assignees->whereKey($filters['assignee']));
        }

        if ($filters['overdue'] === '1' || $filters['overdue'] === 'true') {
            $query->overdue();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Issue $issue): array
    {
        return [
            'id' => $issue->id,
            'title' => $issue->title,
            'project' => ['id' => $issue->project->id, 'name' => $issue->project->name],
            'status' => ['id' => $issue->status->id, 'name' => $issue->status->name, 'category' => $issue->status->category->value],
            'priority' => $issue->priority->value,
            'dueDate' => $issue->due_date?->toISOString(),
            'overdue' => $issue->isOverdue(),
            'assignees' => $issue->assignees->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->values()->all(),
            'labels' => $issue->labels->map(fn ($label) => ['id' => $label->id, 'name' => $label->name, 'color' => $label->color])->values()->all(),
        ];
    }
}
