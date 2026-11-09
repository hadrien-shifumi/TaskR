<?php

namespace Tests\Feature;

use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\KnowledgePage;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class KnowledgeProjectFilterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace, 2: Project, 3: Project, 4: KnowledgePage, 5: KnowledgePage, 6: KnowledgePage}
     */
    private function setupWorkspaceWithPages(): array
    {
        Queue::fake();

        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $projectA = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $projectB = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $pageA = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page Alpha', 'body' => 'Corps', 'project_id' => $projectA->id,
        ]);
        $pageB = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page Beta', 'body' => 'Corps', 'project_id' => $projectB->id,
        ]);
        $transverse = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page transverse', 'body' => 'Corps',
        ]);

        return [$owner, $workspace, $projectA, $projectB, $pageA, $pageB, $transverse];
    }

    public function test_filters_pages_by_project(): void
    {
        [$owner, , $projectA] = $this->setupWorkspaceWithPages();

        $this->actingAs($owner)
            ->get(route('knowledge.index', ['project' => $projectA->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Knowledge/Index')
                ->has('pages', 1)
                ->where('pages.0.title', 'Page Alpha')
                ->where('projectFilter', $projectA->id));
    }

    public function test_filters_pages_to_transverse_only(): void
    {
        [$owner] = $this->setupWorkspaceWithPages();

        $this->actingAs($owner)
            ->get(route('knowledge.index', ['project' => 'transverse']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Knowledge/Index')
                ->has('pages', 1)
                ->where('pages.0.title', 'Page transverse')
                ->where('projectFilter', 'transverse'));
    }

    public function test_bare_index_ships_an_empty_tree_until_a_scope_is_chosen(): void
    {
        [$owner] = $this->setupWorkspaceWithPages();

        $this->actingAs($owner)
            ->get(route('knowledge.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Knowledge/Index')
                ->has('pages', 0)
                ->has('projects', 2)
                ->where('projectFilter', null));
    }

    public function test_empty_project_param_behaves_like_no_scope_chosen(): void
    {
        [$owner] = $this->setupWorkspaceWithPages();

        $this->actingAs($owner)
            ->get(route('knowledge.index', ['project' => '']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Knowledge/Index')
                ->has('pages', 0)
                ->where('projectFilter', null));
    }

    public function test_deep_link_scopes_sidebar_to_the_opened_page_project(): void
    {
        [$owner, , $projectA, , $pageA] = $this->setupWorkspaceWithPages();

        $this->actingAs($owner)
            ->get(route('knowledge.show', $pageA->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Knowledge/Index')
                ->where('projectFilter', $projectA->id)
                ->has('pages', 1)
                ->where('pages.0.title', 'Page Alpha')
                ->where('selected.id', $pageA->id));
    }

    public function test_deep_link_to_transverse_page_scopes_to_transverse(): void
    {
        [$owner, , , , , , $transverse] = $this->setupWorkspaceWithPages();

        $this->actingAs($owner)
            ->get(route('knowledge.show', $transverse->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Knowledge/Index')
                ->where('projectFilter', 'transverse')
                ->has('pages', 1)
                ->where('pages.0.title', 'Page transverse')
                ->where('selected.id', $transverse->id));
    }

    public function test_versions_page_scopes_sidebar_to_the_page_project(): void
    {
        [$owner, , , , $pageA] = $this->setupWorkspaceWithPages();

        $this->actingAs($owner)
            ->get(route('knowledge.versions', $pageA->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Knowledge/Versions', false)
                ->where('page.id', $pageA->id)
                ->where('pages.0.title', 'Page Alpha')
                ->has('pages', 1));
    }

    public function test_versions_page_for_transverse_page_scopes_to_transverse(): void
    {
        [$owner, , , , , , $transverse] = $this->setupWorkspaceWithPages();

        $this->actingAs($owner)
            ->get(route('knowledge.versions', $transverse->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Knowledge/Versions', false)
                ->where('page.id', $transverse->id)
                ->where('pages.0.title', 'Page transverse')
                ->has('pages', 1));
    }

    public function test_passes_project_filter_to_inertia_payload(): void
    {
        [$owner, , $projectA] = $this->setupWorkspaceWithPages();

        $this->actingAs($owner)
            ->get(route('knowledge.index', ['project' => $projectA->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('projectFilter', $projectA->id)
                ->has('projects', 2));
    }

    public function test_unknown_project_filter_is_rejected(): void
    {
        [$owner] = $this->setupWorkspaceWithPages();

        $this->actingAs($owner)
            ->get(route('knowledge.index', ['project' => '01h0000000000000000000000000']))
            ->assertSessionHasErrors('project');
    }

    public function test_cross_workspace_project_filter_is_rejected(): void
    {
        [$owner, $workspace] = $this->setupWorkspaceWithPages();

        $stranger = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($stranger, ['name' => 'Other']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $foreignProject = CreateProject::run($otherWorkspace, $stranger, ['name' => 'Étranger']);
        app(WorkspaceContext::class)->set($workspace);

        $this->actingAs($owner)
            ->get(route('knowledge.index', ['project' => $foreignProject->id]))
            ->assertSessionHasErrors('project');
    }

    public function test_selected_page_still_resolves_when_outside_filter(): void
    {
        [$owner, , $projectA, , , $pageB] = $this->setupWorkspaceWithPages();

        $this->actingAs($owner)
            ->get(route('knowledge.show', ['page' => $pageB->id, 'project' => $projectA->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Knowledge/Index')
                ->has('pages', 1)
                ->where('pages.0.title', 'Page Alpha')
                ->where('selected.title', 'Page Beta'));
    }

    public function test_guest_filtering_by_out_of_scope_project_returns_empty(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $inScope = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $outOfScope = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page Alpha', 'body' => 'Corps', 'project_id' => $inScope->id,
        ]);
        CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page Beta', 'body' => 'Corps', 'project_id' => $outOfScope->id,
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

        $this->actingAs($guest)
            ->get(route('knowledge.index', ['project' => $outOfScope->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('pages', 0));
    }

    public function test_guest_filtering_by_transverse_returns_empty(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);

        CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page Alpha', 'body' => 'Corps', 'project_id' => $project->id,
        ]);
        CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page transverse', 'body' => 'Corps',
        ]);

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

        $this->actingAs($guest)
            ->get(route('knowledge.index', ['project' => 'transverse']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('pages', 0));
    }

    public function test_guest_filtering_by_in_scope_project_shows_only_that_project(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $inScope = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $otherInScope = CreateProject::run($workspace, $owner, ['name' => 'Gamma']);

        CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page Alpha', 'body' => 'Corps', 'project_id' => $inScope->id,
        ]);
        CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Page Gamma', 'body' => 'Corps', 'project_id' => $otherInScope->id,
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
        ProjectMember::create([
            'project_id' => $otherInScope->id,
            'user_id' => $guest->id,
            'role' => 'member',
        ]);

        $this->actingAs($guest)
            ->get(route('knowledge.index', ['project' => $inScope->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('pages', 1)
                ->where('pages.0.title', 'Page Alpha'));
    }
}
