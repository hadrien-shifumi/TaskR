<?php

namespace Tests\Feature\Audit;

use App\Actions\Audit\RecordActivity;
use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProjectActivityPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace, 2: Project, 3: Issue}
     */
    private function setupIssue(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);
        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);

        // The setup journalling (project.created, issue.created) is redated
        // so the fixtures' ordering stays deterministic.
        $projectCreated = AuditEvent::query()
            ->where('type', AuditEventType::ProjectCreated->value)
            ->where('project_id', $project->id)
            ->firstOrFail();
        $this->redate($projectCreated, '2025-12-30 10:00:00');
        $this->redateSetupEvent($issue);

        return [$owner, $workspace, $project, $issue];
    }

    /**
     * The journal is append-only: tests redate rows through the query
     * builder (no model events) to make the descending order deterministic.
     */
    private function redate(AuditEvent $event, string $createdAt): void
    {
        DB::table('audit_events')->where('id', $event->id)->update(['created_at' => $createdAt]);
    }

    /**
     * Redates the issue.created event journalled by CreateIssue during the
     * setup so it stays the oldest row of the fixture.
     */
    private function redateSetupEvent(Issue $issue): void
    {
        $setupEvent = AuditEvent::query()->where('issue_id', $issue->id)->firstOrFail();
        $this->redate($setupEvent, '2025-12-31 10:00:00');
    }

    /**
     * Journals $count comment.created events, one per day from $startDay on,
     * so the descending order across the 20-per-page boundary stays
     * deterministic (the setup events remain the oldest rows).
     *
     * @return array<int, AuditEvent>
     */
    private function journalCommentEvents(Issue $issue, User $actor, int $count, int $startDay): array
    {
        $events = [];

        for ($i = 0; $i < $count; $i++) {
            $event = RecordActivity::run(AuditEventType::CommentCreated, $issue, $actor, [
                'body' => ['before' => null, 'after' => 'Retour n°'.($i + 1)],
            ]);
            $this->redate($event, sprintf('2026-01-%02d 10:00:00', $startDay + $i));
            $events[] = $event;
        }

        return $events;
    }

    public function test_activity_page_requires_authentication(): void
    {
        [$owner, , $project] = $this->setupIssue();

        $this->get(route('projects.activity', $project->id))
            ->assertRedirect(route('login'));
    }

    public function test_cross_workspace_access_is_denied(): void
    {
        [$owner, , $project] = $this->setupIssue();

        $intruder = User::factory()->create();
        CreateWorkspace::run($intruder, ['name' => 'Theirs']);

        $this->actingAs($intruder)
            ->get(route('projects.activity', $project->id))
            ->assertNotFound();
    }

    public function test_guest_out_of_scope_project_is_not_found(): void
    {
        [$owner, $workspace, $project] = $this->setupIssue();

        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Hors perimetre']);

        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $guest->id,
            'role' => 'member',
        ]);

        $this->actingAs($guest)
            ->get(route('projects.activity', $otherProject->id))
            ->assertNotFound();
    }

    public function test_activity_page_lists_project_events_descending_including_issue_events(): void
    {
        [$owner, $workspace, $project, $issue] = $this->setupIssue();

        $statusChanged = RecordActivity::run(
            AuditEventType::IssueStatusChanged,
            $issue,
            $owner,
            ['status' => ['before' => 'Backlog', 'after' => 'In Progress']],
        );
        $this->redate($statusChanged, '2026-01-02 10:00:00');

        $commentCreated = RecordActivity::run(
            AuditEventType::CommentCreated,
            $issue,
            $owner,
            ['body' => ['before' => null, 'after' => 'Premier retour']],
        );
        $this->redate($commentCreated, '2026-01-03 10:00:00');

        $this->actingAs($owner)
            ->get(route('projects.activity', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Activity')
                // Descending: the latest journalled event comes first, the
                // setup's journaling is last. Issue events surface through
                // the denormalized project_id column.
                ->has('events', 4)
                ->where('events.0.type', 'comment.created')
                ->where('events.1.type', 'issue.status_changed')
                ->where('events.2.type', 'issue.created')
                ->where('events.0.actor.id', $owner->id)
                ->where('events.0.actor.name', $owner->name)
                ->has('events.0.createdAt')
                ->where('events.1.changes.status.before', 'Backlog')
                ->where('events.1.changes.status.after', 'In Progress')
                ->where('project.id', $project->id)
                ->where('project.name', 'Site vitrine')
                // The actor filter is fed with the workspace members.
                ->has('actors', 1)
                ->where('actors.0.id', $owner->id)
                // The type filter offers every journalled event type.
                ->has('types', count(AuditEventType::cases()))
                // Single page: the pagination reports the whole journal.
                ->where('pagination.currentPage', 1)
                ->where('pagination.lastPage', 1)
                ->where('pagination.perPage', 20)
                ->where('pagination.total', 4)
                ->where('pagination.from', 1)
                ->where('pagination.to', 4)
                ->has('transitions', 1));
    }

    public function test_actor_filter_returns_only_events_of_that_actor(): void
    {
        [$owner, $workspace, $project, $issue] = $this->setupIssue();

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $ownEvent = RecordActivity::run(AuditEventType::IssueStatusChanged, $issue, $owner, [
            'status' => ['before' => 'Backlog', 'after' => 'In Progress'],
        ]);
        $this->redate($ownEvent, '2026-01-02 10:00:00');

        $memberEvent = RecordActivity::run(AuditEventType::CommentCreated, $issue, $member, [
            'body' => ['before' => null, 'after' => 'Vu par le collègue'],
        ]);
        $this->redate($memberEvent, '2026-01-03 10:00:00');

        $this->actingAs($owner)
            ->get(route('projects.activity', ['project' => $project->id, 'actor_id' => $member->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('events', 1)
                ->where('events.0.type', 'comment.created')
                ->where('events.0.actor.id', $member->id));
    }

    public function test_type_filter_returns_only_events_of_that_type(): void
    {
        [$owner, , $project, $issue] = $this->setupIssue();

        $statusChanged = RecordActivity::run(AuditEventType::IssueStatusChanged, $issue, $owner, [
            'status' => ['before' => 'Backlog', 'after' => 'In Progress'],
        ]);
        $this->redate($statusChanged, '2026-01-02 10:00:00');

        $commentCreated = RecordActivity::run(AuditEventType::CommentCreated, $issue, $owner);
        $this->redate($commentCreated, '2026-01-03 10:00:00');

        $this->actingAs($owner)
            ->get(route('projects.activity', ['project' => $project->id, 'type' => 'comment.created']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('events', 1)
                ->where('events.0.type', 'comment.created'));
    }

    public function test_invalid_type_filter_is_rejected(): void
    {
        [$owner, , $project] = $this->setupIssue();

        $this->actingAs($owner)
            ->getJson(route('projects.activity', ['project' => $project->id, 'type' => 'not-a-type']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_invalid_actor_id_filter_is_rejected(): void
    {
        [$owner, , $project] = $this->setupIssue();

        $this->actingAs($owner)
            ->getJson(route('projects.activity', ['project' => $project->id, 'actor_id' => 'not-a-number']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('actor_id');
    }

    public function test_status_transition_aggregates_count_pairs(): void
    {
        [$owner, , $project, $issue] = $this->setupIssue();

        $first = RecordActivity::run(AuditEventType::IssueStatusChanged, $issue, $owner, [
            'status' => ['before' => 'Backlog', 'after' => 'In Progress'],
        ]);
        $this->redate($first, '2026-01-02 10:00:00');

        $second = RecordActivity::run(AuditEventType::IssueStatusChanged, $issue, $owner, [
            'status' => ['before' => 'In Progress', 'after' => 'Done'],
        ]);
        $this->redate($second, '2026-01-03 10:00:00');

        $third = RecordActivity::run(AuditEventType::IssueStatusChanged, $issue, $owner, [
            'status' => ['before' => 'Backlog', 'after' => 'In Progress'],
        ]);
        $this->redate($third, '2026-01-04 10:00:00');

        $this->actingAs($owner)
            ->get(route('projects.activity', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Sorted by descending count: the 2× pair first.
                ->has('transitions', 2)
                ->where('transitions.0.from', 'Backlog')
                ->where('transitions.0.to', 'In Progress')
                ->where('transitions.0.count', 2)
                ->where('transitions.1.from', 'In Progress')
                ->where('transitions.1.to', 'Done')
                ->where('transitions.1.count', 1));
    }

    public function test_events_from_other_workspaces_are_invisible(): void
    {
        [$owner, $workspace, $project, $issue] = $this->setupIssue();

        $foreignEvent = RecordActivity::run(AuditEventType::IssueStatusChanged, $issue, $owner, [
            'status' => ['before' => 'Backlog', 'after' => 'In Progress'],
        ]);
        $this->redate($foreignEvent, '2026-01-02 10:00:00');

        // A second workspace journals its own events; they must never leak
        // into the first workspace's project activity page.
        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Autre WS']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $otherProject = CreateProject::run($otherWorkspace, $otherOwner, ['name' => 'Projet ailleurs']);
        $otherIssue = CreateIssue::run($otherWorkspace, ['project_id' => $otherProject->id, 'title' => 'Ailleurs']);

        app(WorkspaceContext::class)->set($workspace);

        $this->actingAs($owner)
            ->get(route('projects.activity', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('events', 3)
                ->where('events.0.type', 'issue.status_changed')
                ->where('events.1.type', 'issue.created')
                ->where('events.2.type', 'project.created'));
    }

    public function test_activity_page_shows_twenty_events_per_page(): void
    {
        [$owner, , $project, $issue] = $this->setupIssue();

        // 24 journalled comments + the 2 setup events = 26 rows: the feed
        // splits into one full page of 20 and a remainder of 6.
        $this->journalCommentEvents($issue, $owner, 24, 1);

        $this->actingAs($owner)
            ->get(route('projects.activity', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Activity')
                ->has('events', 20)
                ->where('pagination.currentPage', 1)
                ->where('pagination.lastPage', 2)
                ->where('pagination.perPage', 20)
                ->where('pagination.total', 26)
                ->where('pagination.from', 1)
                ->where('pagination.to', 20));
    }

    public function test_activity_page_second_page_returns_the_remaining_events(): void
    {
        [$owner, , $project, $issue] = $this->setupIssue();

        $this->journalCommentEvents($issue, $owner, 24, 1);

        $this->actingAs($owner)
            ->get(route('projects.activity', ['project' => $project->id, 'page' => 2]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Activity')
                // The 6 oldest rows: the four first comments, then the
                // journalled setup of the issue and of the project.
                ->has('events', 6)
                ->where('events.0.type', 'comment.created')
                ->where('events.5.type', 'project.created')
                ->where('pagination.currentPage', 2)
                ->where('pagination.lastPage', 2)
                ->where('pagination.total', 26)
                ->where('pagination.from', 21)
                ->where('pagination.to', 26));
    }

    public function test_activity_page_beyond_last_page_clamps_to_last_page(): void
    {
        [$owner, , $project, $issue] = $this->setupIssue();

        $this->journalCommentEvents($issue, $owner, 24, 1);

        // A stale or shared URL never errors: page 99 lands on the last page.
        $this->actingAs($owner)
            ->get(route('projects.activity', ['project' => $project->id, 'page' => 99]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Activity')
                ->has('events', 6)
                ->where('pagination.currentPage', 2)
                ->where('pagination.lastPage', 2));
    }

    public function test_activity_page_invalid_page_values_fall_back_to_the_first_page(): void
    {
        [$owner, , $project, $issue] = $this->setupIssue();

        $this->journalCommentEvents($issue, $owner, 24, 1);

        // The page param is sanitized silently — never a validation error.
        foreach (['abc', '-3', '0'] as $rawPage) {
            $this->actingAs($owner)
                ->get(route('projects.activity', ['project' => $project->id, 'page' => $rawPage]))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $inertia) => $inertia
                    ->component('Projects/Activity')
                    ->has('events', 20)
                    ->where('pagination.currentPage', 1));
        }
    }

    public function test_activity_page_pagination_keeps_actor_and_type_filters(): void
    {
        [$owner, $workspace, $project, $issue] = $this->setupIssue();

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        // The member owns 25 events (two pages), the owner 5 more recent ones.
        $this->journalCommentEvents($issue, $member, 25, 1);
        $this->journalCommentEvents($issue, $owner, 5, 26);

        $this->actingAs($owner)
            ->get(route('projects.activity', [
                'project' => $project->id,
                'page' => 2,
                'actor_id' => $member->id,
                'type' => 'comment.created',
            ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Activity')
                // The pagination follows the filtered set, not the whole
                // journal: page 2 holds the member's five oldest events only.
                ->has('events', 5)
                ->where('events.0.actor.id', $member->id)
                ->where('events.4.actor.id', $member->id)
                ->where('pagination.currentPage', 2)
                ->where('pagination.lastPage', 2)
                ->where('pagination.total', 25)
                ->where('pagination.from', 21)
                ->where('pagination.to', 25));
    }

    public function test_transition_aggregates_are_identical_on_every_page(): void
    {
        [$owner, , $project, $issue] = $this->setupIssue();

        // Three status transitions land among the oldest rows (page 2) while
        // the 20 comments fill page 1: the aggregates must stay complete.
        $first = RecordActivity::run(AuditEventType::IssueStatusChanged, $issue, $owner, [
            'status' => ['before' => 'Backlog', 'after' => 'In Progress'],
        ]);
        $this->redate($first, '2026-01-01 10:00:00');

        $second = RecordActivity::run(AuditEventType::IssueStatusChanged, $issue, $owner, [
            'status' => ['before' => 'In Progress', 'after' => 'Done'],
        ]);
        $this->redate($second, '2026-01-02 10:00:00');

        $third = RecordActivity::run(AuditEventType::IssueStatusChanged, $issue, $owner, [
            'status' => ['before' => 'Backlog', 'after' => 'In Progress'],
        ]);
        $this->redate($third, '2026-01-03 10:00:00');

        $this->journalCommentEvents($issue, $owner, 20, 4);

        $assertTransitions = fn (AssertableInertia $page) => $page
            ->has('transitions', 2)
            ->where('transitions.0.from', 'Backlog')
            ->where('transitions.0.to', 'In Progress')
            ->where('transitions.0.count', 2)
            ->where('transitions.1.from', 'In Progress')
            ->where('transitions.1.to', 'Done')
            ->where('transitions.1.count', 1);

        // Page 1: the 20 comments, without any transition on display.
        $this->actingAs($owner)
            ->get(route('projects.activity', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $assertTransitions($page)
                ->has('events', 20)
                ->where('events.0.type', 'comment.created'));

        // Page 2: the three transitions plus the setup events — the
        // aggregates still count every transition of the whole journal.
        $this->actingAs($owner)
            ->get(route('projects.activity', ['project' => $project->id, 'page' => 2]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $assertTransitions($page)
                ->has('events', 5)
                ->where('events.0.type', 'issue.status_changed')
                ->where('events.4.type', 'project.created'));
    }
}
