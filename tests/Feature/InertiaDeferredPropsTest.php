<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Heavy props ride Inertia's deferred loading: the initial page payload
 * carries the shell only, and the client fetches the deferred props in a
 * parallel partial reload right after the first paint. Each test pins the
 * contract for one page: the prop must be absent from the initial payload
 * (the shell renders without it) and present on a partial request asking
 * for it.
 */
class InertiaDeferredPropsTest extends TestCase
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

    /**
     * Headers of a partial reload asking for the given props, as issued by
     * the Inertia client when it loads deferred props. The response stays
     * a regular page render (the client's own requests carry more headers,
     * but the props resolution only keys off these two).
     *
     * @param  list<string>  $only
     * @return array<string, string>
     */
    private function partial(string $component, array $only): array
    {
        return [
            'X-Inertia-Partial-Component' => $component,
            'X-Inertia-Partial-Data' => implode(',', $only),
        ];
    }

    public function test_the_shared_mentionables_prop_is_deferred(): void
    {
        [$owner] = $this->setupWorkspace();

        // The autocomplete source never blocks the first paint: it rides a
        // deferred prop fetched in parallel by the client.
        $this->actingAs($owner)
            ->get('/issues')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Issues/Index')
                ->missing('mentionables'));

        $this->actingAs($owner)
            ->get('/issues', $this->partial('Issues/Index', ['mentionables']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('mentionables')
                ->where('mentionables.0.type', 'user'));
    }

    public function test_the_issue_fiche_defers_its_heavy_props(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);
        $issue = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Une issue',
        ]);

        // The fiche shell (detail, statuses, members, labels) renders
        // immediately; the thread and the dependency candidates load in
        // parallel. The parent autocomplete no longer ships a full
        // candidate list: the client fetches live through the search
        // endpoint.
        $this->actingAs($owner)
            ->get("/issues/{$issue->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Issues/Show')
                ->has('issue')
                ->missing('comments')
                ->missing('activity')
                ->missing('dependencyCandidates'));

        $this->actingAs($owner)
            ->get("/issues/{$issue->id}", $this->partial('Issues/Show', ['comments', 'activity', 'dependencyCandidates']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('comments', 0)
                ->has('activity')
                ->has('dependencyCandidates', 0));
    }

    public function test_the_kanban_view_defers_its_project_boards(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'B1',
        ]);

        // The kanban shell (filters, statuses, projects) renders first;
        // the boards — the heaviest payload of the app — load in parallel.
        $this->actingAs($owner)
            ->get('/issues?view=kanban')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Issues/Index')
                ->where('view', 'kanban')
                ->has('statuses')
                ->missing('projectBoards'));

        $this->actingAs($owner)
            ->get('/issues?view=kanban', $this->partial('Issues/Index', ['projectBoards']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('projectBoards', 1)
                ->where('projectBoards.0.project.name', 'Site vitrine')
                ->has('projectBoards.0.columns'));
    }

    public function test_the_flash_banner_rides_partial_reloads(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        // A partial reload (e.g. the board syncing after a drag-and-drop
        // move, only: columns + health + flash) must still carry the flash
        // banner: the move confirmation lives there.
        $this->actingAs($owner)
            ->withSession(['status' => 'Issue moved.'])
            ->get("/projects/{$project->id}/board", [
                'X-Inertia-Partial-Component' => 'Projects/Board',
                'X-Inertia-Partial-Data' => 'columns,health,flash',
            ])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('flash.status', 'Issue moved.'));
    }
}
