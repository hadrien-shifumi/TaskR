<?php

namespace Tests\Unit;

use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\KnowledgePage;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class KnowledgePageSoftDeleteTest extends TestCase
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

    public function test_model_uses_soft_deletes_trait(): void
    {
        $this->assertContains(SoftDeletes::class, class_uses_recursive(KnowledgePage::class));
    }

    public function test_fillable_includes_soft_delete_columns(): void
    {
        $page = new KnowledgePage;

        $this->assertContains('deleted_by_id', $page->getFillable());
        $this->assertContains('deleted_reason', $page->getFillable());
    }

    public function test_casts_include_deleted_at(): void
    {
        $page = new KnowledgePage;
        $casts = $page->getCasts();

        $this->assertArrayHasKey('deleted_at', $casts);
        $this->assertSame('datetime', $casts['deleted_at']);
    }

    public function test_deleted_by_relation_returns_user(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Page', 'body' => 'Corps.']);

        $relation = $page->deletedBy();

        $this->assertInstanceOf(BelongsTo::class, $relation);
        $this->assertSame('deleted_by_id', $relation->getForeignKeyName());
        $this->assertSame(User::class, $relation->getRelated()::class);
    }

    public function test_scope_only_trashed_filters_correctly(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $active = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Active', 'body' => 'A.']);
        $deleted = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Supprimée', 'body' => 'S.']);

        $deleted->delete();

        $trashed = KnowledgePage::onlyTrashed()->get();

        $this->assertCount(1, $trashed);
        $this->assertSame($deleted->id, $trashed->first()->id);
    }

    public function test_scope_trashed_with_reason_filters_correctly(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $pageA = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Page A', 'body' => 'A.']);
        $pageB = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Page B', 'body' => 'B.']);

        $pageA->update(['deleted_reason' => 'Obsolète']);
        $pageA->delete();

        $pageB->update(['deleted_reason' => 'Doublon']);
        $pageB->delete();

        $trashed = KnowledgePage::trashedWithReason('Obsolète')->get();

        $this->assertCount(1, $trashed);
        $this->assertSame($pageA->id, $trashed->first()->id);
    }

    public function test_trashed_page_reports_trashed_state(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Page', 'body' => 'Corps.']);

        $this->assertFalse($page->trashed());

        $page->delete();

        $this->assertTrue($page->trashed());
    }

    public function test_deleted_by_relation_resolves_user(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Page', 'body' => 'Corps.']);
        $page->update(['deleted_by_id' => $owner->id]);
        $page->delete();

        $trashed = KnowledgePage::withTrashed()->find($page->id);
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deletedBy);
        $this->assertSame($owner->id, $trashed->deletedBy->id);
    }
}
