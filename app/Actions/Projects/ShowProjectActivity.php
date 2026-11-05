<?php

namespace App\Actions\Projects;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\User;
use App\Presenters\Audit\AuditEventPresenter;
use App\Presenters\Projects\ProjectMemberPresenter;
use App\Presenters\Projects\ProjectPresenter;
use App\Responders\Projects\ProjectActivityResponder;
use App\Support\WorkspaceContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class ShowProjectActivity
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    /** Events per page in the activity feed. */
    public const PER_PAGE = 20;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'actor_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'type' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * Activity feed of a project: the latest journalled events (issue events
     * included through the denormalized project_id), filterable by actor and
     * by event type, paginated 20 per page (the page clamps to the last one),
     * plus status transition aggregates (A → B counters) fed by the
     * append-only journal — the aggregates cover the whole journal whatever
     * the displayed page.
     *
     * @param  int  $page  Requested page, 1-based; invalid values fall back to 1
     * @return array{project: array<string, mixed>, issuesCount: int, events: array<int, array<string, mixed>>, pagination: array{currentPage: int, lastPage: int, perPage: int, total: int, from: int|null, to: int|null}, actors: array<int, array<string, mixed>>, types: array<int, string>, transitions: array<int, array{from: string, to: string, count: int}>}
     */
    public function handle(Project $project, ?int $actorId = null, ?string $type = null, ?User $user = null, int $page = 1): array
    {
        // Out-of-scope projects fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user instanceof User) {
            app(WorkspaceContext::class)->abortUnlessProjectAccessible($user, $project);
        }

        if ($type !== null && $type !== '' && AuditEventType::tryFrom($type) === null) {
            throw ValidationException::withMessages([
                'type' => [__('validation.in', ['attribute' => 'type'])],
            ]);
        }

        $eventsQuery = AuditEvent::query()
            ->where('project_id', $project->id)
            ->with('actor')
            ->when($actorId !== null, fn ($query) => $query->where('actor_id', $actorId))
            ->when($type !== null && $type !== '', fn ($query) => $query->where('type', $type));

        $total = (clone $eventsQuery)->count();

        // Clamp to the last page: an out-of-range page returns the last
        // slice and reports its real number.
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, $page);

        if ($page > $lastPage) {
            $page = $lastPage;
        }

        $events = $eventsQuery
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->forPage($page, self::PER_PAGE)
            ->get()
            ->map(fn (AuditEvent $event) => AuditEventPresenter::detail($event))
            ->all();

        $workspace = app(WorkspaceContext::class)->getOrFail();

        return [
            'project' => ProjectPresenter::summary($project),
            // Feeds the project menu's disabled delete entry.
            'issuesCount' => $project->issues()->count(),
            'events' => $events,
            'pagination' => [
                'currentPage' => $page,
                'lastPage' => $lastPage,
                'perPage' => self::PER_PAGE,
                'total' => $total,
                'from' => $total === 0 ? null : ($page - 1) * self::PER_PAGE + 1,
                'to' => $total === 0 ? null : min($page * self::PER_PAGE, $total),
            ],
            'actors' => $workspace->members()
                ->orderBy('name')
                ->get()
                ->map(fn ($member) => ProjectMemberPresenter::person($member))
                ->all(),
            'types' => array_map(fn ($case) => $case->value, AuditEventType::cases()),
            'transitions' => $this->transitionAggregates($project->id),
        ];
    }

    public function asController(ActionRequest $request, Project $project, ProjectActivityResponder $responder): Response
    {
        $data = $request->validated();

        $payload = $this->handle(
            $project,
            isset($data['actor_id']) ? (int) $data['actor_id'] : null,
            $data['type'] ?? null,
            $request->user(),
            $this->sanitizePage($request->query('page')),
        );

        return $responder->toResponse($request, $payload);
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
     * Status transition aggregates over the whole project journal: one
     * counter per (before → after) pair of the issue.status_changed events,
     * most frequent first. Aggregated in PHP — the per-project volume is
     * capped by the retention of the journal.
     *
     * @return array<int, array{from: string, to: string, count: int}>
     */
    private function transitionAggregates(string $projectId): array
    {
        $events = AuditEvent::query()
            ->where('project_id', $projectId)
            ->where('type', AuditEventType::IssueStatusChanged)
            ->get(['changes']);

        $counts = [];
        foreach ($events as $event) {
            $before = $event->changes['status']['before'] ?? null;
            $after = $event->changes['status']['after'] ?? null;

            if (! is_string($before) || $before === '' || ! is_string($after) || $after === '') {
                continue;
            }

            $counts[$before][$after] = ($counts[$before][$after] ?? 0) + 1;
        }

        $transitions = [];
        foreach ($counts as $from => $tos) {
            foreach ($tos as $to => $count) {
                $transitions[] = ['from' => $from, 'to' => $to, 'count' => $count];
            }
        }

        usort($transitions, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return $transitions;
    }
}
