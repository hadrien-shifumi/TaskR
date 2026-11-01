<?php

namespace Tests\Feature\Audit;

use App\Actions\Audit\RecordActivity;
use App\Actions\Issues\CreateIssue;
use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Issue;
use App\Models\KnowledgePage;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\TestCase;

class RecordActivityTest extends TestCase
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

    public function test_issue_event_records_type_actor_changes_and_subject(): void
    {
        [$owner, $workspace, $project, $issue] = $this->setupIssue();

        $event = RecordActivity::run(
            AuditEventType::IssueStatusChanged,
            $issue,
            $owner,
            ['status' => ['before' => 'Backlog', 'after' => 'In Progress']],
        );

        $this->assertInstanceOf(AuditEvent::class, $event);
        $this->assertSame($workspace->id, $event->workspace_id);
        $this->assertSame(AuditEventType::IssueStatusChanged, $event->type);
        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame($issue->id, $event->issue_id);
        $this->assertSame($project->id, $event->project_id);
        $this->assertNull($event->knowledge_page_id);
        $this->assertSame(['status' => ['before' => 'Backlog', 'after' => 'In Progress']], $event->changes);
        $this->assertNotNull($event->created_at);

        $this->assertDatabaseHas('audit_events', [
            'id' => $event->id,
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
            'project_id' => $project->id,
            'actor_id' => $owner->id,
            'type' => 'issue.status_changed',
        ]);
    }

    public function test_project_event_records_project_subject(): void
    {
        [$owner, , $project] = $this->setupIssue();

        $event = RecordActivity::run(AuditEventType::ProjectArchived, $project, $owner);

        $this->assertSame($project->id, $event->project_id);
        $this->assertNull($event->issue_id);
        $this->assertNull($event->knowledge_page_id);
        $this->assertSame(AuditEventType::ProjectArchived, $event->type);
    }

    public function test_knowledge_page_event_records_page_subject(): void
    {
        Queue::fake();
        [$owner, , $project] = $this->setupIssue();

        $page = CreateKnowledgePage::run($project->workspace, $owner, [
            'title' => 'Conventions',
            'body' => 'Corps.',
            'project_id' => $project->id,
        ]);

        $event = RecordActivity::run(AuditEventType::KnowledgePageCreated, $page, $owner);

        $this->assertSame($page->id, $event->knowledge_page_id);
        $this->assertNull($event->issue_id);
        $this->assertNull($event->project_id);
        $this->assertSame(AuditEventType::KnowledgePageCreated, $event->type);
    }

    public function test_null_actor_is_accepted(): void
    {
        [, , , $issue] = $this->setupIssue();

        $event = RecordActivity::run(AuditEventType::IssueCreated, $issue);

        $this->assertNull($event->actor_id);
        $this->assertNull($event->actor);
        $this->assertSame($issue->id, $event->issue_id);
    }

    public function test_changes_default_to_empty_array(): void
    {
        [$owner, , , $issue] = $this->setupIssue();

        $event = RecordActivity::run(AuditEventType::IssueCreated, $issue, $owner);

        $this->assertSame([], $event->changes);
    }

    public function test_event_is_invisible_from_another_workspace(): void
    {
        [$owner, , , $issue] = $this->setupIssue();

        $event = RecordActivity::run(AuditEventType::IssueCreated, $issue, $owner);

        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Autre WS']);
        app(WorkspaceContext::class)->set($otherWorkspace);

        $this->assertSame(0, AuditEvent::query()->count());
        $this->assertNull(AuditEvent::query()->find($event->id));

        // Back on the owning workspace, the event is reachable again. The
        // setup itself journalled the issue creation: compare against the
        // event's own id rather than an absolute count.
        app(WorkspaceContext::class)->set($issue->workspace);
        $this->assertNotNull(AuditEvent::query()->find($event->id));
        $this->assertSame(1, AuditEvent::query()->whereKey($event->id)->count());
    }

    public function test_event_is_unreachable_without_workspace_context(): void
    {
        [$owner, , , $issue] = $this->setupIssue();

        $event = RecordActivity::run(AuditEventType::IssueCreated, $issue, $owner);

        app(WorkspaceContext::class)->set(null);

        $this->assertSame(0, AuditEvent::query()->count());
        $this->assertNull(AuditEvent::query()->find($event->id));
    }

    public function test_unsupported_subject_throws_logic_exception(): void
    {
        $this->setupIssue();

        $this->expectException(LogicException::class);

        RecordActivity::run(AuditEventType::IssueCreated, User::factory()->create());
    }

    public function test_issue_subject_relation_resolves_back_to_the_issue(): void
    {
        [$owner, , , $issue] = $this->setupIssue();

        $event = RecordActivity::run(AuditEventType::IssueUpdated, $issue, $owner);

        $this->assertInstanceOf(Issue::class, $event->issue);
        $this->assertSame($issue->id, $event->issue->id);
    }

    public function test_knowledge_page_relation_resolves_back_to_the_page(): void
    {
        Queue::fake();
        [$owner, , $project] = $this->setupIssue();

        $page = CreateKnowledgePage::run($project->workspace, $owner, [
            'title' => 'Conventions',
            'body' => 'Corps.',
            'project_id' => $project->id,
        ]);

        $event = RecordActivity::run(AuditEventType::KnowledgePageUpdated, $page, $owner);

        $this->assertInstanceOf(KnowledgePage::class, $event->knowledgePage);
        $this->assertSame($page->id, $event->knowledgePage->id);
    }
}
