<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Milestones\CreateMilestone;
use App\Actions\Projects\CreateProject;
use App\Actions\SavedViews\CreateSavedView;
use App\Actions\Workspaces\CreateWorkspace;
use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\ListIssues;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Milestone;
use App\Models\ProjectMember;
use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class IssueMilestoneFilterTest extends TestCase
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

    /**
     * The canonical fixtures: two projects, one milestone each, issues
     * attached on both sides plus one milestone-less issue. The signed-in
     * actor is the workspace owner (unrestricted perimeter).
     *
     * @return array{
     *     user: User,
     *     workspace: Workspace,
     *     attached: Milestone,
     *     attachedIssue: Issue,
     *     other: Milestone,
     *     otherIssue: Issue,
     *     plainIssue: Issue,
     * }
     */
    private function setupFixture(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $beta = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $attached = CreateMilestone::run($alpha, ['title' => 'V1'], $owner);
        $other = CreateMilestone::run($beta, ['title' => 'V2'], $owner);

        $attachedIssue = CreateIssue::run($workspace, [
            'project_id' => $alpha->id,
            'title' => 'Sur V1',
            'milestone_id' => $attached->id,
        ]);
        $otherIssue = CreateIssue::run($workspace, [
            'project_id' => $beta->id,
            'title' => 'Sur V2',
            'milestone_id' => $other->id,
        ]);
        $plainIssue = CreateIssue::run($workspace, [
            'project_id' => $alpha->id,
            'title' => 'Sans jalon',
        ]);

        return [
            'user' => $owner,
            'workspace' => $workspace,
            'attached' => $attached,
            'attachedIssue' => $attachedIssue,
            'other' => $other,
            'otherIssue' => $otherIssue,
            'plainIssue' => $plainIssue,
        ];
    }

    /**
     * A guest rostered on the Beta project only, one workspace membership
     * line on top of the fixture (the owner stays signed out here).
     *
     * @param  array{user: User, workspace: Workspace, attached: Milestone, attachedIssue: Issue, other: Milestone, otherIssue: Issue, plainIssue: Issue}  $fixture
     * @return array{0: User, 1: Workspace, 2: Milestone}
     */
    private function setupBetaGuest(array $fixture): array
    {
        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $fixture['workspace']->id,
            'role' => 'guest',
        ]);
        ProjectMember::create([
            'project_id' => $fixture['other']->project_id,
            'user_id' => $guest->id,
            'role' => 'member',
        ]);

        return [$guest, $fixture['workspace'], $fixture['other']];
    }

    public function test_issues_page_milestone_filter_narrows_the_list(): void
    {
        $fixture = $this->setupFixture();

        $this->actingAs($fixture['user'])
            ->get(route('issues.index', ['milestone' => $fixture['attached']->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 1)
                ->where('issues.0.id', $fixture['attachedIssue']->id)
                ->where('filters.milestone', $fixture['attached']->id));
    }

    public function test_issues_page_invalid_milestone_param_is_ignored(): void
    {
        $fixture = $this->setupFixture();

        // A shared URL must never 422: a malformed value is silently
        // dropped, like every other issues-page filter.
        $this->actingAs($fixture['user'])
            ->get(route('issues.index', ['milestone' => 'not-a-ulid']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 3)
                ->has('filters', fn (AssertableInertia $filters) => $filters->missing('milestone')));
    }

    public function test_guest_sees_only_accessible_project_milestones_in_options(): void
    {
        $fixture = $this->setupFixture();
        [$guest] = $this->setupBetaGuest($fixture);

        // The guest's perimeter covers Beta only: the selector offers that
        // project's milestone, never Alpha's.
        $this->actingAs($guest)
            ->get(route('issues.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('milestones', 1)
                ->where('milestones.0.id', $fixture['other']->id));

        // The owner sees both.
        $this->actingAs($fixture['user'])
            ->get(route('issues.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('milestones', 2));
    }

    public function test_kanban_view_honors_the_milestone_filter(): void
    {
        $fixture = $this->setupFixture();

        // The kanban keeps one board per accessible project; the jalon
        // filter narrows the cards: only the Alpha ticket stays, the
        // Beta board is empty.
        $this->actingAs($fixture['user'])
            ->get(route('issues.index', ['view' => 'kanban', 'milestone' => $fixture['attached']->id]), [
                'X-Inertia-Partial-Component' => 'Issues/Index',
                'X-Inertia-Partial-Data' => 'projectBoards',
            ])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('projectBoards', 2)
                ->has('projectBoards.0', fn (AssertableInertia $board) => $board
                    ->where('project.name', 'Alpha')
                    ->etc()
                    ->where('columns', fn ($columns) => collect($columns)->sum('issuesCount') === 1))
                ->has('projectBoards.1', fn (AssertableInertia $board) => $board
                    ->where('project.name', 'Beta')
                    ->etc()
                    ->where('columns', fn ($columns) => collect($columns)->sum('issuesCount') === 0)));
    }

    /**
     * Parse a CSV body (BOM stripped) into rows of cells.
     *
     * @return list<list<string>>
     */
    private function parseCsv(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        $lines = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $content) ?: [],
            fn (string $line): bool => $line !== '',
        ));

        return array_map(fn (string $line): array => str_getcsv($line, ',', '"', '\\'), $lines);
    }

    public function test_export_follows_the_milestone_filter(): void
    {
        $fixture = $this->setupFixture();

        $response = $this->actingAs($fixture['user'])
            ->get(route('issues.export', ['milestone' => $fixture['attached']->id]));

        $ids = array_map(
            fn (array $row): string => $row[0],
            array_slice($this->parseCsv($response->streamedContent()), 1),
        );

        $this->assertSame([$fixture['attachedIssue']->id], $ids);

        // Same for the JSON export.
        $rows = json_decode(
            $this->actingAs($fixture['user'])
                ->get(route('issues.export', ['format' => 'json', 'milestone' => $fixture['attached']->id]))
                ->streamedContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame([$fixture['attachedIssue']->id], array_column($rows, 'id'));
    }

    public function test_out_of_scope_milestone_matches_nothing_for_a_guest(): void
    {
        $fixture = $this->setupFixture();
        [$guest] = $this->setupBetaGuest($fixture);

        // Alpha is out of the guest's perimeter: its jalon can only give
        // an empty list, never a leakage of the Alpha ticket.
        $this->actingAs($guest)
            ->get(route('issues.index', ['milestone' => $fixture['attached']->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 0)
                ->where('total', 0));
    }

    public function test_saved_view_stores_the_milestone_filter_and_summarizes_it(): void
    {
        $fixture = $this->setupFixture();
        $fixture['user']->update(['locale' => 'fr']);
        app()->setLocale('fr');

        $this->actingAs($fixture['user'])
            ->post('/views', [
                'name' => 'Sprint V1',
                'milestone' => $fixture['attached']->id,
            ])
            ->assertRedirect(route('issues.index', ['milestone' => $fixture['attached']->id]));

        $view = SavedView::query()->where('name', 'Sprint V1')->firstOrFail();

        $this->assertSame(['milestone' => $fixture['attached']->id], $view->filters);
        $this->assertSame('Jalon : V1', $view->summary);
    }

    public function test_saved_view_drops_invalid_milestone(): void
    {
        $fixture = $this->setupFixture();

        $this->actingAs($fixture['user'])
            ->post('/views', [
                'name' => 'Jalon pourri',
                'milestone' => 'abc',
            ])
            ->assertRedirect();

        $view = SavedView::query()->where('name', 'Jalon pourri')->firstOrFail();

        $this->assertNull($view->filters);
        $this->assertNull($view->summary);
    }

    public function test_saved_view_summary_hides_out_of_scope_milestone(): void
    {
        $fixture = $this->setupFixture();
        [$guest] = $this->setupBetaGuest($fixture);

        app()->setLocale('fr');

        // The owner creates a view naming Alpha's milestone; the guest
        // (scoped to Beta) reads a summary recomputed without the jalon.
        CreateSavedView::run($fixture['workspace'], $fixture['user'], [
            'name' => 'Vue jalonnée',
            'filters' => ['milestone' => $fixture['attached']->id],
            'sort' => null,
            'dir' => null,
        ]);

        $this->actingAs($guest)
            ->get(route('issues.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('savedViews.0.name', 'Vue jalonnée')
                ->where('savedViews.0.summary', null));

        // The owner's stored copy keeps the jalon.
        $view = SavedView::query()->where('name', 'Vue jalonnée')->firstOrFail();
        $this->assertSame('Jalon : V1', $view->summary);
    }

    public function test_mcp_list_issues_filters_by_milestone(): void
    {
        $fixture = $this->setupFixture();

        $listed = TaskrServer::actingAs($fixture['user'])
            ->tool(ListIssues::class, [
                'workspace_id' => $fixture['workspace']->id,
                'milestone' => $fixture['attached']->id,
            ])
            ->assertOk()
            ->payload();

        $this->assertSame([$fixture['attachedIssue']->id], array_column($listed['issues'], 'id'));
        $this->assertSame(1, $listed['total']);
    }
}
