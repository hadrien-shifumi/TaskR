<?php

namespace Tests\Feature;

use App\Actions\Knowledge\CompareKnowledgePageVersions;
use App\Actions\Knowledge\ListKnowledgePageVersions;
use App\Actions\Knowledge\RestoreKnowledgePageVersion;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Jobs\VectorizeKnowledgePage;
use App\Models\KnowledgePage;
use App\Models\KnowledgePageVersion;
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

class KnowledgeVersionHistoryTest extends TestCase
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
     * Create a page directly via factory (no automatic version, unlike
     * CreateKnowledgePage which T7 turns into a version-creating action).
     */
    private function createPage(Workspace $workspace, User $owner, array $attributes = []): KnowledgePage
    {
        return KnowledgePage::factory()->create(array_merge([
            'workspace_id' => $workspace->id,
            'last_editor_id' => $owner->id,
        ], $attributes));
    }

    /**
     * Guest scoped to one project. Pages with versions: in-scope project
     * page, out-of-scope project page.
     *
     * @return array{0: User, 1: Workspace, 2: KnowledgePage, 3: KnowledgePage, 4: User}
     */
    private function setupScopedGuestWithVersions(): array
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $inScope = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $outOfScope = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $inScopePage = $this->createPage($workspace, $owner, [
            'title' => 'Page client', 'body' => 'Corps', 'project_id' => $inScope->id,
        ]);
        $outOfScopePage = $this->createPage($workspace, $owner, [
            'title' => 'Page interne', 'body' => 'Corps', 'project_id' => $outOfScope->id,
        ]);

        KnowledgePageVersion::factory()->count(2)->create([
            'page_id' => $inScopePage->id,
            'editor_id' => $owner->id,
        ]);
        KnowledgePageVersion::factory()->count(2)->create([
            'page_id' => $outOfScopePage->id,
            'editor_id' => $owner->id,
        ]);

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

        return [$owner, $workspace, $inScopePage, $outOfScopePage, $guest];
    }

    // ── ListKnowledgePageVersions ────────────────────────────────────

    public function test_list_returns_all_versions_ordered_desc(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $page = $this->createPage($workspace, $owner, [
            'title' => 'Onboarding', 'body' => 'First version.',
        ]);

        $oldest = KnowledgePageVersion::factory()->create([
            'page_id' => $page->id,
            'editor_id' => $owner->id,
            'created_at' => now()->subDays(3),
        ]);
        $middle = KnowledgePageVersion::factory()->create([
            'page_id' => $page->id,
            'editor_id' => $owner->id,
            'created_at' => now()->subDay(),
        ]);
        $newest = KnowledgePageVersion::factory()->create([
            'page_id' => $page->id,
            'editor_id' => $owner->id,
            'created_at' => now(),
        ]);

        $versions = ListKnowledgePageVersions::run($page);

        $this->assertCount(3, $versions);
        $this->assertSame([$newest->id, $middle->id, $oldest->id], array_column($versions, 'id'));
    }

    public function test_list_includes_editor_name(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $page = $this->createPage($workspace, $owner, [
            'title' => 'Onboarding', 'body' => 'First version.',
        ]);

        KnowledgePageVersion::factory()->create([
            'page_id' => $page->id,
            'editor_id' => $owner->id,
        ]);

        $versions = ListKnowledgePageVersions::run($page, $owner);

        $this->assertCount(1, $versions);
        $this->assertSame($owner->name, $versions[0]['editor']);
    }

    public function test_list_empty_for_page_without_versions(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $page = $this->createPage($workspace, $owner, [
            'title' => 'Sans version', 'body' => 'Corps.',
        ]);

        $versions = ListKnowledgePageVersions::run($page);

        $this->assertSame([], $versions);
    }

    public function test_list_respects_guest_scope(): void
    {
        [$owner, $workspace, $inScopePage, $outOfScopePage, $guest] =
            $this->setupScopedGuestWithVersions();

        $this->expectException(ModelNotFoundException::class);

        ListKnowledgePageVersions::run($outOfScopePage, $guest);
    }

    public function test_list_cross_workspace_isolation(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $page = $this->createPage($workspace, $owner, [
            'title' => 'Page secrète', 'body' => 'Confidentiel.',
        ]);
        KnowledgePageVersion::factory()->count(2)->create([
            'page_id' => $page->id,
            'editor_id' => $owner->id,
        ]);

        // Switch to another workspace owned by someone else: the stranger
        // is not a member of the current workspace, so the access check
        // fails exactly like a non-existent page (404).
        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Autre']);
        app(WorkspaceContext::class)->set($otherWorkspace);

        $stranger = User::factory()->create();

        $this->expectException(ModelNotFoundException::class);

        ListKnowledgePageVersions::run($page, $stranger);
    }

    // ── RestoreKnowledgePageVersion ──────────────────────────────────

    public function test_restore_updates_page_content(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = $this->createPage($workspace, $owner, [
            'title' => 'Version B', 'body' => 'Body B',
        ]);

        $v1 = KnowledgePageVersion::create([
            'page_id' => $page->id,
            'title' => 'Version A',
            'body' => 'Body A',
            'editor_id' => $owner->id,
            'created_at' => now()->subDay(),
        ]);
        KnowledgePageVersion::create([
            'page_id' => $page->id,
            'title' => 'Version B',
            'body' => 'Body B',
            'editor_id' => $owner->id,
            'created_at' => now(),
        ]);

        $restored = RestoreKnowledgePageVersion::run($page, $v1, $owner);

        $this->assertSame('Version A', $restored->title);
        $this->assertSame('Body A', $restored->body);
    }

    public function test_restore_creates_new_version(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = $this->createPage($workspace, $owner, [
            'title' => 'Version B', 'body' => 'Body B',
        ]);

        $v1 = KnowledgePageVersion::create([
            'page_id' => $page->id,
            'title' => 'Version A',
            'body' => 'Body A',
            'editor_id' => $owner->id,
            'created_at' => now()->subDays(2),
        ]);
        KnowledgePageVersion::create([
            'page_id' => $page->id,
            'title' => 'Version B',
            'body' => 'Body B',
            'editor_id' => $owner->id,
            'created_at' => now()->subDay(),
        ]);

        $this->assertSame(2, $page->versions()->count());

        RestoreKnowledgePageVersion::run($page, $v1, $owner);

        // The restoration itself is a new version: history is preserved.
        $this->assertSame(3, $page->versions()->count());

        $newest = $page->versions()->orderBy('created_at', 'desc')->first();
        $this->assertSame('Version A', $newest->title);
        $this->assertSame('Body A', $newest->body);
    }

    public function test_restore_new_version_has_current_user_as_editor(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = $this->createPage($workspace, $owner, [
            'title' => 'Version B', 'body' => 'Body B',
        ]);

        $v1 = KnowledgePageVersion::create([
            'page_id' => $page->id,
            'title' => 'Version A',
            'body' => 'Body A',
            'editor_id' => $owner->id,
            'created_at' => now()->subDay(),
        ]);

        $otherEditor = User::factory()->create();
        Membership::create([
            'user_id' => $otherEditor->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        RestoreKnowledgePageVersion::run($page, $v1, $otherEditor);

        $newest = $page->versions()->orderBy('created_at', 'desc')->first();
        $this->assertSame($otherEditor->id, (int) $newest->editor_id);
    }

    public function test_restore_updates_last_editor(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = $this->createPage($workspace, $owner, [
            'title' => 'Version B', 'body' => 'Body B',
        ]);

        $v1 = KnowledgePageVersion::create([
            'page_id' => $page->id,
            'title' => 'Version A',
            'body' => 'Body A',
            'editor_id' => $owner->id,
            'created_at' => now()->subDay(),
        ]);

        $otherEditor = User::factory()->create();
        Membership::create([
            'user_id' => $otherEditor->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $restored = RestoreKnowledgePageVersion::run($page, $v1, $otherEditor);

        $this->assertSame($otherEditor->id, (int) $restored->last_editor_id);
    }

    public function test_restore_triggers_revectorization(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = $this->createPage($workspace, $owner, [
            'title' => 'Page', 'body' => 'Corps.',
        ]);

        $version = KnowledgePageVersion::create([
            'page_id' => $page->id,
            'title' => 'Page v1',
            'body' => 'Corps v1',
            'editor_id' => $owner->id,
            'created_at' => now()->subDay(),
        ]);

        RestoreKnowledgePageVersion::run($page, $version, $owner);

        Queue::assertPushed(VectorizeKnowledgePage::class);
    }

    public function test_restore_fails_if_version_belongs_to_another_page(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $pageA = $this->createPage($workspace, $owner, [
            'title' => 'Page A', 'body' => 'A.',
        ]);
        $pageB = $this->createPage($workspace, $owner, [
            'title' => 'Page B', 'body' => 'B.',
        ]);

        $versionOfA = KnowledgePageVersion::create([
            'page_id' => $pageA->id,
            'title' => 'Version A',
            'body' => 'Body A',
            'editor_id' => $owner->id,
            'created_at' => now()->subDay(),
        ]);

        $this->expectException(NotFoundHttpException::class);

        RestoreKnowledgePageVersion::run($pageB, $versionOfA, $owner);
    }

    public function test_restore_respects_guest_scope(): void
    {
        [$owner, $workspace, $inScopePage, $outOfScopePage, $guest] =
            $this->setupScopedGuestWithVersions();

        $version = KnowledgePageVersion::withoutGlobalScopes()
            ->where('page_id', $outOfScopePage->id)
            ->firstOrFail();

        $this->expectException(ModelNotFoundException::class);

        RestoreKnowledgePageVersion::run($outOfScopePage, $version, $guest);
    }

    public function test_restore_preserves_page_hierarchy(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $parent = $this->createPage($workspace, $owner, [
            'title' => 'Parent', 'body' => 'P.',
        ]);
        $child = $this->createPage($workspace, $owner, [
            'title' => 'Enfant', 'body' => 'E.', 'parent_id' => $parent->id,
        ]);

        $version = KnowledgePageVersion::create([
            'page_id' => $child->id,
            'title' => 'Enfant v1',
            'body' => 'Corps v1',
            'editor_id' => $owner->id,
            'created_at' => now()->subDay(),
        ]);

        $restored = RestoreKnowledgePageVersion::run($child, $version, $owner);

        $this->assertSame($parent->id, $restored->parent_id);
    }

    // ── CompareKnowledgePageVersions ─────────────────────────────────

    public function test_compare_returns_both_versions(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $page = $this->createPage($workspace, $owner, [
            'title' => 'Onboarding', 'body' => 'First version.',
        ]);

        $v1 = KnowledgePageVersion::create([
            'page_id' => $page->id,
            'title' => 'Version A',
            'body' => 'Body A',
            'editor_id' => $owner->id,
            'created_at' => now()->subDay(),
        ]);
        $v2 = KnowledgePageVersion::create([
            'page_id' => $page->id,
            'title' => 'Version B',
            'body' => 'Body B',
            'editor_id' => $owner->id,
            'created_at' => now(),
        ]);

        $comparison = CompareKnowledgePageVersions::run($page, $v1, $v2);

        $this->assertSame($v1->id, $comparison['from']['id']);
        $this->assertSame($v2->id, $comparison['to']['id']);
    }

    public function test_compare_includes_full_body(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $page = $this->createPage($workspace, $owner, [
            'title' => 'Onboarding', 'body' => 'First version.',
        ]);

        $v1 = KnowledgePageVersion::create([
            'page_id' => $page->id,
            'title' => 'Version A',
            'body' => 'Body A with **markdown** and multiple lines.',
            'editor_id' => $owner->id,
            'created_at' => now()->subDay(),
        ]);
        $v2 = KnowledgePageVersion::create([
            'page_id' => $page->id,
            'title' => 'Version B',
            'body' => 'Body B completely different content.',
            'editor_id' => $owner->id,
            'created_at' => now(),
        ]);

        $comparison = CompareKnowledgePageVersions::run($page, $v1, $v2);

        $this->assertSame('Body A with **markdown** and multiple lines.', $comparison['from']['body']);
        $this->assertSame('Body B completely different content.', $comparison['to']['body']);
    }

    public function test_compare_includes_editor_and_timestamp(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $page = $this->createPage($workspace, $owner, [
            'title' => 'Onboarding', 'body' => 'First version.',
        ]);

        $v1 = KnowledgePageVersion::create([
            'page_id' => $page->id,
            'title' => 'Version A',
            'body' => 'Body A',
            'editor_id' => $owner->id,
            'created_at' => now()->subDay(),
        ]);
        $v2 = KnowledgePageVersion::create([
            'page_id' => $page->id,
            'title' => 'Version B',
            'body' => 'Body B',
            'editor_id' => $owner->id,
            'created_at' => now(),
        ]);

        $comparison = CompareKnowledgePageVersions::run($page, $v1, $v2);

        $this->assertSame($owner->name, $comparison['from']['editor']);
        $this->assertSame($owner->name, $comparison['to']['editor']);
        $this->assertSame($v1->created_at->toISOString(), $comparison['from']['createdAt']);
        $this->assertSame($v2->created_at->toISOString(), $comparison['to']['createdAt']);
    }

    public function test_compare_fails_if_version_from_another_page(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $pageA = $this->createPage($workspace, $owner, [
            'title' => 'Page A', 'body' => 'A.',
        ]);
        $pageB = $this->createPage($workspace, $owner, [
            'title' => 'Page B', 'body' => 'B.',
        ]);

        $versionOfA = KnowledgePageVersion::create([
            'page_id' => $pageA->id,
            'title' => 'Version A',
            'body' => 'Body A',
            'editor_id' => $owner->id,
            'created_at' => now()->subDay(),
        ]);
        $versionOfB = KnowledgePageVersion::create([
            'page_id' => $pageB->id,
            'title' => 'Version B',
            'body' => 'Body B',
            'editor_id' => $owner->id,
            'created_at' => now(),
        ]);

        $this->expectException(NotFoundHttpException::class);

        // Compare a version of page B against page A: must 404.
        CompareKnowledgePageVersions::run($pageA, $versionOfA, $versionOfB);
    }

    public function test_compare_respects_guest_scope(): void
    {
        [$owner, $workspace, $inScopePage, $outOfScopePage, $guest] =
            $this->setupScopedGuestWithVersions();

        $versions = KnowledgePageVersion::withoutGlobalScopes()
            ->where('page_id', $outOfScopePage->id)
            ->orderBy('created_at')
            ->get();

        $this->expectException(ModelNotFoundException::class);

        CompareKnowledgePageVersions::run($outOfScopePage, $versions[0], $versions[1], $guest);
    }

    public function test_compare_same_version_returns_identical_content(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $page = $this->createPage($workspace, $owner, [
            'title' => 'Onboarding', 'body' => 'First version.',
        ]);

        $v1 = KnowledgePageVersion::create([
            'page_id' => $page->id,
            'title' => 'Version A',
            'body' => 'Body A',
            'editor_id' => $owner->id,
            'created_at' => now()->subDay(),
        ]);

        $comparison = CompareKnowledgePageVersions::run($page, $v1, $v1);

        $this->assertSame($comparison['from'], $comparison['to']);
    }
}
