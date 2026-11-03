<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Knowledge\SearchKnowledge;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Issue;
use App\Models\KnowledgePage;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SearchIssueKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    /** Query vector: close to every seeded page vector. */
    private const QUERY_VECTOR = [1.0, 0.0];

    private const PAGE_VECTOR = [0.99, 0.141];

    protected function tearDown(): void
    {
        config()->set('knowledge.embeddings', [
            'base_url' => null,
            'api_key' => null,
            'model' => null,
        ]);

        parent::tearDown();
    }

    private function configureEmbeddings(): void
    {
        config()->set('knowledge.embeddings', [
            'base_url' => 'https://embeddings.test/v1',
            'api_key' => 'test-key',
            'model' => 'text-embedding-3-small',
        ]);

        Http::fake(function (Request $request) {
            return Http::response(['data' => [['embedding' => self::QUERY_VECTOR, 'index' => 0]]]);
        });
    }

    /**
     * Owner with one project and one issue.
     *
     * @return array{0: User, 1: Workspace, 2: Project, 3: Issue}
     */
    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Issue']);

        return [$owner, $workspace, $project, $issue];
    }

    /**
     * Guest scoped to one project, with a second project out of reach.
     *
     * @return array{0: User, 1: Workspace, 2: Issue, 3: Issue, 4: User}
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

        return [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest];
    }

    public function test_retourne_resultats_json(): void
    {
        $this->configureEmbeddings();
        [$owner, $workspace, $project, $issue] = $this->setupWorkspace();

        $page = KnowledgePage::factory()->vectorized(self::PAGE_VECTOR)->create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Processus de règlement',
            'body' => 'Le règlement des factures clients.',
        ]);

        $response = $this->actingAs($owner)
            ->getJson(route('issues.knowledge-search', $issue).'?q=facturation');

        $response->assertOk()
            ->assertJsonStructure(['results' => [['id', 'title', 'excerpt', 'score', 'projectId']]]);

        // The endpoint is a thin JSON mirror of SearchKnowledge: same
        // query, same ranking, same ids.
        $expected = SearchKnowledge::run($workspace, 'facturation', null, 10, $owner);

        $this->assertSame(
            array_column($expected, 'id'),
            array_column($response->json('results'), 'id'),
        );
        $this->assertSame($page->id, $response->json('results.0.id'));
        $this->assertSame($project->id, $response->json('results.0.projectId'));
    }

    public function test_guest_ne_voit_que_son_perimetre(): void
    {
        $this->configureEmbeddings();
        [$owner, $workspace, $inScopeIssue, , $guest] = $this->setupScopedGuest();

        KnowledgePage::factory()->vectorized(self::PAGE_VECTOR)->create([
            'workspace_id' => $workspace->id,
            'project_id' => $inScopeIssue->project_id,
            'title' => 'Page client',
            'body' => 'Contenu du perimetre.',
        ]);
        KnowledgePage::factory()->vectorized(self::PAGE_VECTOR)->create([
            'workspace_id' => $workspace->id,
            'project_id' => CreateProject::run($workspace, $owner, ['name' => 'Gamma'])->id,
            'title' => 'Page interne',
            'body' => 'Contenu interne.',
        ]);
        KnowledgePage::factory()->vectorized(self::PAGE_VECTOR)->create([
            'workspace_id' => $workspace->id,
            'project_id' => null,
            'title' => 'Page transverse',
            'body' => 'Contenu transverse.',
        ]);

        $this->actingAs($guest)
            ->getJson(route('issues.knowledge-search', $inScopeIssue).'?q=contenu')
            ->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.title', 'Page client');
    }

    public function test_project_id_hors_workspace_422(): void
    {
        $this->configureEmbeddings();
        [$owner, $workspace, , $issue] = $this->setupWorkspace();

        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Autre']);
        // BelongsToWorkspace auto-fills workspace_id from the context:
        // switch before creating in the other workspace, then switch back.
        app(WorkspaceContext::class)->set($otherWorkspace);
        $foreignProject = CreateProject::run($otherWorkspace, $otherOwner, ['name' => 'Etranger']);
        app(WorkspaceContext::class)->set($workspace);

        $this->actingAs($owner)
            ->getJson(route('issues.knowledge-search', $issue).'?q=facturation&project_id='.$foreignProject->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('project_id');

        // A guest passing a project_id inside the workspace but outside
        // their perimeter is rejected too (guard delegated to SearchKnowledge).
        [$guestOwner, $guestWorkspace, $inScopeIssue, , $guest] = $this->setupScopedGuest();
        $outOfScopeProject = CreateProject::run($guestWorkspace, $guestOwner, ['name' => 'Gamma']);

        $this->actingAs($guest)
            ->getJson(route('issues.knowledge-search', $inScopeIssue).'?q=contenu&project_id='.$outOfScopeProject->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('project_id');
    }

    public function test_q_max_500_et_limit_1_50(): void
    {
        $this->configureEmbeddings();
        [$owner, , , $issue] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->getJson(route('issues.knowledge-search', $issue))
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');

        $this->actingAs($owner)
            ->getJson(route('issues.knowledge-search', $issue).'?q='.str_repeat('a', 501))
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');

        $this->actingAs($owner)
            ->getJson(route('issues.knowledge-search', $issue).'?q=facturation&limit=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors('limit');

        $this->actingAs($owner)
            ->getJson(route('issues.knowledge-search', $issue).'?q=facturation&limit=51')
            ->assertStatus(422)
            ->assertJsonValidationErrors('limit');

        $this->actingAs($owner)
            ->getJson(route('issues.knowledge-search', $issue).'?q=facturation&limit=50')
            ->assertOk()
            ->assertJsonStructure(['results'])
            ->assertJsonMissing(['searchUnavailable' => true]);
    }

    public function test_issue_hors_perimetre_404(): void
    {
        $this->configureEmbeddings();
        [$owner, $workspace, , $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        // Guest probing an issue outside their project scope: 404, exactly
        // like a non-existent issue.
        $this->actingAs($guest)
            ->getJson(route('issues.knowledge-search', $outOfScopeIssue).'?q=contenu')
            ->assertNotFound();

        // Cross-workspace isolation: the issue binding 404s via WorkspaceScope.
        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Autre']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $otherProject = CreateProject::run($otherWorkspace, $otherOwner, ['name' => 'Etranger']);
        $foreignIssue = CreateIssue::run($otherWorkspace, ['project_id' => $otherProject->id, 'title' => 'Foreign']);
        app(WorkspaceContext::class)->set($workspace);

        // The main workspace owner is not a member of the other workspace:
        // the foreign issue must fail exactly like a non-existent one.
        $this->actingAs($owner)
            ->getJson(route('issues.knowledge-search', $foreignIssue->id).'?q=contenu')
            ->assertNotFound();
    }

    public function test_embeddings_non_configures_search_unavailable(): void
    {
        [$owner, $workspace, , $issue] = $this->setupWorkspace();

        // A vectorized page exists: the empty result set comes from the
        // degraded search, not from an empty knowledge base.
        KnowledgePage::factory()->vectorized(self::PAGE_VECTOR)->create([
            'workspace_id' => $workspace->id,
        ]);

        $this->actingAs($owner)
            ->getJson(route('issues.knowledge-search', $issue).'?q=facturation')
            ->assertOk()
            ->assertJsonPath('searchUnavailable', true)
            ->assertJsonCount(0, 'results');
    }

    public function test_corbeille_exclue_des_resultats(): void
    {
        $this->configureEmbeddings();
        [$owner, $workspace, $project, $issue] = $this->setupWorkspace();

        $active = KnowledgePage::factory()->vectorized(self::PAGE_VECTOR)->create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Page active',
            'body' => 'Contenu actif et indexé.',
        ]);
        $trashed = KnowledgePage::factory()->vectorized(self::PAGE_VECTOR)->create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Page en corbeille',
            'body' => 'Contenu supprimé mais encore indexé.',
        ]);

        // Soft-delete the second page — its embedding remains in the DB.
        $trashed->delete();

        $ids = array_column(
            $this->actingAs($owner)
                ->getJson(route('issues.knowledge-search', $issue).'?q=contenu')
                ->assertOk()
                ->json('results'),
            'id',
        );

        $this->assertContains($active->id, $ids);
        $this->assertNotContains($trashed->id, $ids);
    }
}
