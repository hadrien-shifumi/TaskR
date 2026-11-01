<?php

namespace Tests\Feature\Audit;

use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Knowledge\DeleteKnowledgePage;
use App\Actions\Knowledge\ForceDeleteKnowledgePage;
use App\Actions\Knowledge\RestoreKnowledgePage;
use App\Actions\Knowledge\RestoreKnowledgePageVersion;
use App\Actions\Knowledge\UpdateKnowledgePage;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class KnowledgeActivityTest extends TestCase
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

    public function test_create_knowledge_page_emits_knowledge_page_created(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Conventions',
            'body' => 'Corps initial',
            'project_id' => $project->id,
        ]);

        $event = AuditEvent::query()->where('type', AuditEventType::KnowledgePageCreated->value)->firstOrFail();

        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame($page->id, $event->knowledge_page_id);
        $this->assertNull($event->issue_id);
        $this->assertSame([
            'title' => ['before' => null, 'after' => 'Conventions'],
            'body' => ['before' => null, 'after' => 'Corps initial'],
        ], $event->changes);
    }

    public function test_update_knowledge_page_emits_knowledge_page_updated_with_the_diff(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();
        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Conventions',
            'body' => 'Corps initial',
        ]);

        UpdateKnowledgePage::run($page, $owner, ['title' => 'Conventions v2', 'body' => 'Corps revu']);

        $event = AuditEvent::query()->where('type', AuditEventType::KnowledgePageUpdated->value)->firstOrFail();

        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame($page->id, $event->knowledge_page_id);
        $this->assertSame([
            'title' => ['before' => 'Conventions', 'after' => 'Conventions v2'],
            'body' => ['before' => 'Corps initial', 'after' => 'Corps revu'],
        ], $event->changes);
    }

    public function test_update_knowledge_page_truncates_long_bodies_in_changes(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();
        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Conventions',
            'body' => str_repeat('a', 3000),
        ]);
        $newBody = str_repeat('b', 3000);

        UpdateKnowledgePage::run($page, $owner, ['title' => 'Conventions', 'body' => $newBody]);

        $event = AuditEvent::query()->where('type', AuditEventType::KnowledgePageUpdated->value)->firstOrFail();

        // The journal keeps the event light: the full history lives in the
        // version table, so bodies are truncated to ~2000 characters.
        $before = $event->changes['body']['before'];
        $after = $event->changes['body']['after'];

        $this->assertSame(str_repeat('a', 2000), mb_substr($before, 0, 2000));
        $this->assertTrue(mb_strlen($before) <= 2010);
        $this->assertSame(str_repeat('b', 2000), mb_substr($after, 0, 2000));
        $this->assertTrue(mb_strlen($after) <= 2010);
    }

    public function test_delete_knowledge_page_emits_knowledge_page_deleted_with_the_reason(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();
        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Conventions',
            'body' => 'Corps.',
        ]);

        DeleteKnowledgePage::run($page, $owner);

        $event = AuditEvent::query()->where('type', AuditEventType::KnowledgePageDeleted->value)->firstOrFail();

        $this->assertSame($owner->id, $event->actor_id);
        // Soft delete: the row survives in the trash, so the subject link holds.
        $this->assertSame($page->id, $event->knowledge_page_id);
        $this->assertSame('Conventions', $event->changes['title']['before']);
        $this->assertNull($event->changes['title']['after']);
        $this->assertSame('manual', $event->changes['reason']);
    }

    public function test_restore_knowledge_page_emits_knowledge_page_restored(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();
        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Conventions',
            'body' => 'Corps.',
        ]);
        DeleteKnowledgePage::run($page, $owner);

        RestoreKnowledgePage::run($page, $owner);

        $event = AuditEvent::query()->where('type', AuditEventType::KnowledgePageRestored->value)->firstOrFail();

        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame($page->id, $event->knowledge_page_id);
    }

    public function test_force_delete_knowledge_page_emits_deleted_with_the_definitive_inventory(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();
        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Conventions',
            'body' => 'Corps.',
        ]);
        // A second version exists: the initial one plus the update.
        UpdateKnowledgePage::run($page, $owner, ['title' => 'Conventions', 'body' => 'Corps revu']);
        DeleteKnowledgePage::run($page, $owner);

        ForceDeleteKnowledgePage::run($page, $owner);

        $deleted = AuditEvent::query()
            ->where('type', AuditEventType::KnowledgePageDeleted->value)
            ->orderByDesc('created_at')
            ->firstOrFail();

        // Hard delete: the definitive event is the last knowledge_page.deleted.
        $this->assertTrue($deleted->changes['definitive']);
        $this->assertSame('Conventions', $deleted->changes['title']);
        $this->assertSame('Corps revu', $deleted->changes['body']);
        $this->assertSame(2, $deleted->changes['versions_count']);
        $this->assertSame($owner->id, $deleted->actor_id);
        // The row is gone: the nullOnDelete FK clears the subject link.
        $this->assertNull($deleted->knowledge_page_id);
    }

    public function test_restore_knowledge_page_version_emits_knowledge_page_updated(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();
        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Conventions',
            'body' => 'Corps initial',
        ]);
        UpdateKnowledgePage::run($page, $owner, ['title' => 'Conventions v2', 'body' => 'Corps revu']);

        $firstVersion = $page->versions()->oldest('created_at')->firstOrFail();
        RestoreKnowledgePageVersion::run($page, $firstVersion, $owner);

        $event = AuditEvent::query()
            ->where('type', AuditEventType::KnowledgePageUpdated->value)
            ->orderByDesc('created_at')
            ->firstOrFail();

        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame($page->id, $event->knowledge_page_id);
        $this->assertSame($firstVersion->id, $event->changes['restored_version_id']);
        $this->assertSame(['before' => 'Conventions v2', 'after' => 'Conventions'], $event->changes['title']);
        $this->assertSame(['before' => 'Corps revu', 'after' => 'Corps initial'], $event->changes['body']);
    }
}
