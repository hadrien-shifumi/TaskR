<?php

namespace Tests\Feature;

use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Knowledge\UpdateKnowledgePage;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Membership;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class KnowledgeConflictTest extends TestCase
{
    use RefreshDatabase;

    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        return [$owner, $workspace, $member];
    }

    public function test_no_conflict_when_base_updated_at_matches(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page',
            'body' => 'Body.',
        ]);

        // The editor opened the page at its current updated_at.
        $updated = UpdateKnowledgePage::run($page, $owner, [
            'title' => 'Page modifiée',
            'body' => 'Body modifié.',
            'base_updated_at' => $page->updated_at->toISOString(),
        ]);

        $this->assertSame('Page modifiée', $updated->title);
    }

    public function test_conflict_detected_when_page_modified_since_base(): void
    {
        Queue::fake();
        [$owner, $workspace, $member] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page',
            'body' => 'Body.',
        ]);

        // The owner opens the page (base = now).
        $baseUpdatedAt = $page->updated_at->toISOString();

        // The member saves in between.
        Carbon::setTestNow(now()->addMinute());
        UpdateKnowledgePage::run($page, $member, [
            'title' => 'Modifié par member',
            'body' => 'Body modifié.',
        ]);
        Carbon::setTestNow(); // reset

        // The owner saves: the page moved on, this must conflict.
        try {
            UpdateKnowledgePage::run($page, $owner, [
                'title' => 'Modifié par owner',
                'body' => 'Body owner.',
                'base_updated_at' => $baseUpdatedAt,
            ]);
            $this->fail('Expected HttpResponseException was not thrown.');
        } catch (HttpResponseException $e) {
            $response = $e->getResponse();
            $this->assertSame(409, $response->getStatusCode());
            $data = $response->getData(true);
            $this->assertArrayHasKey('conflict', $data);
            $this->assertSame($member->name, $data['conflict']['lastEditor']);
        }

        // The owner's content was not written.
        $this->assertSame('Modifié par member', $page->fresh()->title);
    }

    public function test_force_overwrite_bypasses_conflict(): void
    {
        Queue::fake();
        [$owner, $workspace, $member] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page',
            'body' => 'Body.',
        ]);

        $baseUpdatedAt = $page->updated_at->toISOString();

        Carbon::setTestNow(now()->addMinute());
        UpdateKnowledgePage::run($page, $member, [
            'title' => 'Modifié par member',
            'body' => 'Body modifié.',
        ]);
        Carbon::setTestNow(); // reset

        $updated = UpdateKnowledgePage::run($page, $owner, [
            'title' => 'Écrasé par owner',
            'body' => 'Body owner.',
            'base_updated_at' => $baseUpdatedAt,
            'force_overwrite' => true,
        ]);

        $this->assertSame('Écrasé par owner', $updated->title);
    }

    public function test_no_conflict_detection_without_base_updated_at(): void
    {
        Queue::fake();
        [$owner, $workspace, $member] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page',
            'body' => 'Body.',
        ]);

        Carbon::setTestNow(now()->addMinute());
        UpdateKnowledgePage::run($page, $member, [
            'title' => 'Modifié par member',
            'body' => 'Body modifié.',
        ]);
        Carbon::setTestNow(); // reset

        // No base_updated_at (legacy client, MCP): no detection, last write wins.
        $updated = UpdateKnowledgePage::run($page, $owner, [
            'title' => 'Modifié par owner',
            'body' => 'Body owner.',
        ]);

        $this->assertSame('Modifié par owner', $updated->title);
    }

    public function test_conflict_response_contains_last_editor_info(): void
    {
        Queue::fake();
        [$owner, $workspace, $member] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page',
            'body' => 'Body.',
        ]);

        $baseUpdatedAt = $page->updated_at->toISOString();

        Carbon::setTestNow(now()->addMinute());
        UpdateKnowledgePage::run($page, $member, [
            'title' => 'Modifié par member',
            'body' => 'Body modifié.',
        ]);
        Carbon::setTestNow(); // reset

        try {
            UpdateKnowledgePage::run($page, $owner, [
                'title' => 'Owner',
                'body' => 'Owner body.',
                'base_updated_at' => $baseUpdatedAt,
            ]);
            $this->fail('Expected HttpResponseException was not thrown.');
        } catch (HttpResponseException $e) {
            $response = $e->getResponse();
            $this->assertSame(409, $response->getStatusCode());
            $data = $response->getData(true);
            $this->assertNotEmpty($data['message']);
            $this->assertStringContainsString($member->name, $data['message']);
            $this->assertSame($member->id, $data['conflict']['lastEditorId']);
            $this->assertSame($member->name, $data['conflict']['lastEditor']);
            $this->assertArrayHasKey('updatedAt', $data['conflict']);
        }
    }

    public function test_conflict_via_http_returns_409(): void
    {
        Queue::fake();
        [$owner, $workspace, $member] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page',
            'body' => 'Body.',
        ]);

        $baseUpdatedAt = $page->updated_at->toISOString();

        Carbon::setTestNow(now()->addMinute());
        $this->actingAs($member)
            ->patch("/knowledge/{$page->id}", [
                'title' => 'Modifié par member',
                'body' => 'Body modifié.',
            ])
            ->assertRedirect();
        Carbon::setTestNow(); // reset

        $response = $this->actingAs($owner)
            ->patch("/knowledge/{$page->id}", [
                'title' => 'Owner',
                'body' => 'Owner body.',
                'base_updated_at' => $baseUpdatedAt,
            ]);

        $response->assertStatus(409);
        $response->assertJsonStructure([
            'message',
            'conflict' => ['lastEditor', 'lastEditorId', 'updatedAt'],
        ]);
        $this->assertSame($member->name, $response->json('conflict.lastEditor'));
    }

    public function test_force_overwrite_via_http(): void
    {
        Queue::fake();
        [$owner, $workspace, $member] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page',
            'body' => 'Body.',
        ]);

        $baseUpdatedAt = $page->updated_at->toISOString();

        Carbon::setTestNow(now()->addMinute());
        $this->actingAs($member)
            ->patch("/knowledge/{$page->id}", [
                'title' => 'Modifié par member',
                'body' => 'Body modifié.',
            ])
            ->assertRedirect();
        Carbon::setTestNow(); // reset

        $response = $this->actingAs($owner)
            ->patch("/knowledge/{$page->id}", [
                'title' => 'Écrasé',
                'body' => 'Owner body.',
                'base_updated_at' => $baseUpdatedAt,
                'force_overwrite' => true,
            ]);

        $response->assertRedirect();
        $this->assertSame('Écrasé', $page->fresh()->title);
    }

    public function test_mcp_update_without_base_updated_at_still_works(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page',
            'body' => 'Body.',
        ]);

        // The MCP tool does not send base_updated_at: no conflict detection.
        $updated = UpdateKnowledgePage::run($page, $owner, [
            'title' => 'MCP update',
            'body' => 'MCP body.',
        ]);

        $this->assertSame('MCP update', $updated->title);
    }

    public function test_conflict_detection_with_same_timestamp_no_conflict(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page',
            'body' => 'Body.',
        ]);

        // base is exactly the current updated_at: equality is not a conflict.
        $updated = UpdateKnowledgePage::run($page, $owner, [
            'title' => 'Modifié',
            'body' => 'Body modifié.',
            'base_updated_at' => $page->updated_at->toISOString(),
        ]);

        $this->assertSame('Modifié', $updated->title);
    }

    public function test_versions_still_created_after_conflict_resolution(): void
    {
        Queue::fake();
        [$owner, $workspace, $member] = $this->setupWorkspace();

        $page = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page',
            'body' => 'Body.',
        ]);

        $baseUpdatedAt = $page->updated_at->toISOString();

        // v2: the member saves.
        UpdateKnowledgePage::run($page, $member, [
            'title' => 'Member',
            'body' => 'Member body.',
        ]);

        // v3: the owner resolves the conflict by forcing.
        UpdateKnowledgePage::run($page, $owner, [
            'title' => 'Owner',
            'body' => 'Owner body.',
            'base_updated_at' => $baseUpdatedAt,
            'force_overwrite' => true,
        ]);

        // v1 (creation), v2 (member), v3 (owner).
        $this->assertSame(3, $page->versions()->count());
    }
}
