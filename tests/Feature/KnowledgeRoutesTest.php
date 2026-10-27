<?php

namespace Tests\Feature;

use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Knowledge\UpdateKnowledgePage;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\KnowledgePage;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class KnowledgeRoutesTest extends TestCase
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

    // ── Corbeille — routes ───────────────────────────────────────────

    public function test_trash_route_returns_inertia_page(): void
    {
        Queue::fake();
        [$owner] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->get('/knowledge/trash')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Page component created in T14 — skip file existence check.
                ->component('Knowledge/Trash', false)
                ->has('trashedPages')
                ->has('pages')
                ->has('projects'));
    }

    public function test_trash_route_not_captured_by_page_route(): void
    {
        Queue::fake();
        [$owner] = $this->setupWorkspace();

        // If /knowledge/trash were declared after /knowledge/{page},
        // the {page} binding would try to find a page with id "trash" → 404.
        $this->actingAs($owner)
            ->get('/knowledge/trash')
            ->assertOk();
    }

    public function test_trash_route_lists_trashed_pages(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $active = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Active page', 'body' => 'Body.',
        ]);
        $trashed = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Trashed page', 'body' => 'Body.',
        ]);
        $trashed->delete();

        $this->actingAs($owner)
            ->get('/knowledge/trash')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Page component created in T14 — skip file existence check.
                ->component('Knowledge/Trash', false)
                ->has('trashedPages', 1)
                ->where('trashedPages.0.id', $trashed->id)
                ->where('trashedPages.0.title', 'Trashed page'));
    }

    public function test_restore_route_restores_trashed_page(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page', 'body' => 'Body.',
        ]);
        $page->delete();

        $this->assertNull(KnowledgePage::find($page->id));

        $this->actingAs($owner)
            ->post("/knowledge/{$page->id}/restore")
            ->assertRedirect();

        $this->assertNotNull(KnowledgePage::find($page->id));
        $this->assertNull(KnowledgePage::find($page->id)->deleted_at);
    }

    public function test_force_delete_route_removes_page(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page', 'body' => 'Body.',
        ]);
        $page->delete();

        $this->actingAs($owner)
            ->delete("/knowledge/{$page->id}/force")
            ->assertRedirect();

        $this->assertNull(KnowledgePage::withTrashed()->find($page->id));
        $this->assertDatabaseMissing('knowledge_pages', ['id' => $page->id]);
    }

    // ── Corbeille — 404 cases ────────────────────────────────────────

    public function test_restore_404_for_non_trashed_page(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Active page', 'body' => 'Body.',
        ]);

        $this->actingAs($owner)
            ->post("/knowledge/{$page->id}/restore")
            ->assertNotFound();
    }

    public function test_force_delete_404_for_non_trashed_page(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Active page', 'body' => 'Body.',
        ]);

        $this->actingAs($owner)
            ->delete("/knowledge/{$page->id}/force")
            ->assertNotFound();
    }

    public function test_restore_404_for_nonexistent_page(): void
    {
        Queue::fake();
        [$owner] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->post('/knowledge/01h0000000000000000000000000/restore')
            ->assertNotFound();
    }

    public function test_force_delete_404_for_nonexistent_page(): void
    {
        Queue::fake();
        [$owner] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->delete('/knowledge/01h0000000000000000000000000/force')
            ->assertNotFound();
    }

    public function test_restore_cross_workspace_404(): void
    {
        Queue::fake();
        $owner1 = User::factory()->create();
        $workspace1 = CreateWorkspace::run($owner1, ['name' => 'WS1']);
        app(WorkspaceContext::class)->set($workspace1);
        $page = CreateKnowledgePage::run($workspace1, $owner1, [
            'title' => 'Page WS1', 'body' => 'Body.',
        ]);
        $page->delete();

        $owner2 = User::factory()->create();
        CreateWorkspace::run($owner2, ['name' => 'WS2']);

        $this->actingAs($owner2)
            ->post("/knowledge/{$page->id}/restore")
            ->assertNotFound();
    }

    public function test_force_delete_cross_workspace_404(): void
    {
        Queue::fake();
        $owner1 = User::factory()->create();
        $workspace1 = CreateWorkspace::run($owner1, ['name' => 'WS1']);
        app(WorkspaceContext::class)->set($workspace1);
        $page = CreateKnowledgePage::run($workspace1, $owner1, [
            'title' => 'Page WS1', 'body' => 'Body.',
        ]);
        $page->delete();

        $owner2 = User::factory()->create();
        CreateWorkspace::run($owner2, ['name' => 'WS2']);

        $this->actingAs($owner2)
            ->delete("/knowledge/{$page->id}/force")
            ->assertNotFound();
    }

    // ── Versionnage — routes ─────────────────────────────────────────

    public function test_versions_route_returns_inertia_page(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page', 'body' => 'Body.',
        ]);

        $this->actingAs($owner)
            ->get("/knowledge/{$page->id}/versions")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Page component created in T15 — skip file existence check.
                ->component('Knowledge/Versions', false)
                ->has('page')
                ->has('versions')
                ->has('pages')
                ->has('projects'));
    }

    public function test_versions_route_lists_versions(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'V1', 'body' => 'Body V1.',
        ]);

        // Create a v2.
        UpdateKnowledgePage::run($page, $owner, [
            'title' => 'V2', 'body' => 'Body V2.',
        ]);

        $this->actingAs($owner)
            ->get("/knowledge/{$page->id}/versions")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Page component created in T15 — skip file existence check.
                ->component('Knowledge/Versions', false)
                ->has('versions', 2));
    }

    public function test_restore_version_route_restores_content(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'V1', 'body' => 'Body V1.',
        ]);

        // Create a v2.
        UpdateKnowledgePage::run($page, $owner, [
            'title' => 'V2', 'body' => 'Body V2.',
        ]);

        $this->assertSame('V2', $page->fresh()->title);

        // Restore v1.
        $v1 = $page->versions()->orderBy('created_at')->firstOrFail();

        $this->actingAs($owner)
            ->post("/knowledge/{$page->id}/versions/{$v1->id}/restore")
            ->assertRedirect();

        $this->assertSame('V1', $page->fresh()->title);
        $this->assertSame('Body V1.', $page->fresh()->body);
    }

    public function test_restore_version_404_for_version_of_another_page(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page1 = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page 1', 'body' => 'Body 1.',
        ]);
        $page2 = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page 2', 'body' => 'Body 2.',
        ]);

        $versionOfPage2 = $page2->versions()->firstOrFail();

        $this->actingAs($owner)
            ->post("/knowledge/{$page1->id}/versions/{$versionOfPage2->id}/restore")
            ->assertNotFound();
    }

    public function test_versions_route_404_for_nonexistent_page(): void
    {
        Queue::fake();
        [$owner] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->get('/knowledge/01h0000000000000000000000000/versions')
            ->assertNotFound();
    }

    // ── Auth required ────────────────────────────────────────────────

    public function test_trash_route_requires_auth(): void
    {
        $this->get('/knowledge/trash')
            ->assertRedirect('/login');
    }

    public function test_versions_route_requires_auth(): void
    {
        $this->get('/knowledge/01h0000000000000000000000000/versions')
            ->assertRedirect('/login');
    }

    public function test_restore_route_requires_auth(): void
    {
        $this->post('/knowledge/01h0000000000000000000000000/restore')
            ->assertRedirect('/login');
    }

    public function test_force_delete_route_requires_auth(): void
    {
        $this->delete('/knowledge/01h0000000000000000000000000/force')
            ->assertRedirect('/login');
    }
}
