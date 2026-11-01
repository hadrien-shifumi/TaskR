<?php

namespace Tests\Feature\Audit;

use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Projects\ArchiveProject;
use App\Actions\Projects\CreateProject;
use App\Actions\Projects\DeleteProject;
use App\Actions\Projects\UpdateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProjectActivityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace];
    }

    public function test_create_project_emits_project_created(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $project = CreateProject::run($workspace, $owner, [
            'name' => 'Site vitrine',
            'description' => 'Refonte complete',
            'target_date' => '2030-01-01',
        ]);

        $event = AuditEvent::query()->where('type', AuditEventType::ProjectCreated->value)->firstOrFail();

        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame($project->id, $event->project_id);
        $this->assertNull($event->issue_id);
        $this->assertSame([
            'name' => ['before' => null, 'after' => 'Site vitrine'],
            'description' => ['before' => null, 'after' => 'Refonte complete'],
            'target_date' => ['before' => null, 'after' => '2030-01-01'],
        ], $event->changes);
    }

    public function test_update_project_emits_project_updated_with_the_diff(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $project = CreateProject::run($workspace, $owner, [
            'name' => 'Site vitrine',
            'description' => 'Refonte complete',
        ]);

        UpdateProject::run($project, [
            'name' => 'Nouveau nom',
            'description' => 'Pilote reduit',
            'target_date' => '2030-05-05',
        ], $owner);

        $event = AuditEvent::query()->where('type', AuditEventType::ProjectUpdated->value)->firstOrFail();

        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame($project->id, $event->project_id);
        $this->assertSame([
            'name' => ['before' => 'Site vitrine', 'after' => 'Nouveau nom'],
            'description' => ['before' => 'Refonte complete', 'after' => 'Pilote reduit'],
            'target_date' => ['before' => null, 'after' => '2030-05-05'],
        ], $event->changes);
    }

    public function test_update_project_without_changes_emits_nothing(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Stable']);

        $countBefore = AuditEvent::query()->count();

        UpdateProject::run($project, ['name' => 'Stable'], $owner);

        $this->assertSame($countBefore, AuditEvent::query()->count());
    }

    public function test_archive_project_emits_archived_then_unarchived(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        ArchiveProject::run($project, $owner);

        $archived = AuditEvent::query()->where('type', AuditEventType::ProjectArchived->value)->firstOrFail();
        $this->assertSame($owner->id, $archived->actor_id);
        $this->assertSame($project->id, $archived->project_id);

        ArchiveProject::run($project->refresh(), $owner);

        $unarchived = AuditEvent::query()->where('type', AuditEventType::ProjectUnarchived->value)->firstOrFail();
        $this->assertSame($project->id, $unarchived->project_id);
        $this->assertSame($owner->id, $unarchived->actor_id);
    }

    public function test_delete_project_emits_project_deleted_with_the_inventory(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();
        $project = CreateProject::run($workspace, $owner, [
            'name' => 'Site vitrine',
            'description' => 'Refonte complete',
        ]);
        CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Conventions',
            'body' => 'Corps.',
            'project_id' => $project->id,
        ]);

        DeleteProject::run($project, $owner);

        $event = AuditEvent::query()->where('type', AuditEventType::ProjectDeleted->value)->firstOrFail();

        $this->assertSame($owner->id, $event->actor_id);
        // The nullOnDelete FK clears the subject link once the row is gone:
        // the event stays readable through its workspace and its inventory.
        $this->assertNull($event->project_id);
        $this->assertSame('Site vitrine', $event->changes['name']);
        $this->assertSame('Refonte complete', $event->changes['description']);
        $this->assertSame(1, $event->changes['knowledge_pages_trashed']);
    }

    public function test_project_events_are_isolated_per_workspace(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Autre WS']);
        app(WorkspaceContext::class)->set($otherWorkspace);

        $this->assertSame(0, AuditEvent::query()->where('type', AuditEventType::ProjectCreated->value)->count());
    }
}
