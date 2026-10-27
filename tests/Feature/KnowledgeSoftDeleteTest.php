<?php

namespace Tests\Feature;

use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Knowledge\DeleteKnowledgePage;
use App\Actions\Knowledge\ListKnowledgePages;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\KnowledgePage;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class KnowledgeSoftDeleteTest extends TestCase
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

    /**
     * @return array{0: User, 1: Workspace, 2: User}
     */
    private function setupWorkspaceWithGuest(): array
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);

        return [$owner, $workspace, $guest];
    }

    public function test_soft_delete_sets_deleted_at_and_hides_from_default_queries(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page à supprimer',
            'body' => 'Corps.',
        ]);

        $page->delete();

        // Hidden from default queries.
        $this->assertNull(KnowledgePage::find($page->id));

        // Still present with trashed.
        $trashed = KnowledgePage::withTrashed()->find($page->id);
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
    }

    public function test_only_trashed_returns_only_soft_deleted_pages(): void
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

    public function test_with_trashed_returns_all_pages_including_deleted(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $active = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Active', 'body' => 'A.']);
        $deleted = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Supprimée', 'body' => 'S.']);

        $deleted->delete();

        $all = KnowledgePage::withTrashed()->get();

        $this->assertCount(2, $all);
        $this->assertEqualsCanonicalizing(
            [$active->id, $deleted->id],
            $all->pluck('id')->all(),
        );
    }

    public function test_restore_clears_deleted_at(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Page', 'body' => 'Corps.']);

        $page->delete();
        $this->assertNull(KnowledgePage::find($page->id));

        $page->restore();

        $restored = KnowledgePage::find($page->id);
        $this->assertNotNull($restored);
        $this->assertNull($restored->deleted_at);
    }

    public function test_force_delete_removes_page_definitely(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Page', 'body' => 'Corps.']);

        $page->delete();
        $page->forceDelete();

        $this->assertNull(KnowledgePage::withTrashed()->find($page->id));
        $this->assertDatabaseMissing('knowledge_pages', ['id' => $page->id]);
    }

    public function test_deleted_by_and_reason_are_fillable(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Page', 'body' => 'Corps.']);

        $page->update([
            'deleted_by_id' => $owner->id,
            'deleted_reason' => 'Contenu obsolète',
        ]);
        $page->delete();

        $trashed = KnowledgePage::withTrashed()->find($page->id);
        $this->assertNotNull($trashed);
        $this->assertSame($owner->id, (int) $trashed->deleted_by_id);
        $this->assertSame('Contenu obsolète', $trashed->deleted_reason);
    }

    public function test_workspace_scope_still_applies_to_trashed_pages(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Page', 'body' => 'Corps.']);
        $page->delete();

        // Switch to another workspace.
        $stranger = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($stranger, ['name' => 'Autre']);
        app(WorkspaceContext::class)->set($otherWorkspace);

        // The trashed page of workspace A is not visible from workspace B,
        // even with withTrashed (the WorkspaceScope still applies).
        $this->assertNull(KnowledgePage::withTrashed()->find($page->id));
        $this->assertSame(0, KnowledgePage::withTrashed()->count());
    }

    public function test_children_relationship_works_with_soft_deletes(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $parent = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Parent', 'body' => 'P.']);
        $child = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Enfant',
            'body' => 'E.',
            'parent_id' => $parent->id,
        ]);

        $parent->delete();

        // The children relation on the (trashed) parent still resolves.
        $trashedParent = KnowledgePage::withTrashed()->find($parent->id);
        $this->assertNotNull($trashedParent);
        $this->assertSame(1, $trashedParent->children()->count());
        $this->assertSame($child->id, $trashedParent->children()->first()->id);
    }

    public function test_cross_workspace_isolation_on_trashed_pages(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Page', 'body' => 'Corps.']);
        $page->delete();

        // A user from another workspace cannot see the trashed page,
        // even bypassing the workspace scope — the page belongs to WS A.
        $stranger = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($stranger, ['name' => 'Autre']);
        app(WorkspaceContext::class)->set($otherWorkspace);

        // Scoped query: nothing from workspace A is visible.
        $this->assertNull(KnowledgePage::withTrashed()->find($page->id));

        // Even without the workspace scope, the page is in workspace A,
        // not in workspace B — it exists but belongs to another tenant.
        $found = KnowledgePage::withoutWorkspaceScope()
            ->withTrashed()
            ->find($page->id);
        $this->assertNotNull($found);
        $this->assertSame($workspace->id, $found->workspace_id);
        $this->assertNotSame($otherWorkspace->id, $found->workspace_id);
    }

    public function test_delete_action_soft_deletes_with_metadata(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page à jeter',
            'body' => 'Contenu.',
        ]);

        DeleteKnowledgePage::run($page, $owner);

        $trashed = KnowledgePage::withTrashed()->findOrFail($page->id);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame($owner->id, (int) $trashed->deleted_by_id);
        $this->assertSame('manual', $trashed->deleted_reason);
    }

    public function test_delete_action_accepts_custom_reason(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page cascade',
            'body' => 'Contenu.',
        ]);

        DeleteKnowledgePage::run($page, $owner, 'cascade_project');

        $trashed = KnowledgePage::withTrashed()->findOrFail($page->id);
        $this->assertSame('cascade_project', $trashed->deleted_reason);
    }

    public function test_delete_action_without_user_leaves_deleted_by_null(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page interne',
            'body' => 'Contenu.',
        ]);

        // Trusted internal run (no user) — used by cascade operations.
        DeleteKnowledgePage::run($page, null);

        $trashed = KnowledgePage::withTrashed()->findOrFail($page->id);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertNull($trashed->deleted_by_id);
        $this->assertSame('manual', $trashed->deleted_reason);
    }

    public function test_delete_action_still_blocks_pages_with_children(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $parent = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Parent',
            'body' => 'Contenu.',
        ]);
        CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Enfant',
            'body' => 'Contenu.',
            'parent_id' => $parent->id,
        ]);

        $this->actingAs($owner)
            ->delete("/knowledge/{$parent->id}")
            ->assertStatus(409);

        // Parent is NOT trashed.
        $this->assertNull(KnowledgePage::withTrashed()->findOrFail($parent->id)->deleted_at);
    }

    public function test_trashed_page_disappears_from_list_action(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page visible',
            'body' => 'Contenu.',
        ]);

        DeleteKnowledgePage::run($page, $owner);

        $pages = ListKnowledgePages::run($workspace, $owner);
        $this->assertCount(0, $pages);
    }

    public function test_guest_cannot_delete_transverse_page(): void
    {
        Queue::fake();
        [$owner, $workspace, $guest] = $this->setupWorkspaceWithGuest();

        $transversePage = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Doc transverse',
            'body' => 'Contenu.',
        ]);

        $this->actingAs($guest)
            ->delete("/knowledge/{$transversePage->id}")
            ->assertNotFound();
    }
}
