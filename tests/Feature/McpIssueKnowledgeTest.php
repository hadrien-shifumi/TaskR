<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\GetIssue;
use App\Mcp\Tools\ListIssues;
use App\Mcp\Tools\SetIssueKnowledge;
use App\Models\Issue;
use App\Models\KnowledgePage;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class McpIssueKnowledgeTest extends TestCase
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

    public function test_mcp_set_issue_knowledge_sync(): void
    {
        [$owner, $workspace, $project, $issue] = $this->setupWorkspace();

        $first = KnowledgePage::factory()->create(['workspace_id' => $workspace->id, 'title' => 'First page']);
        $second = KnowledgePage::factory()->create(['workspace_id' => $workspace->id, 'title' => 'Second page']);
        $third = KnowledgePage::factory()->create(['workspace_id' => $workspace->id, 'title' => 'Third page']);

        $result = $this->syncKnowledge($owner, $workspace, $issue, [$first->id, $second->id])->assertOk()->payload();

        // The payload is the full issue body, not a bare id list.
        $this->assertSame($issue->id, $result['issue']['id']);
        $this->assertSame($issue->title, $result['issue']['title']);
        $this->assertSame($project->id, $result['issue']['projectId']);
        $this->assertSame($workspace->id, $result['issue']['workspaceId']);

        $this->assertDatabaseHas('issue_knowledge_pages', ['issue_id' => $issue->id, 'knowledge_page_id' => $first->id]);
        $this->assertDatabaseHas('issue_knowledge_pages', ['issue_id' => $issue->id, 'knowledge_page_id' => $second->id]);

        // A second call replaces the whole set. The context is re-set because
        // every tool call flushes it on its way out.
        $this->syncKnowledge($owner, $workspace, $issue, [$second->id, $third->id])->assertOk();

        $this->assertDatabaseMissing('issue_knowledge_pages', ['issue_id' => $issue->id, 'knowledge_page_id' => $first->id]);
        $this->assertDatabaseHas('issue_knowledge_pages', ['issue_id' => $issue->id, 'knowledge_page_id' => $second->id]);
        $this->assertDatabaseHas('issue_knowledge_pages', ['issue_id' => $issue->id, 'knowledge_page_id' => $third->id]);
        $this->assertDatabaseCount('issue_knowledge_pages', 2);

        // An empty array unlinks every page.
        $result = $this->syncKnowledge($owner, $workspace, $issue, [])->assertOk()->payload();

        $this->assertSame($issue->id, $result['issue']['id']);
        $this->assertDatabaseCount('issue_knowledge_pages', 0);
    }

    public function test_mcp_set_issue_knowledge_guest_scope(): void
    {
        [$owner, $workspace, $inScope, $outOfScope, $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        $inScopePage = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'project_id' => $inScope->id,
        ]);
        $outOfScopePage = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'project_id' => $outOfScope->id,
        ]);

        // A page outside the guest's perimeter is refused with a field error.
        $payload = $this->syncKnowledge($guest, $workspace, $inScopeIssue, [$outOfScopePage->id])->payload();

        $this->assertSame('validation_failed', $payload['error']);
        $this->assertArrayHasKey('page_ids', $payload['fields']);
        $this->assertStringContainsStringIgnoringCase('outside your access scope', $payload['fields']['page_ids'][0]);
        $this->assertDatabaseCount('issue_knowledge_pages', 0);

        // The out-of-perimeter issue itself is not found: no existence leak.
        $payload = $this->syncKnowledge($guest, $workspace, $outOfScopeIssue, [])->payload();

        $this->assertSame('not_found', $payload['error']);
        $this->assertArrayNotHasKey('message', $payload);

        // Within the perimeter the guest may link pages.
        $this->syncKnowledge($guest, $workspace, $inScopeIssue, [$inScopePage->id])->assertOk();

        $this->assertDatabaseHas('issue_knowledge_pages', [
            'issue_id' => $inScopeIssue->id,
            'knowledge_page_id' => $inScopePage->id,
        ]);
    }

    public function test_mcp_get_issue_retourne_knowledge_pages(): void
    {
        [$owner, $workspace, $project, $issue] = $this->setupWorkspace();

        $projectPage = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Alpha page',
        ]);
        $transversePage = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'project_id' => null,
            'title' => 'Zulu page',
        ]);
        $trashedPage = KnowledgePage::factory()->trashed()->create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Miked page',
        ]);

        $issue->knowledgePages()->attach([$projectPage->id, $transversePage->id, $trashedPage->id]);

        $payload = TaskrServer::actingAs($owner)->tool(GetIssue::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
        ])->assertOk()->payload()['issue'];

        // Stable order by title, whatever the pivot returns.
        $this->assertSame([
            ['id' => $projectPage->id, 'title' => 'Alpha page', 'projectId' => $project->id, 'trashed' => false],
            ['id' => $trashedPage->id, 'title' => 'Miked page', 'projectId' => $project->id, 'trashed' => true],
            ['id' => $transversePage->id, 'title' => 'Zulu page', 'projectId' => null, 'trashed' => false],
        ], $payload['knowledgePages']);

        // The light list stays light: no knowledge pages there.
        $listed = TaskrServer::actingAs($owner)->tool(ListIssues::class, [
            'workspace_id' => $workspace->id,
        ])->assertOk()->payload()['issues'];

        $this->assertNotEmpty($listed);
        $this->assertArrayNotHasKey('knowledgePages', $listed[0]);
    }

    public function test_mcp_get_issue_guest_ne_voit_pas_pages_hors_perimetre(): void
    {
        [$owner, $workspace, $inScope, $outOfScope, $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        $inScopePage = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'project_id' => $inScope->id,
            'title' => 'Alpha page',
        ]);
        $outOfScopePage = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'project_id' => $outOfScope->id,
            'title' => 'Beta page',
        ]);
        $transversePage = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'project_id' => null,
            'title' => 'Transverse page',
        ]);

        $inScopeIssue->knowledgePages()->attach([$inScopePage->id, $outOfScopePage->id, $transversePage->id]);

        // Out-of-perimeter pages are silently dropped: no error, no leak.
        $payload = TaskrServer::actingAs($guest)->tool(GetIssue::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $inScopeIssue->id,
        ])->assertOk()->payload()['issue'];

        $this->assertSame(
            [['id' => $inScopePage->id, 'title' => 'Alpha page', 'projectId' => $inScope->id, 'trashed' => false]],
            $payload['knowledgePages'],
        );

        // The out-of-perimeter issue stays not found.
        $payload = TaskrServer::actingAs($guest)->tool(GetIssue::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $outOfScopeIssue->id,
        ])->payload();

        $this->assertSame('not_found', $payload['error']);
    }

    public function test_mcp_set_issue_knowledge_refuses_a_trashed_page(): void
    {
        [$owner, $workspace, , $issue] = $this->setupWorkspace();

        $trashedPage = KnowledgePage::factory()->trashed()->create(['workspace_id' => $workspace->id]);

        $payload = $this->syncKnowledge($owner, $workspace, $issue, [$trashedPage->id])->payload();

        $this->assertSame('validation_failed', $payload['error']);
        $this->assertArrayHasKey('page_ids.0', $payload['fields']);
        $this->assertDatabaseCount('issue_knowledge_pages', 0);
    }

    public function test_mcp_set_issue_knowledge_ignores_another_workspace_page(): void
    {
        [$owner, $workspace, , $issue] = $this->setupWorkspace();

        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Other']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $foreignPage = KnowledgePage::factory()->create(['workspace_id' => $otherWorkspace->id]);
        $foreignProject = CreateProject::run($otherWorkspace, $otherOwner, ['name' => 'Foreign']);
        $foreignIssue = CreateIssue::run($otherWorkspace, ['project_id' => $foreignProject->id, 'title' => 'Foreign issue']);

        $payload = $this->syncKnowledge($owner, $workspace, $issue, [$foreignPage->id])->payload();

        $this->assertSame('validation_failed', $payload['error']);
        $this->assertArrayHasKey('page_ids.0', $payload['fields']);
        $this->assertDatabaseCount('issue_knowledge_pages', 0);

        // An issue of the other workspace fails exactly like a missing one.
        $payload = $this->syncKnowledge($owner, $workspace, $foreignIssue, [])->payload();

        $this->assertSame('not_found', $payload['error']);
        $this->assertArrayNotHasKey('message', $payload);
    }

    /**
     * @param  array<int, string>  $pageIds
     */
    private function syncKnowledge(User $user, Workspace $workspace, Issue $issue, array $pageIds): TestResponse
    {
        return TaskrServer::actingAs($user)->tool(SetIssueKnowledge::class, [
            'workspace_id' => $workspace->id,
            'issue_id' => $issue->id,
            'page_ids' => $pageIds,
        ]);
    }

    /**
     * @return array{0: User, 1: Workspace, 2: Project, 3: Issue}
     */
    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);
        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Linked ticket']);

        return [$owner, $workspace, $project, $issue];
    }

    /**
     * Guest scoped to one project, with a second project out of reach.
     *
     * @return array{0: User, 1: Workspace, 2: Project, 3: Project, 4: Issue, 5: Issue, 6: User}
     */
    private function setupScopedGuest(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $inScope = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $outOfScope = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $inScopeIssue = CreateIssue::run($workspace, ['project_id' => $inScope->id, 'title' => 'Dans le perimetre']);
        $outOfScopeIssue = CreateIssue::run($workspace, ['project_id' => $outOfScope->id, 'title' => 'Hors perimetre']);

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

        return [$owner, $workspace, $inScope, $outOfScope, $inScopeIssue, $outOfScopeIssue, $guest];
    }
}
