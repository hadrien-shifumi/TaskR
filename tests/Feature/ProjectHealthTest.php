<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\MoveIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Projects\GetProjectHealth;
use App\Actions\TimeTracking\CreateTimeEntry;
use App\Actions\TimeTracking\SetIssueEstimate;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ProjectHealth;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProjectHealthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Setup: workspace with default statuses, a project, and issues.
     *
     * @return array{0: User, 1: Workspace, 2: Project, 3: array<string, Status>}
     */
    private function setupProjectWithStatuses(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, ['name' => 'Site vitrine']);

        $statuses = [];
        foreach ($workspace->statuses as $status) {
            $statuses[$status->category->value] = $status;
        }

        return [$owner, $workspace, $project, $statuses];
    }

    /**
     * Create an issue in a project with a given status category.
     */
    private function createIssue(Project $project, array $statuses, string $category, string $title = 'Issue', ?string $dueDate = null): Issue
    {
        return CreateIssue::run(app(WorkspaceContext::class)->getOrFail(), [
            'project_id' => $project->id,
            'title' => $title,
            'status_id' => $statuses[$category]->id,
            'due_date' => $dueDate,
        ]);
    }

    /**
     * Set created_at and target_date on a project (bypassing fillable).
     */
    private function setProjectDates(Project $project, string $createdAt, ?string $targetDate): Project
    {
        DB::table('projects')->where('id', $project->id)->update([
            'created_at' => $createdAt,
            'target_date' => $targetDate,
        ]);

        return $project->fresh();
    }

    // ── 5.1 Tests de la formule de projection ───────────────────────

    public function test_project_without_target_date_has_no_projection(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();
        $this->createIssue($project, $statuses, 'backlog');

        $health = GetProjectHealth::run($project);

        $this->assertNull($health->status);
        $this->assertNull($health->expectedPercentage);
    }

    public function test_project_in_time_is_ok(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        // Created 10 days ago, target in 90 days → expected ~10%.
        $project = $this->setProjectDates(
            $project,
            now()->subDays(10)->toDateTimeString(),
            now()->addDays(90)->toDateString(),
        );

        $this->createIssue($project, $statuses, 'done', 'Done 1');
        $this->createIssue($project, $statuses, 'done', 'Done 2');
        for ($i = 1; $i <= 8; $i++) {
            $this->createIssue($project, $statuses, 'backlog', "Todo {$i}");
        }

        $health = GetProjectHealth::run($project);

        $this->assertSame('ok', $health->status);
        $this->assertSame(20.0, $health->completionPercentage);
    }

    public function test_project_at_risk_when_behind_expected(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        // Created 50 days ago, target in 50 days → expected ~50%.
        $project = $this->setProjectDates(
            $project,
            now()->subDays(50)->toDateTimeString(),
            now()->addDays(50)->toDateString(),
        );

        // Only 10% done (1 of 10).
        $this->createIssue($project, $statuses, 'done', 'Done 1');
        for ($i = 2; $i <= 10; $i++) {
            $this->createIssue($project, $statuses, 'backlog', "Todo {$i}");
        }

        $health = GetProjectHealth::run($project);

        $this->assertSame('at-risk', $health->status);
        $this->assertSame(10.0, $health->completionPercentage);
        $this->assertNotNull($health->expectedPercentage);
        $this->assertGreaterThan(40.0, $health->expectedPercentage);
        $this->assertLessThan(60.0, $health->expectedPercentage);
    }

    public function test_project_late_when_target_date_passed_and_incomplete(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        // Target date was yesterday.
        $project = $this->setProjectDates(
            $project,
            now()->subDays(30)->toDateTimeString(),
            now()->subDay()->toDateString(),
        );

        // 30% done.
        $this->createIssue($project, $statuses, 'done', 'Done 1');
        $this->createIssue($project, $statuses, 'done', 'Done 2');
        $this->createIssue($project, $statuses, 'done', 'Done 3');
        for ($i = 1; $i <= 7; $i++) {
            $this->createIssue($project, $statuses, 'backlog', "Todo {$i}");
        }

        $health = GetProjectHealth::run($project);

        $this->assertSame('late', $health->status);
        $this->assertSame(30.0, $health->completionPercentage);
    }

    public function test_project_ok_when_target_date_passed_but_100_percent_complete(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $project = $this->setProjectDates(
            $project,
            now()->subDays(30)->toDateTimeString(),
            now()->subDay()->toDateString(),
        );

        $this->createIssue($project, $statuses, 'done', 'Done 1');
        $this->createIssue($project, $statuses, 'done', 'Done 2');
        $this->createIssue($project, $statuses, 'cancelled', 'Cancelled 1');

        $health = GetProjectHealth::run($project);

        $this->assertSame('ok', $health->status);
        $this->assertSame(100.0, $health->completionPercentage);
    }

    public function test_expected_percentage_is_clamped_to_bounds(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        // Target date in the past → expected = 100% (clamped).
        $project = $this->setProjectDates(
            $project,
            now()->subDays(10)->toDateTimeString(),
            now()->subDays(5)->toDateString(),
        );

        $this->createIssue($project, $statuses, 'backlog');

        $health = GetProjectHealth::run($project);

        $this->assertSame(100.0, $health->expectedPercentage);
        $this->assertSame('late', $health->status);
    }

    public function test_project_without_issues_is_ok(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $project = $this->setProjectDates(
            $project,
            now()->toDateTimeString(),
            now()->addDays(30)->toDateString(),
        );

        $health = GetProjectHealth::run($project);

        $this->assertSame('ok', $health->status);
        $this->assertSame(0, $health->totalIssues);
        $this->assertNull($health->completionPercentage);
    }

    public function test_target_date_equal_or_before_created_at_means_expected_100(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        // target_date <= created_at → expected = 100% immediately.
        $project = $this->setProjectDates(
            $project,
            now()->subDays(10)->toDateTimeString(),
            now()->subDays(10)->toDateString(),
        );

        $this->createIssue($project, $statuses, 'backlog');

        $health = GetProjectHealth::run($project);

        $this->assertSame(100.0, $health->expectedPercentage);
        $this->assertSame('late', $health->status);
    }

    // ── 5.2 Tests des indicateurs avec fixtures ─────────────────────

    public function test_category_counts_and_completion_percentage(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        // 3 backlog, 2 in_progress, 4 done, 1 cancelled = 10 issues, 50% complete.
        for ($i = 0; $i < 3; $i++) {
            $this->createIssue($project, $statuses, 'backlog', "Backlog {$i}");
        }
        for ($i = 0; $i < 2; $i++) {
            $this->createIssue($project, $statuses, 'in_progress', "In Progress {$i}");
        }
        for ($i = 0; $i < 4; $i++) {
            $this->createIssue($project, $statuses, 'done', "Done {$i}");
        }
        $this->createIssue($project, $statuses, 'cancelled', 'Cancelled 1');

        $health = GetProjectHealth::run($project);

        $this->assertSame(3, $health->issuesByCategory['backlog']);
        $this->assertSame(2, $health->issuesByCategory['in_progress']);
        $this->assertSame(4, $health->issuesByCategory['done']);
        $this->assertSame(1, $health->issuesByCategory['cancelled']);
        $this->assertSame(10, $health->totalIssues);
        $this->assertSame(50.0, $health->completionPercentage);
    }

    public function test_overdue_issues_are_counted(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        // One overdue in_progress issue.
        $this->createIssue($project, $statuses, 'in_progress', 'Overdue', now()->subDay()->toDateString());
        // One overdue done issue (not counted).
        $this->createIssue($project, $statuses, 'done', 'Done but late', now()->subDay()->toDateString());
        // One not overdue.
        $this->createIssue($project, $statuses, 'backlog', 'Future', now()->addDay()->toDateString());

        $health = GetProjectHealth::run($project);

        $this->assertSame(1, $health->overdueIssues);
    }

    public function test_consumption_ratio_is_calculated(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $issue1 = $this->createIssue($project, $statuses, 'in_progress', 'Issue 1');
        $issue2 = $this->createIssue($project, $statuses, 'in_progress', 'Issue 2');

        SetIssueEstimate::run($issue1, 6.0);
        SetIssueEstimate::run($issue2, 4.0);

        CreateTimeEntry::run($issue1, $owner, ['hours' => 5]);
        CreateTimeEntry::run($issue2, $owner, ['hours' => 3]);

        $health = GetProjectHealth::run($project);

        $this->assertSame(10.0, $health->estimatedHours);
        $this->assertSame(8.0, $health->loggedHours);
        $this->assertSame(80.0, $health->consumptionRatio);
    }

    public function test_consumption_ratio_is_null_when_no_estimates(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $issue = $this->createIssue($project, $statuses, 'in_progress');
        CreateTimeEntry::run($issue, $owner, ['hours' => 3]);

        $health = GetProjectHealth::run($project);

        $this->assertSame(0.0, $health->estimatedHours);
        $this->assertSame(3.0, $health->loggedHours);
        $this->assertNull($health->consumptionRatio);
    }

    // ── 5.3 Tests feature ───────────────────────────────────────────

    public function test_health_recalculates_when_issue_status_changes(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $project = $this->setProjectDates(
            $project,
            now()->subDays(50)->toDateTimeString(),
            now()->addDays(50)->toDateString(),
        );

        $issue = $this->createIssue($project, $statuses, 'backlog', 'Issue 1');
        $this->createIssue($project, $statuses, 'backlog', 'Issue 2');

        // Initially 0% → at-risk (expected ~50%).
        $health = GetProjectHealth::run($project);
        $this->assertSame('at-risk', $health->status);
        $this->assertSame(0.0, $health->completionPercentage);

        // Move one issue to done → 50% → should be ok (at expected pace).
        MoveIssue::run($issue, $statuses['done']->id);

        $health = GetProjectHealth::run($project);
        $this->assertSame(50.0, $health->completionPercentage);
        // 50% >= expected ~50% → ok (or close enough).
        $this->assertContains($health->status, ['ok', 'at-risk']);
    }

    public function test_member_can_view_project_health(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $this->createIssue($project, $statuses, 'backlog');

        $this->actingAs($member)
            ->get(route('projects.health', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Health')
                ->where('project.id', $project->id)
                ->where('project.name', $project->name)
                ->has('health')
                ->where('health.totalIssues', 1)
                ->where('health.issuesByCategory.backlog', 1));
    }

    public function test_cross_workspace_access_is_denied(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $intruder = User::factory()->create();
        CreateWorkspace::run($intruder, ['name' => 'Theirs']);

        $this->actingAs($intruder)
            ->get(route('projects.health', $project->id))
            ->assertNotFound();
    }

    // ── 5.4 Test feature : vue d'ensemble de la liste des projets ───

    public function test_projects_list_includes_health_data(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $project = $this->setProjectDates(
            $project,
            now()->toDateTimeString(),
            now()->addDays(30)->toDateString(),
        );

        $this->createIssue($project, $statuses, 'done', 'Done 1');
        $this->createIssue($project, $statuses, 'backlog', 'Todo 1');

        $project2 = CreateProject::run($workspace, ['name' => 'AAA Second project']);
        $this->createIssue($project2, $statuses, 'backlog', 'Todo A');

        $this->actingAs($owner)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Index')
                ->has('projects.active', 2)
                // Projects are ordered by name: "AAA Second project" comes first.
                ->where('projects.active.0.name', 'AAA Second project')
                ->where('projects.active.0.health.totalIssues', 1)
                ->where('projects.active.0.health.completionPercentage', 0)
                ->where('projects.active.0.health.status', null)
                ->where('projects.active.1.name', 'Site vitrine')
                ->where('projects.active.1.health.totalIssues', 2)
                ->where('projects.active.1.health.completionPercentage', 50)
                ->where('projects.active.1.health.status', 'ok')
                ->where('projects.active.1.targetDate', $project->target_date->toDateString())
            );
    }

    // ── Tests d'action AsObject ─────────────────────────────────────

    public function test_get_project_health_runs_as_object(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $this->createIssue($project, $statuses, 'backlog');

        $health = GetProjectHealth::run($project);

        $this->assertInstanceOf(ProjectHealth::class, $health);
        $this->assertSame(1, $health->totalIssues);
    }

    public function test_handle_for_projects_returns_health_for_all_projects(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $this->createIssue($project, $statuses, 'done');

        $project2 = CreateProject::run($workspace, ['name' => 'Empty project']);

        $projects = $workspace->projects()->get()->all();
        $healthMap = app(GetProjectHealth::class)->handleForProjects($projects);

        $this->assertCount(2, $healthMap);
        $this->assertArrayHasKey($project->id, $healthMap);
        $this->assertArrayHasKey($project2->id, $healthMap);
        $this->assertSame(1, $healthMap[$project->id]->totalIssues);
        $this->assertSame(0, $healthMap[$project2->id]->totalIssues);
    }

    // ── Tests target_date CRUD ──────────────────────────────────────

    public function test_target_date_can_be_set_on_create(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $this->actingAs($owner)
            ->post('/projects', [
                'name' => 'With target',
                'target_date' => '2026-12-31',
            ])
            ->assertRedirect(route('projects.index'));

        $this->assertDatabaseHas('projects', [
            'name' => 'With target',
        ]);

        $project = Project::where('name', 'With target')->firstOrFail();
        $this->assertSame('2026-12-31', $project->target_date->toDateString());
    }

    public function test_target_date_can_be_updated(): void
    {
        [$owner, $workspace, $project] = $this->setupProjectWithStatuses();

        $this->actingAs($owner)
            ->patch("/projects/{$project->id}", [
                'name' => $project->name,
                'target_date' => '2027-06-15',
            ])
            ->assertRedirect(route('projects.index'));

        $this->assertSame('2027-06-15', $project->fresh()->target_date->toDateString());
    }

    public function test_target_date_can_be_cleared(): void
    {
        [$owner, $workspace, $project] = $this->setupProjectWithStatuses();

        $project->update(['target_date' => '2026-12-31']);

        $this->actingAs($owner)
            ->patch("/projects/{$project->id}", [
                'name' => $project->name,
                'target_date' => null,
            ])
            ->assertRedirect(route('projects.index'));

        $this->assertNull($project->fresh()->target_date);
    }

    public function test_invalid_target_date_is_rejected(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $this->actingAs($owner)
            ->post('/projects', [
                'name' => 'Bad date',
                'target_date' => 'not-a-date',
            ])
            ->assertSessionHasErrors('target_date');
    }
}
