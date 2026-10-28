<?php

namespace Tests\Feature\Knowledge;

use App\Actions\Knowledge\GetKnowledgePageVersion;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\KnowledgePage;
use App\Models\KnowledgePageVersion;
use App\Models\Membership;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class GetKnowledgePageVersionTest extends TestCase
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

    private function createPage(Workspace $workspace, User $owner, array $attributes = []): KnowledgePage
    {
        return KnowledgePage::factory()->create(array_merge([
            'workspace_id' => $workspace->id,
            'last_editor_id' => $owner->id,
        ], $attributes));
    }

    // ── HTTP (JSON) ──────────────────────────────────────────────────

    public function test_returns_full_version_content_as_json(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $page = $this->createPage($workspace, $owner, [
            'title' => 'Original title',
            'body' => '# Original content',
        ]);

        $version = KnowledgePageVersion::factory()->create([
            'page_id' => $page->id,
            'title' => 'Version title',
            'body' => '# Version body content',
            'editor_id' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson(route('knowledge.versions.show', [
                'page' => $page->id,
                'version' => $version->id,
            ]));

        $response->assertOk()
            ->assertJsonPath('id', $version->id)
            ->assertJsonPath('title', 'Version title')
            ->assertJsonPath('body', '# Version body content')
            ->assertJsonPath('editor', $owner->name)
            ->assertJsonPath('createdAt', $version->created_at->toISOString());
    }

    public function test_returns_404_when_version_does_not_belong_to_page(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $pageA = $this->createPage($workspace, $owner, ['title' => 'Page A', 'body' => 'A.']);
        $pageB = $this->createPage($workspace, $owner, ['title' => 'Page B', 'body' => 'B.']);

        $versionOfB = KnowledgePageVersion::factory()->create([
            'page_id' => $pageB->id,
            'editor_id' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->getJson(route('knowledge.versions.show', [
                'page' => $pageA->id,
                'version' => $versionOfB->id,
            ]))
            ->assertNotFound();
    }

    public function test_returns_404_when_user_cannot_access_workspace(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $page = $this->createPage($workspace, $owner, ['title' => 'Secret', 'body' => 'Confidential.']);
        $version = KnowledgePageVersion::factory()->create([
            'page_id' => $page->id,
            'editor_id' => $owner->id,
        ]);

        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->getJson(route('knowledge.versions.show', [
                'page' => $page->id,
                'version' => $version->id,
            ]))
            ->assertNotFound();
    }

    public function test_returns_404_for_cross_workspace_isolation(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $page = $this->createPage($workspace, $owner, ['title' => 'Secret', 'body' => 'Confidential.']);
        $version = KnowledgePageVersion::factory()->create([
            'page_id' => $page->id,
            'editor_id' => $owner->id,
        ]);

        // Switch to another workspace: the page binding 404s via WorkspaceScope.
        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Other']);
        app(WorkspaceContext::class)->set($otherWorkspace);

        $this->actingAs($otherOwner)
            ->getJson(route('knowledge.versions.show', [
                'page' => $page->id,
                'version' => $version->id,
            ]))
            ->assertNotFound();
    }

    public function test_requires_authentication(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $page = $this->createPage($workspace, $owner, ['title' => 'Page', 'body' => 'Body.']);
        $version = KnowledgePageVersion::factory()->create([
            'page_id' => $page->id,
            'editor_id' => $owner->id,
        ]);

        $this->getJson(route('knowledge.versions.show', [
            'page' => $page->id,
            'version' => $version->id,
        ]))->assertUnauthorized();
    }

    // ── Object run (AsObject) ────────────────────────────────────────

    public function test_handle_returns_version_detail(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $page = $this->createPage($workspace, $owner, ['title' => 'Page', 'body' => 'Body.']);

        $version = KnowledgePageVersion::factory()->create([
            'page_id' => $page->id,
            'title' => 'My version',
            'body' => '# Markdown **content**',
            'editor_id' => $owner->id,
        ]);

        $detail = GetKnowledgePageVersion::run($page, $version, $owner);

        $this->assertSame($version->id, $detail['id']);
        $this->assertSame('My version', $detail['title']);
        $this->assertSame('# Markdown **content**', $detail['body']);
        $this->assertSame($owner->name, $detail['editor']);
        $this->assertSame($version->created_at->toISOString(), $detail['createdAt']);
    }

    public function test_handle_fails_if_version_belongs_to_another_page(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $pageA = $this->createPage($workspace, $owner, ['title' => 'Page A', 'body' => 'A.']);
        $pageB = $this->createPage($workspace, $owner, ['title' => 'Page B', 'body' => 'B.']);

        $versionOfB = KnowledgePageVersion::factory()->create([
            'page_id' => $pageB->id,
            'editor_id' => $owner->id,
        ]);

        $this->expectException(NotFoundHttpException::class);

        GetKnowledgePageVersion::run($pageA, $versionOfB, $owner);
    }

    public function test_handle_respects_guest_scope(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $inScope = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $outOfScope = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $inScopePage = $this->createPage($workspace, $owner, [
            'title' => 'Client page', 'body' => 'Body.', 'project_id' => $inScope->id,
        ]);
        $outOfScopePage = $this->createPage($workspace, $owner, [
            'title' => 'Internal page', 'body' => 'Body.', 'project_id' => $outOfScope->id,
        ]);

        $version = KnowledgePageVersion::factory()->create([
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

        $this->expectException(ModelNotFoundException::class);

        GetKnowledgePageVersion::run($outOfScopePage, $version, $guest);
    }

    public function test_handle_allows_guest_for_in_scope_page(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $inScope = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);

        $inScopePage = $this->createPage($workspace, $owner, [
            'title' => 'Client page', 'body' => 'Body.', 'project_id' => $inScope->id,
        ]);

        $version = KnowledgePageVersion::factory()->create([
            'page_id' => $inScopePage->id,
            'title' => 'Version title',
            'body' => '# Version body',
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

        $detail = GetKnowledgePageVersion::run($inScopePage, $version, $guest);

        $this->assertSame($version->id, $detail['id']);
        $this->assertSame('Version title', $detail['title']);
        $this->assertSame('# Version body', $detail['body']);
    }
}
