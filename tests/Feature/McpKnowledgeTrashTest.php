<?php

namespace Tests\Feature;

use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Jobs\VectorizeKnowledgePage;
use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\DeleteKnowledge;
use App\Mcp\Tools\ForceDeleteKnowledge;
use App\Mcp\Tools\ListKnowledgeTrash;
use App\Mcp\Tools\RestoreKnowledge;
use App\Models\KnowledgePage;
use App\Models\Membership;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class McpKnowledgeTrashTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TestResponse::macro('result', function (): array {
            /** @var TestResponse $this */
            return $this->response->toArray()['result'] ?? [];
        });

        TestResponse::macro('payload', function (): array {
            /** @var TestResponse $this */
            return $this->response->toArray()['result']['structuredContent'] ?? [];
        });
    }

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

    // ─── Catalogue ──────────────────────────────────────────────────────

    public function test_trash_tools_are_registered(): void
    {
        TaskrServer::tools()->assertRegistered([
            ListKnowledgeTrash::class,
            RestoreKnowledge::class,
            ForceDeleteKnowledge::class,
        ]);
    }

    // ─── delete_knowledge (vérification soft delete) ────────────────────

    public function test_mcp_delete_moves_to_trash(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Page à supprimer',
            'body' => 'Corps.',
        ]);

        $result = TaskrServer::actingAs($owner)
            ->tool(DeleteKnowledge::class, [
                'workspace_id' => $workspace->id,
                'page_id' => $page->id,
            ])
            ->assertOk()->payload();

        $this->assertTrue($result['deleted']);
        $this->assertSame($page->id, $result['pageId']);

        // AgentTool clears the workspace context after each call.
        app(WorkspaceContext::class)->set($workspace);

        // Soft delete: hidden from default queries, row remains with metadata.
        $this->assertNull(KnowledgePage::find($page->id));
        $trashed = KnowledgePage::withTrashed()->findOrFail($page->id);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame('manual', $trashed->deleted_reason);
        $this->assertSame($owner->id, (int) $trashed->deleted_by_id);
    }

    // ─── list_knowledge_trash ───────────────────────────────────────────

    public function test_mcp_list_trash_returns_trashed_pages(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $page1 = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Page 1',
            'body' => 'Corps 1.',
        ]);
        $page2 = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Page 2',
            'body' => 'Corps 2.',
        ]);
        $page3 = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Page active',
            'body' => 'Corps.',
        ]);

        // Mettre 2 pages en corbeille.
        $page1->forceFill(['deleted_by_id' => $owner->id, 'deleted_reason' => 'manual'])->save();
        $page1->delete();
        $page2->forceFill(['deleted_by_id' => $owner->id, 'deleted_reason' => 'manual'])->save();
        $page2->delete();

        $result = TaskrServer::actingAs($owner)
            ->tool(ListKnowledgeTrash::class, [
                'workspace_id' => $workspace->id,
            ])
            ->assertOk()->payload();

        $this->assertSame(2, $result['count']);
        $this->assertCount(2, $result['trashedPages']);
        $ids = array_column($result['trashedPages'], 'id');
        $this->assertContains($page1->id, $ids);
        $this->assertContains($page2->id, $ids);
        $this->assertNotContains($page3->id, $ids);
    }

    public function test_mcp_list_trash_includes_metadata(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $page = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Page metadata',
            'body' => 'Corps.',
        ]);
        $page->forceFill([
            'deleted_by_id' => $owner->id,
            'deleted_reason' => 'Obsolète',
        ])->save();
        $page->delete();

        $result = TaskrServer::actingAs($owner)
            ->tool(ListKnowledgeTrash::class, [
                'workspace_id' => $workspace->id,
            ])
            ->assertOk()->payload();

        $this->assertSame(1, $result['count']);
        $trashed = $result['trashedPages'][0];
        $this->assertSame($page->id, $trashed['id']);
        $this->assertSame('Page metadata', $trashed['title']);
        $this->assertSame($owner->name, $trashed['deletedBy']);
        $this->assertSame('Obsolète', $trashed['deletedReason']);
        $this->assertNotNull($trashed['deletedAt']);
    }

    public function test_mcp_list_trash_empty_when_no_trashed_pages(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Page active',
            'body' => 'Corps.',
        ]);

        $result = TaskrServer::actingAs($owner)
            ->tool(ListKnowledgeTrash::class, [
                'workspace_id' => $workspace->id,
            ])
            ->assertOk()->payload();

        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['trashedPages']);
    }

    public function test_mcp_list_trash_guest_scope(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $inScope = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $outOfScope = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $inScopePage = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Page client',
            'body' => 'Corps.',
            'project_id' => $inScope->id,
        ]);
        $outOfScopePage = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Page interne',
            'body' => 'Corps.',
            'project_id' => $outOfScope->id,
        ]);
        $transversePage = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Page transverse',
            'body' => 'Corps.',
        ]);

        // Soft-delete all three pages.
        foreach ([$inScopePage, $outOfScopePage, $transversePage] as $page) {
            $page->forceFill(['deleted_by_id' => $owner->id, 'deleted_reason' => 'manual'])->save();
            $page->delete();
        }

        // Guest scoped to Alpha project only.
        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);
        ProjectMember::create([
            'project_id' => $inScope->id,
            'user_id' => $guest->id,
            'role' => 'member',
        ]);

        $result = TaskrServer::actingAs($guest)
            ->tool(ListKnowledgeTrash::class, [
                'workspace_id' => $workspace->id,
            ])
            ->assertOk()->payload();

        // Guest only sees trashed pages of their project scope.
        $this->assertSame(1, $result['count']);
        $ids = array_column($result['trashedPages'], 'id');
        $this->assertContains($inScopePage->id, $ids);
        $this->assertNotContains($outOfScopePage->id, $ids);
        $this->assertNotContains($transversePage->id, $ids);
    }

    // ─── restore_knowledge ──────────────────────────────────────────────

    public function test_mcp_restore_brings_page_back(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Page à restaurer',
            'body' => 'Corps.',
        ]);
        $page->forceFill(['deleted_by_id' => $owner->id, 'deleted_reason' => 'manual'])->save();
        $page->delete();

        $result = TaskrServer::actingAs($owner)
            ->tool(RestoreKnowledge::class, [
                'workspace_id' => $workspace->id,
                'page_id' => $page->id,
            ])
            ->assertOk()->payload();

        $this->assertTrue($result['restored']);
        $this->assertSame($page->id, $result['pageId']);
        $this->assertSame('Page à restaurer', $result['title']);

        // AgentTool clears the workspace context after each call.
        app(WorkspaceContext::class)->set($workspace);

        $restored = KnowledgePage::findOrFail($page->id);
        $this->assertNull($restored->deleted_at);
        $this->assertNull($restored->deleted_by_id);
        $this->assertNull($restored->deleted_reason);
    }

    public function test_mcp_restore_fails_for_non_trashed_page(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $page = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Page active',
            'body' => 'Corps.',
        ]);

        TaskrServer::actingAs($owner)
            ->tool(RestoreKnowledge::class, [
                'workspace_id' => $workspace->id,
                'page_id' => $page->id,
            ])
            ->assertHasErrors(['not in the trash']);

        // Page is untouched.
        $this->assertDatabaseHas('knowledge_pages', [
            'id' => $page->id,
            'deleted_at' => null,
        ]);
    }

    public function test_mcp_restore_triggers_revectorization(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Page',
            'body' => 'Corps.',
        ]);
        $page->forceFill(['deleted_by_id' => $owner->id, 'deleted_reason' => 'manual'])->save();
        $page->delete();

        TaskrServer::actingAs($owner)
            ->tool(RestoreKnowledge::class, [
                'workspace_id' => $workspace->id,
                'page_id' => $page->id,
            ])
            ->assertOk();

        Queue::assertPushed(VectorizeKnowledgePage::class);
    }

    // ─── force_delete_knowledge ─────────────────────────────────────────

    public function test_mcp_force_delete_removes_definitely(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $page = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Page à supprimer définitivement',
            'body' => 'Corps.',
        ]);
        $page->forceFill(['deleted_by_id' => $owner->id, 'deleted_reason' => 'manual'])->save();
        $page->delete();

        $result = TaskrServer::actingAs($owner)
            ->tool(ForceDeleteKnowledge::class, [
                'workspace_id' => $workspace->id,
                'page_id' => $page->id,
            ])
            ->assertOk()->payload();

        $this->assertTrue($result['deleted']);
        $this->assertSame($page->id, $result['pageId']);

        // Hard delete: row is gone, even with withTrashed.
        $this->assertNull(KnowledgePage::withTrashed()->find($page->id));
        $this->assertDatabaseMissing('knowledge_pages', ['id' => $page->id]);
    }

    public function test_mcp_force_delete_fails_for_non_trashed_page(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $page = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Page active',
            'body' => 'Corps.',
        ]);

        TaskrServer::actingAs($owner)
            ->tool(ForceDeleteKnowledge::class, [
                'workspace_id' => $workspace->id,
                'page_id' => $page->id,
            ])
            ->assertHasErrors(['not in the trash']);

        // Page is untouched.
        $this->assertDatabaseHas('knowledge_pages', ['id' => $page->id]);
    }

    // ─── Cross-workspace isolation ──────────────────────────────────────

    public function test_mcp_trash_tools_cross_workspace_isolation(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        // Page in workspace A, soft-deleted.
        $page = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Page secrète',
            'body' => 'Confidentiel.',
        ]);
        $page->forceFill(['deleted_by_id' => $owner->id, 'deleted_reason' => 'manual'])->save();
        $page->delete();

        // Workspace B with a different owner.
        $stranger = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($stranger, ['name' => 'Other']);

        // Stranger tries to restore the page from workspace A using workspace B's ID.
        // WorkspaceScope filters by workspace B → page not found → not_found error.
        $result = TaskrServer::actingAs($stranger)
            ->tool(RestoreKnowledge::class, [
                'workspace_id' => $otherWorkspace->id,
                'page_id' => $page->id,
            ])
            ->assertHasErrors()
            ->payload();

        $this->assertSame('not_found', $result['error']);

        // Stranger tries to force-delete the same page → same isolation.
        $result = TaskrServer::actingAs($stranger)
            ->tool(ForceDeleteKnowledge::class, [
                'workspace_id' => $otherWorkspace->id,
                'page_id' => $page->id,
            ])
            ->assertHasErrors()
            ->payload();

        $this->assertSame('not_found', $result['error']);

        // Stranger cannot list workspace A's trash (not a member).
        TaskrServer::actingAs($stranger)
            ->tool(ListKnowledgeTrash::class, [
                'workspace_id' => $workspace->id,
            ])
            ->assertHasErrors(['not a member']);

        // The page is untouched.
        $trashed = KnowledgePage::withoutWorkspaceScope()
            ->withTrashed()
            ->findOrFail($page->id);
        $this->assertNotNull($trashed->deleted_at);
    }
}
