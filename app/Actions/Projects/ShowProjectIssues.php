<?php

namespace App\Actions\Projects;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Actions\Issues\ListIssues;
use App\Models\Project;
use App\Models\User;
use App\Presenters\Projects\ProjectPresenter;
use App\Responders\Projects\ProjectIssuesResponder;
use App\Support\IssueFilterSanitizer;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Symfony\Component\HttpFoundation\Response;

class ShowProjectIssues
{
    use AsController;
    use AuthorizesWorkspaceAccess;

    /** Tickets per page, the same rhythm as the workspace issues page. */
    public const PER_PAGE = 50;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * Present the tickets list of a project: the same paginated list engine
     * as the workspace issues page (ListIssues), scoped to one project, with
     * the shared title / identifier search and the "visible / total" counter.
     * The page is clamped to the last one so a stale or shared URL never 404s.
     *
     * @param  array{q?: string|null}  $filters
     * @return array{project: array<string, mixed>, health: array<string, mixed>, issues: array<int, array<string, mixed>>, total: int, totalUnfiltered: int, page: int, perPage: int, filters: array<string, string>}
     */
    public function handle(Project $project, array $filters = [], ?User $user = null, int $page = 1): array
    {
        // Out-of-scope projects fail exactly like non-existent ones.
        app(WorkspaceContext::class)->abortUnlessProjectAccessible($user, $project);

        $workspace = app(WorkspaceContext::class)->getOrFail();

        $page = max(1, $page);
        $result = $this->listPage($workspace, $project, $filters, $user, $page);

        $lastPage = max(1, (int) ceil($result['total'] / self::PER_PAGE));

        if ($page > $lastPage) {
            $page = $lastPage;
            $result = $this->listPage($workspace, $project, $filters, $user, $page);
        }

        return [
            'project' => ProjectPresenter::summary($project),
            // Health feeds the pagebar badge (same header as the board).
            'health' => GetProjectHealth::run($project, $user)->toArray(),
            'issues' => $result['issues'],
            'total' => $result['total'],
            'totalUnfiltered' => $result['totalUnfiltered'],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'filters' => array_filter($filters, fn ($value) => $value !== null && $value !== ''),
        ];
    }

    /**
     * One page of the project's issues, through the shared list engine.
     *
     * @param  array{q?: string|null}  $filters
     * @return array{issues: array<int, array<string, mixed>>, total: int, totalUnfiltered: int}
     */
    private function listPage($workspace, Project $project, array $filters, ?User $user, int $page): array
    {
        return ListIssues::run(
            $workspace,
            [...$filters, 'project' => $project->id],
            self::PER_PAGE,
            ($page - 1) * self::PER_PAGE,
            $user,
        );
    }

    public function asController(Request $request, Project $project, ProjectIssuesResponder $responder): Response
    {
        $data = $this->handle(
            $project,
            ['q' => IssueFilterSanitizer::search($request->query('q'))],
            $request->user(),
            $this->sanitizePage($request->query('page')),
        );

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
}
