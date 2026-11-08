<?php

namespace App\Actions\Projects;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\Priority;
use App\Enums\StatusCategory;
use App\Models\AuditEvent;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use App\Presenters\Audit\AuditEventPresenter;
use App\Presenters\Issues\IssuePresenter;
use App\Presenters\Projects\ProjectMemberPresenter;
use App\Presenters\Projects\ProjectPresenter;
use App\Responders\Projects\ProjectMemberActivityResponder;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class ShowProjectMemberActivity
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    /** Events per page in the member activity feed. */
    public const PER_PAGE = 20;

    public function authorize(): bool
    {
        if (! $this->currentUserCanUseWorkspace()) {
            return false;
        }

        $user = request()->user();
        $project = request()->route('project');

        if (! $user instanceof User || ! $project instanceof Project) {
            return false;
        }

        // Same visibility rule as the team workload on the health page:
        // managers only (project owner/manager, or workspace owner).
        return $project->canManage($user);
    }

    public function rules(): array
    {
        // The member is fixed by the route and the page number is
        // sanitized in asController(): nothing to validate on the request.
        return [];
    }

    /**
     * Activity of a single project member: the open issues assigned to them
     * in the project (what they are working on right now) plus their slice of
     * the project journal, paginated like the project activity feed.
     *
     * @param  int  $page  Requested page, 1-based; invalid values fall back to 1
     * @return array{project: array<string, mixed>, issuesCount: int, member: array<string, mixed>, assignedIssues: array<int, array<string, mixed>>, events: array<int, array<string, mixed>>, pagination: array{currentPage: int, lastPage: int, perPage: int, total: int, from: int|null, to: int|null}}
     */
    public function handle(Project $project, User $member, ?User $user = null, int $page = 1): array
    {
        // Out-of-scope projects fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user instanceof User) {
            app(WorkspaceContext::class)->abortUnlessProjectAccessible($user, $project);
        }

        // The member must be part of this project's roster: an
        // out-of-perimeter member is simply not found, not leaked.
        if (! $project->projectMembers()->where('user_id', $member->getKey())->exists()) {
            abort(404);
        }

        $eventsQuery = AuditEvent::query()
            ->where('project_id', $project->id)
            ->where('actor_id', $member->id)
            ->with('actor');

        $total = (clone $eventsQuery)->count();

        // Clamp to the last page: a stale or shared URL never errors, it
        // lands on the last slice of the filtered journal.
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, $page);
        if ($page > $lastPage) {
            $page = $lastPage;
        }

        $events = (clone $eventsQuery)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->forPage($page, self::PER_PAGE)
            ->get()
            ->map(fn (AuditEvent $event) => AuditEventPresenter::detail($event))
            ->all();

        return [
            'project' => ProjectPresenter::summary($project),
            // Feeds the pagebar actions: edit, archive, delete.
            'issuesCount' => $project->issues()->count(),
            'member' => ProjectMemberPresenter::person($member),
            'assignedIssues' => $this->assignedOpenIssues($project, $member),
            'events' => $events,
            'pagination' => [
                'currentPage' => $page,
                'lastPage' => $lastPage,
                'perPage' => self::PER_PAGE,
                'total' => $total,
                'from' => $total === 0 ? null : ($page - 1) * self::PER_PAGE + 1,
                'to' => $total === 0 ? null : min($page * self::PER_PAGE, $total),
            ],
        ];
    }

    public function asController(ActionRequest $request, Project $project, User $member, ProjectMemberActivityResponder $responder): Response
    {
        return $responder->toResponse($request, $this->handle(
            $project,
            $member,
            $request->user(),
            $this->sanitizePage($request->query('page')),
        ));
    }

    /**
     * Normalize the page number read from the query string. Anything that is
     * not a positive integer falls back to the first page — a shared URL
     * must never 422 on the page param.
     */
    private function sanitizePage(mixed $value): int
    {
        if (! is_string($value) || preg_match('/^\d+$/', trim($value)) !== 1) {
            return 1;
        }

        return max(1, (int) trim($value));
    }

    /**
     * The open issues assigned to the member in the project — the ones they
     * are working on right now: backlog and in-progress categories only, not
     * archived, the assignee being the member. Sorted by priority (urgent
     * first) then by most recent update.
     *
     * @return array<int, array<string, mixed>>
     */
    private function assignedOpenIssues(Project $project, User $member): array
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        $openStatusIds = Status::query()
            ->whereIn('category', [StatusCategory::Backlog->value, StatusCategory::InProgress->value])
            ->pluck('id');

        return Issue::query()
            ->where('workspace_id', $workspaceId)
            ->where('project_id', $project->id)
            ->whereNull('archived_at')
            ->whereIn('status_id', $openStatusIds)
            ->whereHas('assignees', fn (Builder $query) => $query->where('users.id', $member->id))
            ->with('status')
            ->orderByRaw($this->priorityOrderExpression().' desc')
            ->orderByDesc('issues.updated_at')
            ->orderBy('issues.id')
            ->get()
            ->map(fn (Issue $issue) => IssuePresenter::assignedIssue($issue))
            ->values()
            ->all();
    }

    /**
     * Portable CASE expression mapping the priority enum onto its case
     * order (none < low < high < urgent), descending — the member's current
     * work lists the most urgent ticket first.
     */
    private function priorityOrderExpression(): string
    {
        $cases = collect(Priority::cases())
            ->map(fn (Priority $priority, int $index) => "when '{$priority->value}' then {$index}")
            ->implode(' ');

        return "case issues.priority {$cases} else 0 end";
    }
}
