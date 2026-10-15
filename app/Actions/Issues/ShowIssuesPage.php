<?php

namespace App\Actions\Issues;

use App\Models\IssueTemplate;
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
     * @param  array<string, string|null>  $filters  project|status|category|priority|label|assignee|overdue|q
     * @return array<string, mixed>
     */
    public function handle(Workspace $workspace, array $filters = []): array
    {
        return [
            ...ListIssues::run($workspace, $filters),
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
}
