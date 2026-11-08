<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class HomeDashboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Freeze the clock on Wednesday 2026-11-04 15:00: the current week
     * runs from Monday 2026-11-02 to Sunday 2026-11-08.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-04 15:00:00'));
    }

    /**
     * @return array{0: User, 1: Workspace, 2: Project, 3: array<string, Status>}
     */
    private function setupWorkspaceWithProject(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        $statuses = [];
        foreach ($workspace->statuses as $status) {
            $statuses[$status->category->value] = $status;
        }

        return [$owner, $workspace, $project, $statuses];
    }

    private function createIssue(Project $project, Status $status, string $title, array $extra = []): void
    {
        CreateIssue::run(app(WorkspaceContext::class)->getOrFail(), [
            'project_id' => $project->id,
            'title' => $title,
            'status_id' => $status->id,
            ...$extra,
        ]);
    }

    /**
     * A workspace with one project, its owner and an issue.
     *
     * @return array{0: User, 1: Workspace, 2: Issue}
     */
    private function setupIssue(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);
        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);
        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);

        return [$owner, $workspace, $issue];
    }

    /**
     * The dashboard's weekly counter, read from a fresh rendering of
     * the home page. Integral values arrive as JSON integers.
     */
    private function assertHomeHours(User $user, int|float $expected): void
    {
        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Home')
                ->where('stats.loggedHoursThisWeek', $expected));
    }

    // ── Compteurs et cartes du dashboard ─────────────────────────────

    public function test_home_shows_workspace_issue_counters(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();

        // 2 open (backlog + in progress), 1 done, 1 cancelled, 1 overdue open.
        $this->createIssue($project, $statuses['backlog'], 'B1');
        $this->createIssue($project, $statuses['in_progress'], 'IP1', ['due_date' => now()->subDay()->toDateString()]);
        $this->createIssue($project, $statuses['done'], 'D1');
        $this->createIssue($project, $statuses['cancelled'], 'C1');

        $this->actingAs($owner)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Home')
                ->where('stats.totalIssues', 4)
                ->where('stats.openIssues', 2)
                ->where('stats.overdueIssues', 1));
    }

    public function test_home_shows_project_counters(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        CreateProject::run($workspace, $owner, ['name' => 'Archivé']);

        $this->actingAs($owner)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stats.activeProjects', 2)
                ->where('stats.archivedProjects', 0));
    }

    public function test_home_lists_active_projects_with_health(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        $this->createIssue($project, $statuses['done'], 'D1');
        $this->createIssue($project, $statuses['backlog'], 'B1');

        $this->actingAs($owner)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('projects', 1)
                ->where('projects.0.id', $project->id)
                ->where('projects.0.name', 'Site vitrine')
                ->where('projects.0.health.totalIssues', 2)
                ->where('projects.0.health.completionPercentage', 50));
    }

    public function test_home_lists_my_assigned_issues(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithProject();
        $this->createIssue($project, $statuses['in_progress'], 'Mine');
        $issue = Issue::query()->where('title', 'Mine')->firstOrFail();
        $issue->assignees()->attach($owner);

        $this->actingAs($owner)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('myIssues', 1)
                ->where('myIssues.0.id', $issue->id)
                ->where('myIssues.0.title', 'Mine')
                ->where('myIssues.0.statusName', 'In Progress'));
    }

    // ── Compteur hebdomadaire : les trois chemins de saisie ──────────

    public function test_home_counter_counts_dialogue_entry(): void
    {
        [$owner, , $issue] = $this->setupIssue();

        // Fresh rendering before any entry: the week starts empty.
        $this->assertHomeHours($owner, 0);

        $this->actingAs($owner)
            ->from(route('issues.show', $issue->id))
            ->post(route('issues.time-entries.store', $issue->id), [
                'hours' => 4,
                'worked_at' => '2026-11-04',
                'note' => 'setup CI',
            ])
            ->assertRedirect(route('issues.show', $issue->id));

        // Another fresh rendering: the new entry is already counted.
        $this->assertHomeHours($owner, 4);
    }

    public function test_home_counter_counts_timer_entry(): void
    {
        [$owner, , $issue] = $this->setupIssue();

        $this->actingAs($owner)
            ->postJson(route('issues.timer.start', $issue->id))
            ->assertOk();

        $this->travel(150)->minutes();

        $this->actingAs($owner)
            ->postJson(route('timer.stop'))
            ->assertOk()
            ->assertJsonPath('stopped.roundedHours', 2.5);

        // The proposal is confirmed as a standard entry on the issue.
        $this->actingAs($owner)
            ->post(route('issues.time-entries.store', $issue->id), [
                'hours' => 2.5,
                'worked_at' => now()->toDateString(),
                'note' => 'from timer',
            ])
            ->assertRedirect();

        $this->assertHomeHours($owner, 2.5);
    }

    public function test_home_counter_counts_week_sheet_entry(): void
    {
        [$owner, , $issue] = $this->setupIssue();

        $this->actingAs($owner)
            ->postJson(route('time-entries.week.store'), [
                'week' => '2026-11-02',
                'lines' => [[
                    'issueId' => $issue->id,
                    'note' => 'sprint 12',
                    'days' => ['2026-11-02' => 2.5, '2026-11-03' => 1.25],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('created', 2);

        $this->assertHomeHours($owner, 3.75);
    }

    public function test_home_counter_week_bounds(): void
    {
        [$owner, , $issue] = $this->setupIssue();

        // Monday of the current week: counted.
        $this->actingAs($owner)
            ->post(route('issues.time-entries.store', $issue->id), [
                'hours' => 2,
                'worked_at' => '2026-11-02',
            ])
            ->assertRedirect();

        // Monday of the next week (a future week): not counted now.
        $this->actingAs($owner)
            ->post(route('issues.time-entries.store', $issue->id), [
                'hours' => 3,
                'worked_at' => '2026-11-09',
            ])
            ->assertRedirect();

        // Both entries exist; only the current week's one is counted.
        $this->assertDatabaseCount('time_entries', 2);
        $this->assertHomeHours($owner, 2);
    }
}
