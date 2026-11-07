<?php

namespace App\Actions\Projects;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\Priority;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Presenters\Issues\IssuePresenter;
use App\Presenters\Projects\ProjectMemberPresenter;
use App\Presenters\Statuses\StatusPresenter;
use App\Responders\Projects\ProjectArchivesResponder;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class ShowProjectArchives
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * The archive of a project: archived issues, most recently archived
     * first, paginated and filterable. Unarchiving is an owner-only
     * management action — the payload exposes the flag so the view can
     * render the button.
     *
     * @param  array<string, string>  $filters  status|priority|assignee|archived_from|archived_to
     * @return array{project: array<string, mixed>, issues: array<int, array<string, mixed>>, pagination: array<string, int|null>, filters: array<string, string>, statuses: array<int, array<string, mixed>>, members: array<int, array<string, mixed>>, canUnarchive: bool}
     */
    public function handle(Project $project, array $filters = [], int $page = 1, ?User $user = null): array
    {
        // Out-of-scope projects fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP callers always pass the actor.
        app(WorkspaceContext::class)->abortUnlessProjectAccessible($user, $project);

        $workspace = app(WorkspaceContext::class)->getOrFail();

        $query = $project->issues()
            ->archived()
            ->with(['project', 'status', 'assignees', 'labels', 'blockedBy.status'])
            ->orderByDesc('archived_at');

        $this->applyFilters($query, $filters);

        $paginator = $query->paginate(20, ['*'], 'page', $page);

        $issues = $paginator->getCollection()
            ->map(fn (Issue $issue) => [
                ...IssuePresenter::card($issue),
                'archivedAt' => $issue->archived_at->toISOString(),
            ])
            ->values()
            ->all();

        return [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'targetDate' => $project->target_date?->toDateString(),
            ],
            'issues' => $issues,
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'filters' => [
                'status' => $filters['status'] ?? '',
                'priority' => $filters['priority'] ?? '',
                'assignee' => $filters['assignee'] ?? '',
                'archivedFrom' => $filters['archived_from'] ?? '',
                'archivedTo' => $filters['archived_to'] ?? '',
            ],
            'statuses' => $workspace->cachedStatuses()
                ->map(fn ($status) => StatusPresenter::option($status))
                ->all(),
            'members' => $workspace->members()
                ->orderBy('name')
                ->get()
                ->map(fn ($member) => ProjectMemberPresenter::person($member))
                ->all(),
            'canUnarchive' => $user !== null && $workspace->hasOwner($user),
        ];
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function applyFilters(Builder|HasMany $query, array $filters): void
    {
        if (! empty($filters['status'])) {
            $query->where('status_id', $filters['status']);
        }

        if (! empty($filters['priority'])) {
            $priority = Priority::tryFrom($filters['priority']);
            if ($priority !== null) {
                $query->where('priority', $priority);
            }
        }

        if (! empty($filters['assignee'])) {
            $query->whereHas('assignees', fn (Builder $assignees) => $assignees->whereKey($filters['assignee']));
        }

        if (! empty($filters['archived_from'])) {
            $query->whereDate('archived_at', '>=', $filters['archived_from']);
        }

        if (! empty($filters['archived_to'])) {
            $query->whereDate('archived_at', '<=', $filters['archived_to']);
        }
    }

    public function asController(Request $request, Project $project, ProjectArchivesResponder $responder): Response
    {
        $data = $this->handle($project, [
            'status' => $request->query('status', ''),
            'priority' => $request->query('priority', ''),
            'assignee' => $request->query('assignee', ''),
            'archived_from' => $request->query('archived_from', ''),
            'archived_to' => $request->query('archived_to', ''),
        ], (int) $request->query('page', 1), $request->user());

        return $responder->toResponse($request, $data);
    }
}
