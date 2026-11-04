<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
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
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Knowledge pages exposed on the web issue fiche (issues.show): the payload
 * must match the MCP get_issue contract — trashed pages included, and a
 * guest only sees the pages of their perimeter.
 */
class IssueKnowledgePageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Workspace with an Alpha and a Beta project, plus an issue of Alpha.
     *
     * @return array{0: User, 1: Workspace, 2: Project, 3: Project, 4: Issue}
     */
    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $beta = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $issue = CreateIssue::run($workspace, ['project_id' => $alpha->id, 'title' => 'Ticket Alpha']);

        return [$owner, $workspace, $alpha, $beta, $issue];
    }

    /**
     * Guest scoped to Alpha only.
     */
    private function createGuestScopedTo(Workspace $workspace, Project $project): User
    {
        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $guest->id,
            'role' => 'member',
        ]);

        return $guest;
    }

    public function test_issue_page_guest_ne_voit_pas_les_pages_hors_perimetre(): void
    {
        [$owner, $workspace, $alpha, $beta, $issue] = $this->setupWorkspace();

        $alphaPage = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'project_id' => $alpha->id,
            'title' => 'Alpha page',
        ]);
        $betaPage = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'project_id' => $beta->id,
            'title' => 'Beta page',
        ]);
        $transversePage = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'project_id' => null,
            'title' => 'Transverse page',
        ]);

        $issue->knowledgePages()->attach([$alphaPage->id, $betaPage->id, $transversePage->id]);

        $guest = $this->createGuestScopedTo($workspace, $alpha);

        $response = $this->actingAs($guest)->get(route('issues.show', $issue->id));

        $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('issue.knowledgePages', [
                ['id' => $alphaPage->id, 'title' => 'Alpha page', 'projectId' => $alpha->id, 'trashed' => false],
            ]));

        // No leak of the out-of-perimeter nor the transverse page titles.
        $response->assertDontSee('Beta page');
        $response->assertDontSee('Transverse page');

        // A full member keeps the whole set.
        $this->actingAs($owner)->get(route('issues.show', $issue->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issue.knowledgePages', 3));
    }

    public function test_issue_page_expose_les_projets_scopes_pour_les_badges(): void
    {
        [$owner, $workspace, $alpha, $beta, $issue] = $this->setupWorkspace();

        // The project badges of the knowledge section need the workspace's
        // project names: a full member can name every project of the workspace.
        $this->actingAs($owner)->get(route('issues.show', $issue->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('projects', [
                    ['id' => $alpha->id, 'name' => 'Alpha'],
                    ['id' => $beta->id, 'name' => 'Beta'],
                ]));

        // A guest only gets the names of their perimeter's projects.
        $guest = $this->createGuestScopedTo($workspace, $alpha);

        $this->actingAs($guest)->get(route('issues.show', $issue->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('projects', [
                    ['id' => $alpha->id, 'name' => 'Alpha'],
                ]));
    }

    public function test_issue_page_expose_les_pages_en_corbeille(): void
    {
        [$owner, $workspace, $alpha, , $issue] = $this->setupWorkspace();

        $livePage = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'project_id' => $alpha->id,
            'title' => 'Alpha page',
        ]);
        $trashedPage = KnowledgePage::factory()->trashed()->create([
            'workspace_id' => $workspace->id,
            'project_id' => $alpha->id,
            'title' => 'Miked page',
        ]);

        $issue->knowledgePages()->attach([$livePage->id, $trashedPage->id]);

        $this->actingAs($owner)->get(route('issues.show', $issue->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('issue.knowledgePages', [
                    ['id' => $livePage->id, 'title' => 'Alpha page', 'projectId' => $alpha->id, 'trashed' => false],
                    ['id' => $trashedPage->id, 'title' => 'Miked page', 'projectId' => $alpha->id, 'trashed' => true],
                ]));
    }
}
