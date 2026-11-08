<?php

namespace Tests\Unit;

use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class AuditEventTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Project}
     */
    private function setupProject(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        return [$owner, $project];
    }

    private function createEvent(?User $actor = null): AuditEvent
    {
        [$owner, $project] = $this->setupProject();

        return AuditEvent::create([
            'project_id' => $project->id,
            'actor_id' => ($actor ?? $owner)->id,
            'type' => AuditEventType::ProjectCreated,
            'changes' => ['name' => ['before' => null, 'after' => $project->name]],
        ]);
    }

    public function test_update_throws_logic_exception(): void
    {
        $event = $this->createEvent();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Audit events are append-only.');

        $event->update(['type' => AuditEventType::ProjectUpdated]);
    }

    public function test_delete_throws_logic_exception(): void
    {
        $event = $this->createEvent();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Audit events are append-only.');

        $event->delete();
    }

    public function test_force_delete_throws_logic_exception(): void
    {
        $event = $this->createEvent();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Audit events are append-only.');

        $event->forceDelete();
    }

    public function test_query_level_delete_throws_logic_exception(): void
    {
        $event = $this->createEvent();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Audit events are append-only.');

        AuditEvent::query()->whereKey($event->id)->delete();
    }

    public function test_event_row_survives_a_rejected_mutation(): void
    {
        $event = $this->createEvent();

        try {
            $event->update(['type' => AuditEventType::ProjectUpdated]);
        } catch (LogicException) {
            // expected: append-only guard
        }

        $this->assertDatabaseHas('audit_events', [
            'id' => $event->id,
            'type' => AuditEventType::ProjectCreated->value,
        ]);
    }

    public function test_type_is_cast_to_enum(): void
    {
        $event = $this->createEvent();

        $fresh = AuditEvent::query()->findOrFail($event->id);

        $this->assertInstanceOf(AuditEventType::class, $fresh->type);
        $this->assertSame(AuditEventType::ProjectCreated, $fresh->type);
    }

    public function test_changes_are_cast_to_array(): void
    {
        $event = $this->createEvent();

        $fresh = AuditEvent::query()->findOrFail($event->id);

        $this->assertIsArray($fresh->changes);
        $this->assertSame(['name' => ['before' => null, 'after' => 'Site vitrine']], $fresh->changes);
    }

    public function test_changes_accept_null(): void
    {
        [$owner, $project] = $this->setupProject();

        $event = AuditEvent::create([
            'project_id' => $project->id,
            'actor_id' => $owner->id,
            'type' => AuditEventType::ProjectArchived,
        ]);

        $this->assertNull(AuditEvent::query()->findOrFail($event->id)->changes);
    }

    public function test_enum_values_are_dotted_snake_case_strings(): void
    {
        foreach (AuditEventType::cases() as $case) {
            $this->assertMatchesRegularExpression('/^[a-z_]+\.[a-z_]+$/', $case->value);
        }
    }

    public function test_enum_covers_the_minimal_spec_coverage(): void
    {
        $expected = [
            'issue.created', 'issue.updated', 'issue.deleted', 'issue.status_changed',
            'issue.auto_completed',
            'issue.auto_completion_enabled', 'issue.auto_completion_disabled',
            'issue.priority_changed', 'issue.assigned', 'issue.unassigned',
            'issue.due_date_changed', 'issue.estimate_changed', 'issue.parent_set',
            'issue.parent_removed', 'issue.archived', 'issue.restored',
            'issue.knowledge_linked', 'issue.knowledge_unlinked',
            'comment.created', 'comment.updated', 'comment.deleted',
            'time_entry.created', 'time_entry.updated', 'time_entry.deleted',
            'project.created', 'project.updated', 'project.archived', 'project.unarchived',
            'project.deleted', 'knowledge_page.created', 'knowledge_page.updated',
            'knowledge_page.deleted', 'knowledge_page.restored',
            'milestone.created', 'milestone.updated', 'milestone.deleted',
            'milestone.closed', 'milestone.parent_set', 'milestone.parent_removed',
            'issue.milestone_set', 'issue.milestone_removed',
        ];

        $values = array_map(fn (AuditEventType $case) => $case->value, AuditEventType::cases());

        $this->assertSame($expected, $values);
    }

    public function test_model_does_not_use_soft_deletes(): void
    {
        $this->assertNotContains(
            SoftDeletes::class,
            class_uses_recursive(AuditEvent::class),
        );
    }

    public function test_identifier_is_a_ulid(): void
    {
        $event = $this->createEvent();

        $this->assertTrue(Str::isUlid($event->id));
    }

    public function test_actor_relation_resolves_the_user(): void
    {
        $event = $this->createEvent();

        $this->assertSame($event->actor_id, $event->actor?->id);
    }

    public function test_issue_subject_relation_resolves(): void
    {
        [$owner, $project] = $this->setupProject();

        $issue = Issue::factory()->create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
        ]);

        $event = AuditEvent::create([
            'issue_id' => $issue->id,
            'actor_id' => $owner->id,
            'type' => AuditEventType::IssueCreated,
        ]);

        $this->assertSame($issue->id, $event->issue?->id);
        $this->assertNull($event->project);
        $this->assertNull($event->knowledgePage);
    }

    public function test_expected_indexes_exist(): void
    {
        $indexes = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'audit_events'"))
            ->pluck('name');

        $this->assertTrue($indexes->contains('audit_events_workspace_id_issue_id_index'));
        $this->assertTrue($indexes->contains('audit_events_workspace_id_project_id_index'));
        $this->assertTrue($indexes->contains('audit_events_workspace_id_knowledge_page_id_index'));
        $this->assertTrue($indexes->contains('audit_events_workspace_id_milestone_id_index'));
        $this->assertTrue($indexes->contains('audit_events_workspace_id_type_created_at_index'));
    }
}
