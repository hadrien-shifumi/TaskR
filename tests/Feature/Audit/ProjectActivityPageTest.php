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
}
