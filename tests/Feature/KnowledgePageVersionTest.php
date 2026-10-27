<?php

namespace Tests\Feature;

use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Knowledge\DeleteKnowledgePage;
use App\Actions\Knowledge\ForceDeleteKnowledgePage;
use App\Actions\Knowledge\UpdateKnowledgePage;
use App\Actions\Workspaces\CreateWorkspace;
use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\CreateKnowledge;
use App\Models\KnowledgePage;
use App\Models\KnowledgePageVersion;
use App\Models\Membership;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class KnowledgePageVersionTest extends TestCase
{
    use RefreshDatabase;

    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace];
    }

    public function test_version_can_be_created_for_a_page(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Onboarding',
            'body' => 'First version.',
        ]);

        $version = KnowledgePageVersion::create([
            'page_id' => $page->id,
            'title' => 'Onboarding',
            'body' => 'First version.',
            'editor_id' => $owner->id,
        ]);

        $this->assertDatabaseHas('knowledge_page_versions', [
            'id' => $version->id,
            'page_id' => $page->id,
            'title' => 'Onboarding',
            'body' => 'First version.',
            'editor_id' => $owner->id,
        ]);

        // created_at is set by the database default (useCurrent): the
        // in-memory instance only sees it after a refresh.
        $this->assertNotNull($version->refresh()->created_at);
    }

    public function test_page_has_versions_relationship(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Onboarding',
            'body' => 'First version.',
        ]);

        // The action already creates 1 automatic version (v1).
        $this->assertSame(1, $page->versions()->count());

        KnowledgePageVersion::factory()->count(3)->create([
            'page_id' => $page->id,
            'editor_id' => $owner->id,
        ]);

        $this->assertSame(4, $page->versions()->count());
    }

    public function test_version_belongs_to_page_and_editor(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Onboarding',
            'body' => 'First version.',
        ]);

        $version = KnowledgePageVersion::factory()->create([
            'page_id' => $page->id,
            'editor_id' => $owner->id,
        ]);

        $this->assertTrue($version->page->is($page));
        $this->assertTrue($version->editor->is($owner));
    }

    public function test_versions_are_cascade_deleted_with_page(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Lone',
            'body' => 'L.',
        ]);

        // The action already creates 1 automatic version (v1).
        KnowledgePageVersion::factory()->count(2)->create([
            'page_id' => $page->id,
            'editor_id' => $owner->id,
        ]);

        $this->assertSame(3, KnowledgePageVersion::count());

        // Soft delete keeps the history (trash is not a purge); only a hard
        // delete cascades to the versions via the FK.
        $page->delete();
        $this->assertSame(3, KnowledgePageVersion::count());

        $page->forceDelete();
        $this->assertSame(0, KnowledgePageVersion::count());
    }

    public function test_cross_workspace_isolation_via_page(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Secret page',
            'body' => 'Confidential.',
        ]);

        $version = KnowledgePageVersion::factory()->create([
            'page_id' => $page->id,
            'editor_id' => $owner->id,
        ]);

        // Switch to another workspace: the page is no longer reachable
        // through the WorkspaceScope, and neither is its version via page().
        $stranger = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($stranger, ['name' => 'Other']);
        app(WorkspaceContext::class)->set($otherWorkspace);

        $this->assertNull(KnowledgePage::find($page->id));
        $this->assertNull($version->fresh()->page);
    }

    public function test_versions_ordered_chronologically(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Onboarding',
            'body' => 'First version.',
        ]);

        // The action creates an automatic v1 with created_at = page creation time.
        $auto = $page->versions()->orderBy('created_at')->firstOrFail();

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
            'created_at' => now()->addSecond(),
        ]);

        $ordered = $page->versions()->orderBy('created_at', 'desc')->pluck('id');

        $this->assertSame([$newest->id, $auto->id, $middle->id, $oldest->id], $ordered->all());
    }

    public function test_version_factory_creates_valid_instance(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Onboarding',
            'body' => 'First version.',
        ]);

        $version = KnowledgePageVersion::factory()->create([
            'page_id' => $page->id,
            'editor_id' => $owner->id,
        ]);

        $this->assertNotNull($version->id);
        $this->assertNotNull($version->title);
        $this->assertNotNull($version->body);
        $this->assertNotNull($version->created_at);
        $this->assertDatabaseHas('knowledge_page_versions', ['id' => $version->id]);
    }

    public function test_editor_can_differ_from_last_editor(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $otherEditor = User::factory()->create();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Onboarding',
            'body' => 'First version.',
        ]);

        $this->assertSame($owner->id, (int) $page->last_editor_id);

        $version = KnowledgePageVersion::factory()->create([
            'page_id' => $page->id,
            'editor_id' => $otherEditor->id,
        ]);

        $this->assertSame($otherEditor->id, (int) $version->editor_id);
        $this->assertTrue($version->editor->is($otherEditor));
        $this->assertTrue($page->lastEditor->is($owner));
    }

    // ==================== Création automatique de versions ====================

    public function test_create_page_creates_initial_version(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Ma page',
            'body' => 'Contenu initial.',
        ]);

        $versions = $page->versions()->get();
        $this->assertCount(1, $versions);

        $version = $versions->first();
        $this->assertSame('Ma page', $version->title);
        $this->assertSame('Contenu initial.', $version->body);
        $this->assertSame($owner->id, (int) $version->editor_id);
    }

    public function test_update_page_creates_new_version(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Ma page',
            'body' => 'Contenu initial.',
        ]);

        // Première mise à jour
        UpdateKnowledgePage::run($page, $owner, [
            'title' => 'Ma page modifiée',
            'body' => 'Contenu modifié.',
        ]);

        $versions = $page->versions()->orderBy('created_at')->get();
        $this->assertCount(2, $versions);

        // v1 = état initial
        $this->assertSame('Ma page', $versions[0]->title);
        $this->assertSame('Contenu initial.', $versions[0]->body);

        // v2 = état modifié
        $this->assertSame('Ma page modifiée', $versions[1]->title);
        $this->assertSame('Contenu modifié.', $versions[1]->body);
    }

    public function test_multiple_updates_create_multiple_versions(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'V1',
            'body' => 'Body V1.',
        ]);

        UpdateKnowledgePage::run($page, $owner, ['title' => 'V2', 'body' => 'Body V2.']);
        UpdateKnowledgePage::run($page, $owner, ['title' => 'V3', 'body' => 'Body V3.']);

        $this->assertCount(3, $page->versions()->get());
    }

    public function test_version_captures_editor(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();
        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page',
            'body' => 'Body.',
        ]);

        UpdateKnowledgePage::run($page, $member, [
            'title' => 'Page modifiée',
            'body' => 'Body modifié.',
        ]);

        $versions = $page->versions()->orderBy('created_at')->get();
        $this->assertSame($owner->id, (int) $versions[0]->editor_id);
        $this->assertSame($member->id, (int) $versions[1]->editor_id);
    }

    public function test_version_timestamps_are_chronological(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'V1',
            'body' => 'Body.',
        ]);

        // Simuler un délai
        Carbon::setTestNow(now()->addMinute());

        UpdateKnowledgePage::run($page, $owner, [
            'title' => 'V2',
            'body' => 'Body modifié.',
        ]);

        Carbon::setTestNow(); // reset

        $versions = $page->versions()->orderBy('created_at')->get();
        $this->assertTrue($versions[0]->created_at->lessThan($versions[1]->created_at));
    }

    public function test_versions_survive_page_soft_delete(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page',
            'body' => 'Body.',
        ]);

        UpdateKnowledgePage::run($page, $owner, ['title' => 'V2', 'body' => 'Body V2.']);

        // Soft delete
        DeleteKnowledgePage::run($page, $owner);

        // Les versions existent toujours
        $this->assertSame(2, KnowledgePageVersion::where('page_id', $page->id)->count());
    }

    public function test_versions_are_deleted_when_page_is_force_deleted(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page',
            'body' => 'Body.',
        ]);

        UpdateKnowledgePage::run($page, $owner, ['title' => 'V2', 'body' => 'Body V2.']);

        // Soft delete puis force delete
        DeleteKnowledgePage::run($page, $owner);
        $trashed = KnowledgePage::withTrashed()->findOrFail($page->id);
        ForceDeleteKnowledgePage::run($trashed, $owner);

        // Les versions sont supprimées en cascade
        $this->assertSame(0, KnowledgePageVersion::where('page_id', $page->id)->count());
    }

    public function test_create_page_with_parent_creates_version(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $parent = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Parent',
            'body' => 'Body parent.',
        ]);

        $child = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Enfant',
            'body' => 'Body enfant.',
            'parent_id' => $parent->id,
        ]);

        $this->assertCount(1, $child->versions()->get());
    }

    public function test_http_create_creates_version(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->post('/knowledge', [
                'title' => 'Page HTTP',
                'body' => 'Body.',
            ])
            ->assertRedirect();

        $page = KnowledgePage::where('title', 'Page HTTP')->firstOrFail();
        $this->assertCount(1, $page->versions()->get());
    }

    public function test_http_update_creates_version(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page',
            'body' => 'Body.',
        ]);

        $this->actingAs($owner)
            ->patch("/knowledge/{$page->id}", [
                'title' => 'Page modifiée',
                'body' => 'Body modifié.',
            ])
            ->assertRedirect();

        $this->assertCount(2, $page->versions()->get());
    }

    public function test_mcp_create_creates_version(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        TestResponse::macro('payload', function (): array {
            /** @var TestResponse $this */
            return $this->response->toArray()['result']['structuredContent'] ?? [];
        });

        $result = TaskrServer::actingAs($owner)
            ->tool(CreateKnowledge::class, [
                'workspace_id' => $workspace->id,
                'title' => 'Page MCP',
                'body' => 'Body MCP.',
            ])
            ->assertOk()->payload();

        $page = KnowledgePage::withoutWorkspaceScope()->findOrFail($result['page']['id']);
        $this->assertCount(1, $page->versions()->get());
    }
}
