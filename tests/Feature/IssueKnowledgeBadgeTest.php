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
 * Badge « 📖 N » of the knowledge pages linked to an issue, on the list row
 * and the board cards (project board + kanban strip): the count and the
 * tooltip titles ride the compact card payloads, scoped for guests and
 * counting the trashed pages like the fiche does.
 */
class IssueKnowledgeBadgeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Owner + workspace with an Alpha and a Beta project, plus an Alpha issue.
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
     * Guest scoped to the given project.
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

    /**
     * Create a knowledge page (project-scoped or transverse) and link it.
     */
    private function linkPage(Issue $issue, Workspace $workspace, ?Project $project, string $title): KnowledgePage
    {
        $page = KnowledgePage::factory()->create([
            'workspace_id' => $workspace->id,
            'project_id' => $project?->id,
            'title' => $title,
        ]);
        $issue->knowledgePages()->attach($page->id);

        return $page;
    }

    public function test_badge_affiche_quant_n_superieur_a_0(): void
    {
        [$owner, $workspace, $alpha, , $issue] = $this->setupWorkspace();

        $this->linkPage($issue, $workspace, $alpha, 'Zeta page');
        $this->linkPage($issue, $workspace, null, 'Alpha page');

        // List row.
        $this->actingAs($owner)->get(route('issues.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('issues.0.knowledgePagesCount', 2)
                ->where('issues.0.knowledgePageTitles', ['Alpha page', 'Zeta page']));

        // Kanban strip card.
        $this->actingAs($owner)->get(route('issues.index', ['view' => 'kanban']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('projectBoards.0.columns.0.issues.0.knowledgePagesCount', 2)
                ->where('projectBoards.0.columns.0.issues.0.knowledgePageTitles', ['Alpha page', 'Zeta page']));

        // Project board card.
        $this->actingAs($owner)->get(route('projects.board', $alpha->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('columns.0.issues.0.knowledgePagesCount', 2)
                ->where('columns.0.issues.0.knowledgePageTitles', ['Alpha page', 'Zeta page']));
    }

    public function test_pas_de_badge_si_0(): void
    {
        [$owner, $workspace, $alpha, , $issue] = $this->setupWorkspace();

        $this->actingAs($owner)->get(route('issues.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('issues.0.knowledgePagesCount', 0)
                ->where('issues.0.knowledgePageTitles', []));

        $this->actingAs($owner)->get(route('projects.board', $alpha->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('columns.0.issues.0.knowledgePagesCount', 0)
                ->where('columns.0.issues.0.knowledgePageTitles', []));
    }

    public function test_count_scoping_guest(): void
    {
        [$owner, $workspace, $alpha, $beta, $issue] = $this->setupWorkspace();

        $this->linkPage($issue, $workspace, $alpha, 'Alpha page');
        $this->linkPage($issue, $workspace, $beta, 'Beta page');
        $this->linkPage($issue, $workspace, null, 'Transverse page');

        $guest = $this->createGuestScopedTo($workspace, $alpha);

        // A guest counts only the pages of their perimeter: the out-of-scope
        // and the transverse pages are excluded, titles included.
        $this->actingAs($guest)->get(route('issues.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('issues.0.knowledgePagesCount', 1)
                ->where('issues.0.knowledgePageTitles', ['Alpha page']));

        $this->actingAs($guest)->get(route('projects.board', $alpha->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('columns.0.issues.0.knowledgePagesCount', 1)
                ->where('columns.0.issues.0.knowledgePageTitles', ['Alpha page']));

        // A full member keeps the whole set.
        $this->actingAs($owner)->get(route('issues.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('issues.0.knowledgePagesCount', 3)
                ->where('issues.0.knowledgePageTitles', ['Alpha page', 'Beta page', 'Transverse page']));
    }

    public function test_compte_les_pages_en_corbeille(): void
    {
        [$owner, $workspace, $alpha, , $issue] = $this->setupWorkspace();

        $this->linkPage($issue, $workspace, $alpha, 'Alpha page');
        $trashed = $this->linkPage($issue, $workspace, $alpha, 'Mise au rebut');
        $trashed->delete();

        // The badge matches the fiche: trashed pages stay counted and titled.
        $this->actingAs($owner)->get(route('issues.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('issues.0.knowledgePagesCount', 2)
                ->where('issues.0.knowledgePageTitles', ['Alpha page', 'Mise au rebut']));

        $this->actingAs($owner)->get(route('projects.board', $alpha->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('columns.0.issues.0.knowledgePagesCount', 2));
    }
}
