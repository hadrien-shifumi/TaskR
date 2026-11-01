<?php

namespace App\Actions\Issues;

use App\Models\User;
use App\Models\Workspace;
use App\Presenters\Issues\IssuePresenter;
use App\Presenters\Labels\LabelPresenter;
use App\Presenters\Projects\ProjectMemberPresenter;
use App\Presenters\Projects\ProjectPresenter;
use App\Presenters\Statuses\StatusPresenter;
use App\Presenters\Templates\TemplatePresenter;
use App\Responders\Issues\IssuesPageResponder;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
     * With view=kanban, issues are grouped into one board per active project,
     * each board carrying one column per workspace status (in workspace order).
     * The kanban is a full load: sort and page params are ignored.
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
            ...$this->listIssues($workspace, $filters, $user, $view === 'list' ? $page : 1, $view === 'list'),
            'view' => $view,
            'filters' => array_filter($filters, fn ($value) => $value !== null && $value !== ''),
            'projects' => $workspace->projects()
                ->active()
                ->when($scope !== null, fn ($query) => $query->whereIn('id', $scope))
                ->orderBy('name')
                ->get()
                ->map(fn ($project) => ProjectPresenter::option($project))
                ->all(),
            'statuses' => $workspace->statuses()->get()->map(fn ($status) => StatusPresenter::option($status))->all(),
        ];

        if ($view === 'kanban') {
            $data['projectBoards'] = $this->buildProjectBoards($workspace, $data['issues'], $filters, $scope);
            unset($data['issues'], $data['total'], $data['page'], $data['perPage']);
        }

        return $data;
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

        $data = $this->handle($workspace, [
            'view' => $request->query('view'),
            'project' => $request->query('project'),
            'status' => $this->sanitizeIdFilter($request->query('status')),
            'category' => $request->query('category'),
            'priority' => $request->query('priority'),
            'label' => $this->sanitizeLabelFilter($request->query('label')),
            'assignee' => $request->query('assignee'),
            'overdue' => $request->query('overdue'),
            'blocked' => $request->query('blocked'),
            'due_before' => $this->sanitizeDateFilter($request->query('due_before')),
            'due_after' => $this->sanitizeDateFilter($request->query('due_after')),
            'q' => $request->query('q'),
            'sort' => $this->sanitizeSort($request->query('sort')),
            'dir' => $this->sanitizeDir($request->query('dir')),
        ], $request->user(), $this->sanitizePage($request->query('page')));

        $data['labels'] = $workspace->labels()->orderBy('name')->get()->map(fn ($label) => LabelPresenter::present($label))->all();
        $data['members'] = $workspace->members()->orderBy('name')->get()->map(fn ($member) => ProjectMemberPresenter::person($member))->all();
        $data['templates'] = $workspace->issueTemplates()
            ->with('labels')
            ->get()
            ->map(fn ($template) => TemplatePresenter::present($template))
            ->all();

        return $responder->toResponse($request, $data);
    }

    /**
     * Normalize the sort key read from the query string. Unknown keys are
     * ignored silently — the default order applies.
     */
    private function sanitizeSort(mixed $value): ?string
    {
        return is_string($value) && in_array($value, ['created', 'priority', 'due_date', 'status', 'project'], true)
            ? $value
            : null;
    }

    /**
     * Normalize the sort direction read from the query string. Unknown
     * directions are ignored silently — the default direction applies.
     */
    private function sanitizeDir(mixed $value): ?string
    {
        return is_string($value) && in_array($value, ['asc', 'desc'], true) ? $value : null;
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
     * Normalize a single-ULID filter read from the query string. Anything that
     * is not a ULID is ignored silently.
     */
    private function sanitizeIdFilter(mixed $value): ?string
    {
        return is_string($value) && Str::isUlid($value) ? $value : null;
    }

    /**
     * Normalize the label filter read from the query string: a single ULID,
     * a `label[]` list, or a comma-separated list all work. Invalid values
     * are dropped silently — a shared URL must never 422.
     *
     * @return string|array<int, string>|null
     */
    private function sanitizeLabelFilter(mixed $label): string|array|null
    {
        $values = is_array($label) ? $label : explode(',', (string) $label);

        $ids = array_values(array_filter(
            array_map(fn (mixed $value) => is_string($value) ? trim($value) : '', $values),
            fn (string $value) => $value !== '' && Str::isUlid($value),
        ));

        if ($ids === []) {
            return null;
        }

        return count($ids) === 1 ? $ids[0] : $ids;
    }

    /**
     * Normalize a due-date bound read from the query string. Anything that is
     * not a strict `Y-m-d` date is ignored silently.
     */
    private function sanitizeDateFilter(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (InvalidFormatException|InvalidArgumentException) {
            return null;
        }

        // Rejects overflowing dates such as 2026-13-45, which Carbon rolls over.
        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $value;
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
