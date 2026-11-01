<?php

namespace Tests\Feature\Audit;

use App\Actions\Audit\RecordActivity;
use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\ShowIssuePage;
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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class IssueTimelineTest extends TestCase
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

        return [$owner, $workspace, $project, $issue];
    }

    /**
     * The journal is append-only: tests redate rows through the query
     * builder (no model events) to make the descending order deterministic —
     * timestamps() only carries second precision, so same-second inserts
     * would fall back to the id tie-breaker.
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

    public function test_issue_page_payload_contains_activity_ordered_descending_with_type_actor_changes(): void
    {
        [$owner, , $project, $issue] = $this->setupIssue();
        $this->redateSetupEvent($issue);

        $statusChanged = RecordActivity::run(
            AuditEventType::IssueStatusChanged,
            $issue,
            $owner,
            ['status' => ['before' => 'Backlog', 'after' => 'In Progress']],
        );
        $this->redate($statusChanged, '2026-01-01 10:00:00');

        $commentCreated = RecordActivity::run(
            AuditEventType::CommentCreated,
            $issue,
            $owner,
            ['body' => ['before' => null, 'after' => 'Premier retour']],
        );
        $this->redate($commentCreated, '2026-01-02 10:00:00');

        $priorityChanged = RecordActivity::run(
            AuditEventType::IssuePriorityChanged,
            $issue,
            $owner,
            ['priority' => ['before' => 'none', 'after' => 'high']],
        );
        $this->redate($priorityChanged, '2026-01-03 10:00:00');

        $this->actingAs($owner)
            ->get(route('issues.show', $issue->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Issues/Show')
                // Descending: the latest journalled event comes first, the
                // setup's issue.created is last.
                ->has('activity', 4)
                ->where('activity.0.type', 'issue.priority_changed')
                ->where('activity.1.type', 'comment.created')
                ->where('activity.2.type', 'issue.status_changed')
                ->where('activity.3.type', 'issue.created')
                ->where('activity.0.actor.id', $owner->id)
                ->where('activity.0.actor.name', $owner->name)
                ->has('activity.0.createdAt')
                ->where('activity.2.changes.status.before', 'Backlog')
                ->where('activity.2.changes.status.after', 'In Progress')
                // Project context rides along for the breadcrumb, untouched.
                ->where('issue.project.id', $project->id));
    }

    public function test_activity_actor_is_null_for_system_events(): void
    {
        [$owner, , , $issue] = $this->setupIssue();
        $this->redateSetupEvent($issue);

        $humanEvent = RecordActivity::run(AuditEventType::IssueStatusChanged, $issue, $owner);
        $this->redate($humanEvent, '2026-04-01 10:00:00');

        $systemEvent = RecordActivity::run(AuditEventType::IssueCreated, $issue);
        $this->redate($systemEvent, '2026-05-01 10:00:00');

        $this->actingAs($owner)
            ->get(route('issues.show', $issue->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('activity.0.type', 'issue.created')
                ->where('activity.0.actor', null)
                // The human event keeps its actor.
                ->where('activity.1.actor.id', $owner->id)
                ->where('activity.1.actor.name', $owner->name));
    }

    public function test_guest_out_of_scope_issue_page_is_not_found(): void
    {
        [$owner, $workspace, $project] = $this->setupIssue();

        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Hors perimetre']);
        $outOfScopeIssue = CreateIssue::run($workspace, [
            'project_id' => $otherProject->id,
            'title' => 'Interdit',
        ]);

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
            ->get(route('issues.show', $outOfScopeIssue->id))
            ->assertNotFound();
    }

    public function test_board_issue_detail_payload_contains_activity(): void
    {
        [$owner, , $project, $issue] = $this->setupIssue();
        $this->redateSetupEvent($issue);

        $statusChanged = RecordActivity::run(
            AuditEventType::IssueStatusChanged,
            $issue,
            $owner,
            ['status' => ['before' => 'Backlog', 'after' => 'In Progress']],
        );
        $this->redate($statusChanged, '2026-01-01 10:00:00');

        $this->actingAs($owner)
            ->get(route('projects.board', ['project' => $project->id, 'issue' => $issue->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Board')
                ->has('issueDetail.activity', 2)
                ->where('issueDetail.activity.0.type', 'issue.status_changed')
                ->where('issueDetail.activity.0.actor.name', $owner->name)
                ->where('issueDetail.activity.0.changes.status.after', 'In Progress')
                ->where('issueDetail.activity.1.type', 'issue.created'));
    }

    public function test_activity_payload_is_capped_at_100_events(): void
    {
        [$owner, $workspace, , $issue] = $this->setupIssue();
        $this->redateSetupEvent($issue);

        $base = Carbon::parse('2026-01-01 00:00:00');
        $recorded = [];
        for ($i = 0; $i < 105; $i++) {
            $event = RecordActivity::run(AuditEventType::IssueUpdated, $issue, $owner);
            $this->redate($event, $base->copy()->addMinutes($i)->format('Y-m-d H:i:s'));
            $recorded[] = $event;
        }

        $payload = ShowIssuePage::run($workspace, $issue, $owner);

        $this->assertCount(100, $payload['activity']);

        $keptDates = array_column($payload['activity'], 'createdAt');
        $dropped = array_slice($recorded, 0, 5);
        foreach ($dropped as $droppedEvent) {
            $droppedIso = $base->copy()->addMinutes(array_search($droppedEvent, $recorded, true))->toISOString();
            $this->assertNotContains($droppedIso, $keptDates);
        }

        // Newest head of the descending timeline.
        $this->assertSame(
            $base->copy()->addMinutes(104)->toISOString(),
            $payload['activity'][0]['createdAt'],
        );
    }
}
