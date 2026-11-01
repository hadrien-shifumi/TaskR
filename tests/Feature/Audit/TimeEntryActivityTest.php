<?php

namespace Tests\Feature\Audit;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\TimeTracking\CreateTimeEntry;
use App\Actions\TimeTracking\DeleteTimeEntry;
use App\Actions\TimeTracking\UpdateTimeEntry;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Issue;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimeEntryActivityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Issue}
     */
    private function setupIssue(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);
        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil'], $owner);

        return [$owner, $issue];
    }

    public function test_create_time_entry_emits_time_entry_created_on_the_parent_issue(): void
    {
        [$owner, $issue] = $this->setupIssue();

        $entry = CreateTimeEntry::run($issue, $owner, [
            'hours' => 2.5,
            'worked_at' => '2030-03-03',
            'note' => 'Analyse',
        ]);

        $event = AuditEvent::query()->where('type', AuditEventType::TimeEntryCreated->value)->firstOrFail();

        // A time entry is not an audit subject: the journal targets the
        // parent issue and carries the entry details in changes.
        $this->assertSame($issue->id, $event->issue_id);
        $this->assertSame($issue->project_id, $event->project_id);
        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame($entry->id, $event->changes['time_entry_id']);
        $this->assertSame(['before' => null, 'after' => 2.5], $event->changes['hours']);
        $this->assertSame(['before' => null, 'after' => '2030-03-03'], $event->changes['worked_at']);
        $this->assertSame(['before' => null, 'after' => 'Analyse'], $event->changes['note']);
    }

    public function test_update_time_entry_emits_time_entry_updated_with_the_diff(): void
    {
        [$owner, $issue] = $this->setupIssue();
        $entry = CreateTimeEntry::run($issue, $owner, ['hours' => 1.5, 'note' => 'Analyse']);
        $beforeWorkedAt = $entry->worked_at->toDateString();

        UpdateTimeEntry::run($entry, [
            'hours' => 3,
            'worked_at' => '2030-03-04',
            'note' => 'Analyse et correction',
        ], $owner);

        $event = AuditEvent::query()->where('type', AuditEventType::TimeEntryUpdated->value)->firstOrFail();

        $this->assertSame($issue->id, $event->issue_id);
        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame($entry->id, $event->changes['time_entry_id']);
        $this->assertSame(['before' => 1.5, 'after' => 3], $event->changes['hours']);
        $this->assertSame(['before' => $beforeWorkedAt, 'after' => '2030-03-04'], $event->changes['worked_at']);
        $this->assertSame(['before' => 'Analyse', 'after' => 'Analyse et correction'], $event->changes['note']);
    }

    public function test_delete_time_entry_emits_time_entry_deleted_with_the_snapshot(): void
    {
        [$owner, $issue] = $this->setupIssue();
        $entry = CreateTimeEntry::run($issue, $owner, ['hours' => 2, 'note' => 'Analyse']);

        DeleteTimeEntry::run($entry, $owner);

        $event = AuditEvent::query()->where('type', AuditEventType::TimeEntryDeleted->value)->firstOrFail();

        $this->assertSame($issue->id, $event->issue_id);
        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame($entry->id, $event->changes['time_entry_id']);
        $this->assertSame(['before' => 2, 'after' => null], $event->changes['hours']);
        $this->assertSame(['before' => $entry->worked_at->toDateString(), 'after' => null], $event->changes['worked_at']);
        $this->assertSame(['before' => 'Analyse', 'after' => null], $event->changes['note']);
    }
}
