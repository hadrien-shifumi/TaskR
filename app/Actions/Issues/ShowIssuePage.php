<?php

namespace App\Actions\Issues;

use App\Models\AuditEvent;
use App\Models\Issue;
use App\Models\User;
use App\Models\Workspace;
use App\Presenters\Audit\AuditEventPresenter;
use App\Presenters\Comments\CommentPresenter;
use App\Presenters\Issues\IssuePresenter;
use App\Presenters\Labels\LabelPresenter;
use App\Presenters\Milestones\MilestonePresenter;
use App\Presenters\Projects\ProjectMemberPresenter;
use App\Presenters\Projects\ProjectPresenter;
use App\Presenters\Statuses\StatusPresenter;
use App\Presenters\TimeTracking\TimeEntryPresenter;
use App\Responders\Issues\IssuePageResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class ShowIssuePage
{
    use AsController;
    use AsObject;

    /**
     * @param  array<string, mixed>
     */
    public function handle(Workspace $workspace, Issue $issue, ?User $user = null): array
    {
        return [
            ...$this->handleShell($workspace, $issue, $user),
            'comments' => $this->comments($issue),
            'activity' => $this->activity($issue),
            'dependencyCandidates' => $this->dependencyCandidates($issue, $user),
        ];
    }

    /**
     * The fiche without its heavy extras (comment thread, audit journal,
     * candidate lists): everything needed for the first paint. The HTTP
     * response defers the extras; object callers (board modal, tests,
     * agents) compose them via handle().
     *
     * @return array<string, mixed>
     */
    public function handleShell(Workspace $workspace, Issue $issue, ?User $user = null): array
    {
        // Out-of-scope issues fail exactly like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $issue);
        }

        $issue->load(['project', 'status', 'labels', 'assignees', 'milestone', 'timeEntries.author', 'creator', 'blockedBy.status', 'blocks.status']);
        $issue->loadCount('comments');

        $parent = $issue->parent()
            ->with('status')
            ->first();

        $children = $issue->children()
            ->with(['status', 'assignees'])
            ->orderBy('created_at')
            ->get();

        // The active-descendant summary rides the fiche: the status change
        // offers to bring the whole subtree along, so the screen must know
        // which descendants differ from the target — all generations.
        $descendants = app(LoadActiveDescendants::class)->run(collect([$issue]), $user);

        $timeEntries = $issue->timeEntries
            ->sortByDesc('worked_at')
            ->sortByDesc('created_at')
            ->values();

        app(LoadIssueKnowledgePages::class)->handle($issue, $user);

        $detail = IssuePresenter::detail($issue, $parent, $children, $descendants);
        $detail['blocks'] = $this->withDependentBlockerIds($detail['blocks'], $issue);

        return [
            'issue' => $detail,
            'timeEntries' => $timeEntries->map(fn ($entry) => TimeEntryPresenter::detail($entry))->values()->all(),
            'statuses' => $workspace->cachedStatuses()->map(fn ($status) => StatusPresenter::option($status))->all(),
            'members' => $workspace->members()->orderBy('name')->get()->map(fn ($member) => ProjectMemberPresenter::person($member))->all(),
            'labels' => $workspace->labels()->orderBy('name')->get()->map(fn ($label) => LabelPresenter::present($label))->all(),
            'projects' => $this->projectOptions($workspace, $user),
            // The milestone dialog of the sidebar: every milestone of the
            // issue's project (the attach action enforces the same-project
            // rule), offered to contributors only.
            'milestoneOptions' => $issue->project->milestones()
                ->orderBy('title')
                ->get()
                ->map(fn ($milestone) => MilestonePresenter::option($milestone))
                ->values()
                ->all(),
            'canContribute' => $user instanceof User && $issue->project->isContributor($user),
        ];
    }

    /**
     * The comment thread, ordered, with authors.
     *
     * @return array<int, array<string, mixed>>
     */
    private function comments(Issue $issue): array
    {
        return $issue->comments()
            ->with('author')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($comment) => CommentPresenter::detail($comment))
            ->values()
            ->all();
    }

    /**
     * Descending audit timeline, newest first; the id breaks ties within
     * the same second. Capped at 100 rows to keep the payload bounded.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activity(Issue $issue): array
    {
        return AuditEvent::query()
            ->where('issue_id', $issue->id)
            ->with('actor')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn ($event) => AuditEventPresenter::detail($event))
            ->values()
            ->all();
    }

    public function asController(Request $request, Issue $issue, IssuePageResponder $responder): Response
    {
        $workspace = app(WorkspaceContext::class)->getOrFail();
        $user = $request->user();

        // The heavy extras ride deferred props: the fiche shell paints
        // immediately and the client fetches thread, journal and dependency
        // candidate lists in a parallel partial reload. The parent
        // autocomplete fetches live through the search endpoint instead of
        // shipping a full candidate list.
        $data = [
            ...$this->handleShell($workspace, $issue, $user),
            'comments' => Inertia::defer(fn (): array => $this->comments($issue)),
            'activity' => Inertia::defer(fn (): array => $this->activity($issue)),
            'dependencyCandidates' => Inertia::defer(fn (): array => $this->dependencyCandidates($issue, $user)),
        ];

        return $responder->toResponse($request, $data);
    }

    /**
     * Nameable projects for the knowledge section's project badges: the
     * whole workspace for a full member, the guest's perimeter only
     * otherwise — same scoping as the knowledge page's sidebar.
     *
     * @return array<int, array{id: string, name: string}>
     */
    private function projectOptions(Workspace $workspace, ?User $user): array
    {
        $scope = $user !== null
            ? app(WorkspaceContext::class)->accessibleProjectIds($user)
            : null;

        return $workspace->projects()
            ->when($scope !== null, fn ($query) => $query->whereIn('id', $scope))
            ->orderBy('name')
            ->get()
            ->map(fn ($project) => ProjectPresenter::option($project))
            ->all();
    }

    /**
     * Mirror rows ("blocks") are deletable from this fiche: the sync route
     * replaces the dependent's whole blocker list, so each row carries its
     * blocker ids — the ✕ re-syncs it minus this issue in a single POST.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>
     */
    private function withDependentBlockerIds(array $blocks, Issue $issue): array
    {
        if ($blocks === []) {
            return $blocks;
        }

        $blockerIds = Issue::query()
            ->whereIn('id', collect($blocks)->pluck('id')->all())
            ->with('blockedBy')
            ->get()
            ->mapWithKeys(fn (Issue $dependent) => [
                $dependent->id => $dependent->blockedBy->modelKeys(),
            ]);

        return collect($blocks)
            ->map(fn (array $dependent) => [
                ...$dependent,
                'blockerIds' => $blockerIds[$dependent['id']] ?? [],
            ])
            ->all();
    }

    /**
     * Candidate blockers for the dependencies dialog: every unarchived
     * issue of the workspace, minus the issue itself and its already
     * linked blockers (sync would silently keep them anyway). Guests
     * only see their perimeter, like parent candidates.
     *
     * @return array<int, array{id: string, title: string}>
     */
    private function dependencyCandidates(Issue $issue, ?User $user): array
    {
        $scope = $user !== null
            ? app(WorkspaceContext::class)->accessibleProjectIds($user)
            : null;

        return Issue::query()
            ->unarchived()
            ->whereKeyNot($issue->id)
            ->whereNotIn('id', $issue->blockedBy->modelKeys())
            ->when($scope !== null, fn ($query) => $query->whereIn('project_id', $scope))
            ->orderBy('title')
            ->get(['id', 'title'])
            ->map(fn (Issue $candidate) => ['id' => $candidate->id, 'title' => $candidate->title])
            ->all();
    }
}
