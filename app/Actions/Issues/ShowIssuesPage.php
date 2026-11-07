<?php

namespace App\Actions\Issues;

use App\Models\User;
use App\Models\Workspace;
use App\Presenters\Issues\IssuePresenter;
use App\Presenters\Labels\LabelPresenter;
use App\Presenters\Projects\ProjectMemberPresenter;
use App\Presenters\Projects\ProjectPresenter;
use App\Presenters\SavedViews\SavedViewPresenter;
use App\Presenters\Statuses\StatusPresenter;
use App\Presenters\Templates\TemplatePresenter;
use App\Responders\Issues\IssuesPageResponder;
use App\Support\IssueFilterSanitizer;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class ShowIssuesPage
{
    use AsController;
    use AsObject;

    /** Tickets per page in the list view; the kanban stays a full load. */
    public const PER_PAGE = 50;

    /**
     * Filterable list across every project of the workspace.
     *
     * With view=list (the default), the list is sorted and paginated
     * server-side: `page` (1-based, out-of-range values clamp to the last
     * page) and `perPage` are exposed next to the issues.
     *
     * With view=kanban, the shell only is returned: the boards — the
     * heaviest payload of the app — ride a deferred prop built by
     * projectBoards(), fetched by the client in a parallel partial reload
     * right after the first paint. Sort and page params are ignored.
     *
     * @param  array<string, string|null>  $filters  view|project|status|category|priority|label|assignee|overdue|blocked|q|sort|dir
     * @param  int  $page  Requested list page, 1-based; invalid values fall back to 1
     * @return array<string, mixed>
     */
    public function handle(Workspace $workspace, array $filters = [], ?User $user = null, int $page = 1): array
    {
        $view = $filters['view'] ?? 'list';

        $scope = $user instanceof User
            ? app(WorkspaceContext::class)->accessibleProjectIds($user)
            : null;

        $data = [
            'view' => $view,
            'filters' => array_filter($filters, fn ($value) => $value !== null && $value !== ''),
            'projects' => $workspace->projects()
                ->active()
                ->when($scope !== null, fn ($query) => $query->whereIn('id', $scope))
                ->orderBy('name')
                ->get()
                ->map(fn ($project) => ProjectPresenter::option($project))
                ->all(),
            'statuses' => $workspace->cachedStatuses()->map(fn ($status) => StatusPresenter::option($status))->all(),
        ];

        if ($view === 'list') {
            $data = [...$data, ...$this->listIssues($workspace, $filters, $user, $page, true)];
        }

        return $data;
    }

    /**
     * The kanban boards: one board per active project, built from the full
     * unpaginated issue list. Called lazily from the deferred prop.
     *
     * @param  array<string, string|null>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function projectBoards(Workspace $workspace, array $filters, ?User $user): array
    {
        $scope = $user instanceof User
            ? app(WorkspaceContext::class)->accessibleProjectIds($user)
            : null;

        $issues = ListIssues::run($workspace, $filters, null, 0, $user);

        return $this->buildProjectBoards($workspace, $issues['issues'], $filters, $scope);
    }

    /**
     * Pull one page of the list, or the whole list for the kanban. The page
     * is clamped to the last one so a stale or shared URL never 404s.
     *
     * @param  array<string, string|null>  $filters
     * @return array{issues: array<int, array<string, mixed>>, total: int, totalUnfiltered: int, page?: int, perPage?: int}
     */
    private function listIssues(Workspace $workspace, array $filters, ?User $user, int $page, bool $paginate): array
    {
        if (! $paginate) {
            return ListIssues::run($workspace, $filters, null, 0, $user);
        }

        $page = max(1, $page);

        $result = ListIssues::run(
            $workspace,
            $filters,
            self::PER_PAGE,
            ($page - 1) * self::PER_PAGE,
            $user,
            $filters['sort'] ?? null,
            $filters['dir'] ?? null,
        );

        // Clamp to the last page: an out-of-range page returns the last
        // slice and reports its real number.
        $lastPage = max(1, (int) ceil($result['total'] / self::PER_PAGE));

        if ($page > $lastPage) {
            $page = $lastPage;
            $result = ListIssues::run(
                $workspace,
                $filters,
                self::PER_PAGE,
                ($page - 1) * self::PER_PAGE,
                $user,
                $filters['sort'] ?? null,
                $filters['dir'] ?? null,
            );
        }

        return [...$result, 'page' => $page, 'perPage' => self::PER_PAGE];
    }

    public function asController(Request $request, IssuesPageResponder $responder): Response
    {
        $workspace = app(WorkspaceContext::class)->getOrFail();

        $filters = [
            'view' => $request->query('view'),
            'project' => $request->query('project'),
            'status' => IssueFilterSanitizer::ulid($request->query('status')),
            'category' => $request->query('category'),
            'priority' => $request->query('priority'),
            'label' => IssueFilterSanitizer::labels($request->query('label')),
            'assignee' => $request->query('assignee'),
            'overdue' => $request->query('overdue'),
            'blocked' => $request->query('blocked'),
            'due_before' => IssueFilterSanitizer::date($request->query('due_before')),
            'due_after' => IssueFilterSanitizer::date($request->query('due_after')),
            'q' => $request->query('q'),
            'sort' => IssueFilterSanitizer::sort($request->query('sort')),
            'dir' => IssueFilterSanitizer::direction($request->query('dir')),
        ];

        $data = $this->handle($workspace, $filters, $request->user(), $this->sanitizePage($request->query('page')));

        // The kanban boards ride a deferred prop: the page shell renders
        // immediately and the client fetches the boards in a parallel
        // partial reload — the full-load list never blocks the first paint.
        if (($data['view'] ?? 'list') === 'kanban') {
            $data['projectBoards'] = Inertia::defer(
                fn (): array => $this->projectBoards($workspace, $filters, $request->user()),
            );
        }

        $data['labels'] = $workspace->labels()->orderBy('name')->get()->map(fn ($label) => LabelPresenter::present($label))->all();
        $data['members'] = $workspace->members()->orderBy('name')->get()->map(fn ($member) => ProjectMemberPresenter::person($member))->all();
        $data['templates'] = $workspace->issueTemplates()
            ->with('labels')
            ->get()
            ->map(fn ($template) => TemplatePresenter::present($template))
            ->all();
        $data['savedViews'] = $workspace->savedViews()
            ->get()
            ->map(fn ($view) => SavedViewPresenter::present($view))
            ->all();

        return $responder->toResponse($request, $data);
    }

    /**
     * Normalize the page number read from the query string. Anything that is
     * not a positive integer falls back to the first page.
     */
    private function sanitizePage(mixed $value): int
    {
        if (! is_string($value) || preg_match('/^\d+$/', trim($value)) !== 1) {
            return 1;
        }

        return max(1, (int) trim($value));
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
        $statuses = $workspace->cachedStatuses();

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
                                ->map(fn (array $issue) => IssuePresenter::kanbanCard($issue))
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
}
