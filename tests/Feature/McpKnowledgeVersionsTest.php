<?php

namespace Tests\Feature;

use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Knowledge\UpdateKnowledgePage;
use App\Actions\Workspaces\CreateWorkspace;
use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\CompareKnowledgeVersions;
use App\Mcp\Tools\ListKnowledgeVersions;
use App\Mcp\Tools\RestoreKnowledgeVersion;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class McpKnowledgeVersionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TestResponse::macro('payload', function (): array {
            /** @var TestResponse $this */
            return $this->response->toArray()['result']['structuredContent'] ?? [];
        });
    }

    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace];
    }

    // ── Catalogue ──────────────────────────────────────────────────────

    public function test_versioning_tools_are_registered(): void
    {
        TaskrServer::tools()->assertRegistered([
            ListKnowledgeVersions::class,
            RestoreKnowledgeVersion::class,
            CompareKnowledgeVersions::class,
        ]);
    }

    // ── list_knowledge_versions ────────────────────────────────────────

    public function test_mcp_list_versions_returns_history(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'V1',
            'body' => 'Body V1.',
        ]);

        Carbon::setTestNow(now()->addMinute());

        UpdateKnowledgePage::run($page, $owner, [
            'title' => 'V2',
            'body' => 'Body V2.',
        ]);

        Carbon::setTestNow();

        $result = TaskrServer::actingAs($owner)
            ->tool(ListKnowledgeVersions::class, [
                'workspace_id' => $workspace->id,
                'page_id' => $page->id,
            ])
            ->assertOk()->payload();

        $this->assertSame($page->id, $result['pageId']);
        $this->assertSame('V2', $result['pageTitle']);
        $this->assertSame(2, $result['count']);
        // Most recent first: V2 before V1.
        $this->assertSame('V2', $result['versions'][0]['title']);
        $this->assertSame('V1', $result['versions'][1]['title']);
    }

    public function test_mcp_list_versions_includes_editor(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page',
            'body' => 'Body.',
        ]);

        $result = TaskrServer::actingAs($owner)
            ->tool(ListKnowledgeVersions::class, [
                'workspace_id' => $workspace->id,
                'page_id' => $page->id,
            ])
            ->assertOk()->payload();

        $this->assertSame(1, $result['count']);
        $this->assertSame($owner->name, $result['versions'][0]['editor']);
        $this->assertArrayHasKey('id', $result['versions'][0]);
        $this->assertArrayHasKey('createdAt', $result['versions'][0]);
    }

    public function test_mcp_list_versions_foreign_page_is_not_found(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $stranger = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($stranger, ['name' => 'Other']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $foreignPage = CreateKnowledgePage::run($otherWorkspace, $stranger, [
            'title' => 'Secret',
            'body' => 'Confidential.',
        ]);
        app(WorkspaceContext::class)->set($workspace);

        $result = TaskrServer::actingAs($owner)
            ->tool(ListKnowledgeVersions::class, [
                'workspace_id' => $workspace->id,
                'page_id' => $foreignPage->id,
            ])
            ->assertHasErrors()
            ->payload();

        $this->assertSame('not_found', $result['error']);
    }

    // ── restore_knowledge_version ──────────────────────────────────────

    public function test_mcp_restore_version_updates_page(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'V1',
            'body' => 'Body V1.',
        ]);

        Carbon::setTestNow(now()->addMinute());

        UpdateKnowledgePage::run($page, $owner, [
            'title' => 'V2',
            'body' => 'Body V2.',
        ]);

        Carbon::setTestNow();

        $v1 = $page->versions()->orderBy('created_at')->firstOrFail();

        $result = TaskrServer::actingAs($owner)
            ->tool(RestoreKnowledgeVersion::class, [
                'workspace_id' => $workspace->id,
                'page_id' => $page->id,
                'version_id' => $v1->id,
            ])
            ->assertOk()->payload();

        $this->assertTrue($result['restored']);
        $this->assertSame($page->id, $result['pageId']);
        $this->assertSame('V1', $result['title']);
        $this->assertSame('Body V1.', $result['body']);
        $this->assertSame($v1->id, $result['restoredFromVersionId']);

        // The page is updated in the database.
        $this->assertSame('V1', $page->fresh()->title);
        $this->assertSame('Body V1.', $page->fresh()->body);
    }

    public function test_mcp_restore_version_creates_new_version(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'V1',
            'body' => 'Body V1.',
        ]);

        Carbon::setTestNow(now()->addMinute());

        UpdateKnowledgePage::run($page, $owner, [
            'title' => 'V2',
            'body' => 'Body V2.',
        ]);

        Carbon::setTestNow();

        $v1 = $page->versions()->orderBy('created_at')->firstOrFail();

        $this->assertSame(2, $page->versions()->count());

        TaskrServer::actingAs($owner)
            ->tool(RestoreKnowledgeVersion::class, [
                'workspace_id' => $workspace->id,
                'page_id' => $page->id,
                'version_id' => $v1->id,
            ])
            ->assertOk();

        // 3 versions: v1, v2, and the restoration (v3).
        $this->assertSame(3, $page->versions()->count());
    }

    public function test_mcp_restore_version_fails_for_wrong_page(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page1 = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page 1',
            'body' => 'Body 1.',
        ]);
        $page2 = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page 2',
            'body' => 'Body 2.',
        ]);

        $v1 = $page1->versions()->firstOrFail();

        // Trying to restore v1 of page1 onto page2 must be refused.
        $result = TaskrServer::actingAs($owner)
            ->tool(RestoreKnowledgeVersion::class, [
                'workspace_id' => $workspace->id,
                'page_id' => $page2->id,
                'version_id' => $v1->id,
            ])
            ->assertHasErrors(['This version does not belong to this page.'])
            ->payload();

        $this->assertSame('business_rule', $result['error']);

        // Page 2 is unchanged.
        $this->assertSame('Page 2', $page2->fresh()->title);
        $this->assertSame('Body 2.', $page2->fresh()->body);
    }

    // ── compare_knowledge_versions ─────────────────────────────────────

    public function test_mcp_compare_versions_returns_both_sides(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'V1',
            'body' => 'Body V1.',
        ]);

        Carbon::setTestNow(now()->addMinute());

        UpdateKnowledgePage::run($page, $owner, [
            'title' => 'V2',
            'body' => 'Body V2.',
        ]);

        Carbon::setTestNow();

        $versions = $page->versions()->orderBy('created_at')->get();
        $v1 = $versions[0];
        $v2 = $versions[1];

        $result = TaskrServer::actingAs($owner)
            ->tool(CompareKnowledgeVersions::class, [
                'workspace_id' => $workspace->id,
                'page_id' => $page->id,
                'from_version_id' => $v1->id,
                'to_version_id' => $v2->id,
            ])
            ->assertOk()->payload();

        $this->assertSame($v1->id, $result['from']['id']);
        $this->assertSame('V1', $result['from']['title']);
        $this->assertSame('Body V1.', $result['from']['body']);
        $this->assertSame($owner->name, $result['from']['editor']);

        $this->assertSame($v2->id, $result['to']['id']);
        $this->assertSame('V2', $result['to']['title']);
        $this->assertSame('Body V2.', $result['to']['body']);
        $this->assertSame($owner->name, $result['to']['editor']);
    }

    public function test_mcp_compare_versions_fails_for_foreign_version(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page1 = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page 1',
            'body' => 'Body 1.',
        ]);
        $page2 = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page 2',
            'body' => 'Body 2.',
        ]);

        $v1 = $page1->versions()->firstOrFail();
        $v2 = $page2->versions()->firstOrFail();

        // Comparing a version from page1 with a version from page2
        // on page1 must be refused.
        $result = TaskrServer::actingAs($owner)
            ->tool(CompareKnowledgeVersions::class, [
                'workspace_id' => $workspace->id,
                'page_id' => $page1->id,
                'from_version_id' => $v1->id,
                'to_version_id' => $v2->id,
            ])
            ->assertHasErrors(['Version not found for this page.'])
            ->payload();

        $this->assertSame('business_rule', $result['error']);
    }
}
