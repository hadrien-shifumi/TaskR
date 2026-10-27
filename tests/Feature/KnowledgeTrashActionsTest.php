<?php

namespace Tests\Feature;

use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Knowledge\ForceDeleteKnowledgePage;
use App\Actions\Knowledge\ListTrashedKnowledgePages;
use App\Actions\Knowledge\RestoreKnowledgePage;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Jobs\VectorizeKnowledgePage;
use App\Models\KnowledgePage;
use App\Models\Membership;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class KnowledgeTrashActionsTest extends TestCase
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
     * Guest scoped to one project. Trashed pages: in-scope project page,
     * out-of-scope project page, transverse page.
     *
     * @return array{0: User, 1: Workspace, 2: KnowledgePage, 3: KnowledgePage, 4: KnowledgePage, 5: User}
     */
    private function setupScopedGuestWithTrashedPages(): array
    {
        Queue::fake();

        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $inScope = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $outOfScope = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $inScopePage = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page client', 'body' => 'Corps', 'project_id' => $inScope->id,
        ]);
        $outOfScopePage = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page interne', 'body' => 'Corps', 'project_id' => $outOfScope->id,
        ]);
        $transversePage = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page transverse', 'body' => 'Corps',
        ]);

        // Soft-delete all three pages with metadata.
        // Note: save() before delete() — SoftDeletes::runSoftDelete() only
        // updates deleted_at/updated_at columns, so deleted_by_id and
        // deleted_reason must be persisted first.
        $inScopePage->forceFill(['deleted_by_id' => $owner->id, 'deleted_reason' => 'Obsolète'])->save();
        $inScopePage->delete();
        $outOfScopePage->forceFill(['deleted_by_id' => $owner->id, 'deleted_reason' => 'Obsolète'])->save();
        $outOfScopePage->delete();
        $transversePage->forceFill(['deleted_by_id' => $owner->id, 'deleted_reason' => 'Obsolète'])->save();
        $transversePage->delete();

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

        return [$owner, $workspace, $inScopePage, $outOfScopePage, $transversePage, $guest];
    }

    // ── RestoreKnowledgePage ─────────────────────────────────────────

    public function test_restore_brings_page_back_to_default_queries(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page à restaurer', 'body' => 'Corps.',
        ]);
        $page->delete();

        $this->assertNull(KnowledgePage::find($page->id));

        $trashedPage = KnowledgePage::withTrashed()->findOrFail($page->id);
        $restored = RestoreKnowledgePage::run($trashedPage, $owner);

        $this->assertNotNull(KnowledgePage::find($page->id));
        $this->assertNull($restored->deleted_at);
    }

    public function test_restore_clears_deleted_metadata(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page', 'body' => 'Corps.',
        ]);
        $page->forceFill([
            'deleted_by_id' => $owner->id,
            'deleted_reason' => 'Contenu obsolète',
        ])->save();
        $page->delete();

        $trashedPage = KnowledgePage::withTrashed()->findOrFail($page->id);
        $restored = RestoreKnowledgePage::run($trashedPage, $owner);

        $this->assertNull($restored->deleted_by_id);
        $this->assertNull($restored->deleted_reason);
    }

    public function test_restore_triggers_revectorization(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page', 'body' => 'Corps.',
        ]);
        $page->delete();

        // Reset the fake to clear the create's job from the record.
        Queue::fake();

        $trashedPage = KnowledgePage::withTrashed()->findOrFail($page->id);
        RestoreKnowledgePage::run($trashedPage, $owner);

        Queue::assertPushed(VectorizeKnowledgePage::class);
    }

    public function test_restore_to_root_when_parent_is_still_trashed(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $parent = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Parent', 'body' => 'P.']);
        $child = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Enfant', 'body' => 'E.', 'parent_id' => $parent->id,
        ]);

        // Soft-delete both: the parent stays in the trash.
        $parent->delete();
        $child->delete();

        $trashedChild = KnowledgePage::withTrashed()->findOrFail($child->id);
        $restored = RestoreKnowledgePage::run($trashedChild, $owner);

        $this->assertNull($restored->parent_id);
    }

    public function test_restore_to_original_parent_when_parent_restored_first(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $parent = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Parent', 'body' => 'P.']);
        $child = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Enfant', 'body' => 'E.', 'parent_id' => $parent->id,
        ]);

        // Soft-delete both, then restore the parent first.
        $parent->delete();
        $child->delete();

        $trashedParent = KnowledgePage::withTrashed()->findOrFail($parent->id);
        RestoreKnowledgePage::run($trashedParent, $owner);

        $trashedChild = KnowledgePage::withTrashed()->findOrFail($child->id);
        $restored = RestoreKnowledgePage::run($trashedChild, $owner);

        $this->assertSame($parent->id, $restored->parent_id);
    }

    public function test_restore_preserves_project_when_project_still_exists(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Facturation']);

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page projet', 'body' => 'Corps.', 'project_id' => $project->id,
        ]);
        $page->delete();

        $trashedPage = KnowledgePage::withTrashed()->findOrFail($page->id);
        $restored = RestoreKnowledgePage::run($trashedPage, $owner);

        // The project still exists: the page keeps its project attachment.
        $this->assertSame($project->id, $restored->project_id);
    }

    public function test_restore_keeps_transverse_page_transverse(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        // A transverse page (no project) is restored as transverse.
        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page transverse', 'body' => 'Corps.',
        ]);
        $page->delete();

        $trashedPage = KnowledgePage::withTrashed()->findOrFail($page->id);
        $restored = RestoreKnowledgePage::run($trashedPage, $owner);

        $this->assertNull($restored->project_id);
    }

    public function test_restore_fails_if_page_not_trashed(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page active', 'body' => 'Corps.',
        ]);

        $this->expectException(NotFoundHttpException::class);

        RestoreKnowledgePage::run($page, $owner);
    }

    public function test_restore_respects_guest_scope(): void
    {
        [$owner, $workspace, $inScopePage, $outOfScopePage, $transversePage, $guest] =
            $this->setupScopedGuestWithTrashedPages();

        $trashedOutOfScope = KnowledgePage::withTrashed()->findOrFail($outOfScopePage->id);

        $this->expectException(ModelNotFoundException::class);

        RestoreKnowledgePage::run($trashedOutOfScope, $guest);
    }

    public function test_restore_returns_restored_page(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page', 'body' => 'Corps.',
        ]);
        $page->delete();

        $trashedPage = KnowledgePage::withTrashed()->findOrFail($page->id);
        $restored = RestoreKnowledgePage::run($trashedPage, $owner);

        $this->assertInstanceOf(KnowledgePage::class, $restored);
        $this->assertSame($page->id, $restored->id);
        $this->assertFalse($restored->trashed());
    }

    // ── ForceDeleteKnowledgePage ─────────────────────────────────────

    public function test_force_delete_removes_page_definitely(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page', 'body' => 'Corps.',
        ]);
        $page->delete();

        $trashedPage = KnowledgePage::withTrashed()->findOrFail($page->id);
        ForceDeleteKnowledgePage::run($trashedPage, $owner);

        $this->assertNull(KnowledgePage::withTrashed()->find($page->id));
        $this->assertDatabaseMissing('knowledge_pages', ['id' => $page->id]);
    }

    public function test_force_delete_works_even_with_children(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $parent = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Parent', 'body' => 'P.']);
        $child = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Enfant', 'body' => 'E.', 'parent_id' => $parent->id,
        ]);

        // Soft-delete the parent only; the child stays active.
        $parent->delete();

        $trashedParent = KnowledgePage::withTrashed()->findOrFail($parent->id);
        ForceDeleteKnowledgePage::run($trashedParent, $owner);

        // The parent is gone for good.
        $this->assertNull(KnowledgePage::withTrashed()->find($parent->id));

        // The child survives as an orphan (parent_id nulled by the FK).
        $orphan = KnowledgePage::findOrFail($child->id);
        $this->assertNull($orphan->parent_id);
    }

    public function test_force_delete_fails_if_page_not_trashed(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page active', 'body' => 'Corps.',
        ]);

        $this->expectException(NotFoundHttpException::class);

        ForceDeleteKnowledgePage::run($page, $owner);
    }

    public function test_force_delete_respects_guest_scope(): void
    {
        [$owner, $workspace, $inScopePage, $outOfScopePage, $transversePage, $guest] =
            $this->setupScopedGuestWithTrashedPages();

        $trashedOutOfScope = KnowledgePage::withTrashed()->findOrFail($outOfScopePage->id);

        $this->expectException(ModelNotFoundException::class);

        ForceDeleteKnowledgePage::run($trashedOutOfScope, $guest);
    }

    // ── ListTrashedKnowledgePages ────────────────────────────────────

    public function test_list_returns_only_trashed_pages(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        CreateKnowledgePage::run($workspace, $owner, ['title' => 'Active', 'body' => 'A.']);
        $deleted1 = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Supprimée 1', 'body' => 'S1.']);
        $deleted2 = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Supprimée 2', 'body' => 'S2.']);

        $deleted1->delete();
        $deleted2->delete();

        $trashed = ListTrashedKnowledgePages::run($workspace, $owner);

        $this->assertCount(2, $trashed);
        $this->assertEqualsCanonicalizing(
            [$deleted1->id, $deleted2->id],
            array_column($trashed, 'id'),
        );
    }

    public function test_list_includes_metadata(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Facturation']);

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page avec métadonnées',
            'body' => 'Corps.',
            'project_id' => $project->id,
        ]);
        $page->forceFill([
            'deleted_by_id' => $owner->id,
            'deleted_reason' => 'Contenu obsolète',
        ])->save();
        $page->delete();

        $trashed = ListTrashedKnowledgePages::run($workspace, $owner);

        $this->assertCount(1, $trashed);
        $item = $trashed[0];

        $this->assertSame($page->id, $item['id']);
        $this->assertSame('Page avec métadonnées', $item['title']);
        $this->assertNotNull($item['deletedAt']);
        $this->assertSame($owner->name, $item['deletedBy']);
        $this->assertSame('Contenu obsolète', $item['deletedReason']);
        $this->assertSame($project->id, $item['projectId']);
        $this->assertSame('Facturation', $item['projectName']);
    }

    public function test_list_ordered_by_deleted_at_desc(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $older = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Ancienne', 'body' => 'A.']);
        $newer = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Récente', 'body' => 'R.']);

        // Delete the older page first, then the newer one.
        $older->delete();
        $this->travel(1)->minute();
        $newer->delete();

        $trashed = ListTrashedKnowledgePages::run($workspace, $owner);

        $this->assertCount(2, $trashed);
        $this->assertSame($newer->id, $trashed[0]['id']);
        $this->assertSame($older->id, $trashed[1]['id']);
    }

    public function test_guest_sees_only_their_scope_in_trash(): void
    {
        [$owner, $workspace, $inScopePage, $outOfScopePage, $transversePage, $guest] =
            $this->setupScopedGuestWithTrashedPages();

        $trashed = ListTrashedKnowledgePages::run($workspace, $guest);

        $this->assertCount(1, $trashed);
        $this->assertSame($inScopePage->id, $trashed[0]['id']);
    }

    public function test_full_member_sees_all_trashed_pages(): void
    {
        [$owner, $workspace, $inScopePage, $outOfScopePage, $transversePage, $guest] =
            $this->setupScopedGuestWithTrashedPages();

        $trashed = ListTrashedKnowledgePages::run($workspace, $owner);

        $this->assertCount(3, $trashed);
        $this->assertEqualsCanonicalizing(
            [$inScopePage->id, $outOfScopePage->id, $transversePage->id],
            array_column($trashed, 'id'),
        );
    }

    public function test_cross_workspace_isolation_for_trash_list(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, ['title' => 'Page', 'body' => 'Corps.']);
        $page->delete();

        // Switch to another workspace.
        $stranger = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($stranger, ['name' => 'Autre']);
        app(WorkspaceContext::class)->set($otherWorkspace);

        $trashed = ListTrashedKnowledgePages::run($otherWorkspace, $stranger);

        $this->assertCount(0, $trashed);
    }
}
