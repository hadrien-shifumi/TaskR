<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\ProjectRole;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProjectIssuesListTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace, 2: Project}
     */
    private function setupWorkspaceWithProject(string $name = 'Site vitrine'): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, $owner, ['name' => $name]);

        return [$owner, $workspace, $project];
    }

    private function addWorkspaceMember(Workspace $workspace): User
    {
        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        return $member;
    }

    private function addProjectMember(Project $project, User $user, ProjectRole $role = ProjectRole::Member): void
    {
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'role' => $role->value,
        ]);
    }

    private function createIssue(Workspace $workspace, Project $project, string $title): Issue
    {
        return CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => $title,
        ]);
    }

    // ── Content ───────────────────────────────────────────────────────

    public function test_the_page_lists_only_this_project_issues(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $other = CreateProject::run($workspace, $owner, ['name' => 'Autre projet']);

        $first = $this->createIssue($workspace, $project, 'Premier ticket');
        $second = $this->createIssue($workspace, $project, 'Deuxieme ticket');
        $this->createIssue($workspace, $other, 'Ticket du voisin');

        $this->actingAs($owner)
            ->get(route('projects.issues', $project->id))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($first, $second) {
                $ids = collect($page->toArray()['props']['issues'])->pluck('id');
                // Newest first by default (created desc); both tickets of
                // the project ride the list, never the neighbor's.
                expect($ids)->toContain($first->id, $second->id)->toHaveCount(2);
            });
    }

    public function test_the_page_searches_by_title(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $this->createIssue($workspace, $project, 'Rapport annuel');
        $this->createIssue($workspace, $project, 'Compte client');

        $this->actingAs($owner)
            ->get(route('projects.issues', [$project->id, 'q' => 'ANNUEL']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 1)
                ->where('issues.0.title', 'Rapport annuel'));
    }

    public function test_the_page_searches_by_identifier_prefix(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $this->createIssue($workspace, $project, 'Premier');
        $target = $this->createIssue($workspace, $project, 'Deuxieme');

        $prefix = substr(strtoupper($target->id), 0, 7);

        $this->actingAs($owner)
            ->get(route('projects.issues', [$project->id, 'q' => $prefix]))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($target, $prefix) {
                // Issues created in the same millisecond share the
                // timestamp head of their ULID: the contract is that
                // every match starts with the prefix, target included.
                $ids = collect($page->toArray()['props']['issues'])->pluck('id');

                expect($ids)->toContain($target->id);
                $ids->each(fn ($id) => expect(str_starts_with(strtoupper($id), $prefix))->toBeTrue());
            });
    }

    public function test_the_page_reports_the_visible_and_total_counts(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $this->createIssue($workspace, $project, 'Alpha');
        $this->createIssue($workspace, $project, 'Alphabet');
        $this->createIssue($workspace, $project, 'Beta');

        $this->actingAs($owner)
            ->get(route('projects.issues', [$project->id, 'q' => 'alpha']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 2)
                ->where('total', 2)
                ->where('totalUnfiltered', 3));
    }

    // ── Pagination ────────────────────────────────────────────────────

    public function test_the_page_paginates_and_clamps_to_the_last_page(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();

        foreach (range(1, 55) as $index) {
            $this->createIssue($workspace, $project, "Ticket {$index}");
        }

        // First page: 50 issues.
        $this->actingAs($owner)
            ->get(route('projects.issues', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 50)
                ->where('page', 1)
                ->where('perPage', 50)
                ->where('total', 55));

        // Second page: the 5 remaining.
        $this->actingAs($owner)
            ->get(route('projects.issues', [$project->id, 'page' => 2]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 5)
                ->where('page', 2));

        // An out-of-range page clamps to the last one.
        $this->actingAs($owner)
            ->get(route('projects.issues', [$project->id, 'page' => 99]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 5)
                ->where('page', 2));
    }

    public function test_the_page_echoes_the_search_and_clamps_an_out_of_range_page(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();

        $this->actingAs($owner)
            ->get(route('projects.issues', [$project->id, 'q' => 'portail', 'page' => 2]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.q', 'portail')
                // An empty list has a single page: the requested page 2
                // clamps back to it, the URL never 404s.
                ->where('page', 1));
    }

    // ── Perimeter ─────────────────────────────────────────────────────

    public function test_the_page_is_denied_cross_workspace(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();

        $intruder = User::factory()->create();
        CreateWorkspace::run($intruder, ['name' => 'Theirs']);

        $this->actingAs($intruder)
            ->get(route('projects.issues', $project->id))
            ->assertNotFound();
    }

    public function test_the_page_follows_the_guest_perimeter(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithProject();
        $other = CreateProject::run($workspace, $owner, ['name' => 'Autre projet']);

        $guest = $this->addWorkspaceMember($workspace);
        // The guest sits on the roster of the other project only.
        $this->addProjectMember($other, $guest, ProjectRole::Guest);

        $this->createIssue($workspace, $project, 'Ticket visible');
        $this->createIssue($workspace, $other, 'Ticket du voisin');

        // Out of the guest's perimeter: the project reads as non-existent.
        $this->actingAs($guest)
            ->get(route('projects.issues', $project->id))
            ->assertNotFound();

        // Inside the perimeter: the guest reads the list, never the neighbor's.
        $this->actingAs($guest)
            ->get(route('projects.issues', $other->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 1)
                ->where('issues.0.title', 'Ticket du voisin'));
    }
}
