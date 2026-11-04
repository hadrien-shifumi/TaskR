<?php

namespace App\Actions\Projects;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Actions\Issues\LoadIssueKnowledgePages;
use App\Actions\Issues\ShowIssuePage;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Presenters\Issues\IssuePresenter;
use App\Presenters\Labels\LabelPresenter;
use App\Presenters\Projects\ProjectMemberPresenter;
use App\Presenters\Projects\ProjectPresenter;
use App\Presenters\Templates\TemplatePresenter;
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
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
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
    public function handle(Project $project, bool $parentsOnly = false, array $filters = [], ?User $user = null): array
    {
        // Out-of-scope projects fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        app(WorkspaceContext::class)->abortUnlessProjectAccessible($user, $project);

        $workspace = app(WorkspaceContext::class)->getOrFail();

        $assigneeId = ! empty($filters['assignee']) ? (int) $filters['assignee'] : null;

        $issues = $project->issues()
            ->unarchived()
            ->with(['assignees', 'blockedBy.status'])
            ->with(['knowledgePages' => LoadIssueKnowledgePages::cardConstraint($user)])
            ->when($parentsOnly, fn ($query) => $query->whereNull('parent_id'))
            ->when($assigneeId !== null, fn (Builder $query) => $query->whereHas('assignees', fn (Builder $q) => $q->where('users.id', $assigneeId)))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('status_id');

        // People on the project = roster ∪ users already assigned to issues of the project.
        $projectPeople = $this->computeProjectPeople($project);

        return [
            'project' => ProjectPresenter::summary($project),
            // Health feeds the pagebar badge and the projection meta.
            'health' => GetProjectHealth::run($project, $user)->toArray(),
            'parentsOnly' => $parentsOnly,
            'filters' => array_filter($filters, fn ($value) => $value !== null && $value !== ''),
            'projectPeople' => $projectPeople,
            'currentUserId' => $user?->id,
            // Creation options for the board's full issue dialog (mirrors ShowIssuesPage).
            'labels' => $workspace->labels()->orderBy('name')->get()->map(fn ($label) => LabelPresenter::present($label))->all(),
            'members' => $workspace->members()->orderBy('name')->get()->map(fn ($member) => ProjectMemberPresenter::person($member))->all(),
            'templates' => $workspace->issueTemplates()
                ->with('labels')
                ->get()
                ->map(fn ($template) => TemplatePresenter::present($template))
                ->all(),
            'columns' => $workspace->statuses()
                ->get()
                ->map(fn ($status) => [
                    'id' => $status->id,
                    'name' => $status->name,
                    'category' => $status->category->value,
                    'issuesCount' => $issues->get($status->id)?->count() ?? 0,
                    'issues' => ($issues->get($status->id) ?? collect())
                        ->map(fn (Issue $issue) => IssuePresenter::boardCard($issue))
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    public function asController(Request $request, Project $project, ProjectBoardResponder $responder): Response
    {
        $data = $this->handle(
            $project,
            $request->boolean('parents'),
            [
                'assignee' => $request->query('assignee'),
            ],
            $request->user(),
        );

        // A `?issue=` param opens the issue detail in the board's modal: the
        // payload mirrors ShowIssuePage's so the modal reuses the fiche
        // component. Accessibility (guest perimeter, cross-workspace) is
        // enforced by ShowIssuePage itself; unknown ids 404 like any other
        // issue surface.
        $issueId = $request->query('issue');
        $data['issueDetail'] = is_string($issueId) && $issueId !== ''
            ? ShowIssuePage::run(app(WorkspaceContext::class)->getOrFail(), Issue::findOrFail($issueId), $request->user())
            : null;

        return $responder->toResponse($request, $data);
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
            ->map(fn ($user) => ProjectMemberPresenter::person($user))
            ->all();
    }
}
