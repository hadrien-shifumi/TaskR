<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Projects\GetMilestoneHealth;
use App\Actions\Projects\GetProjectHealth;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MilestoneHealthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Setup: workspace with default statuses, a project.
     *
     * @return array{0: User, 1: Workspace, 2: Project, 3: array<string, Status>}
     */
    private function setupProjectWithStatuses(): array
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

    /**
     * Create a milestone on the project with explicit attributes.
     */
    private function createMilestone(Project $project, array $attributes = []): Milestone
    {
        return Milestone::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'title' => $attributes['title'] ?? 'Milestone',
            'start_date' => $attributes['start_date'] ?? null,
            'target_date' => $attributes['target_date'] ?? null,
            'completed_at' => $attributes['completed_at'] ?? null,
        ]);
    }

    /**
     * Create an issue attached to the milestone with a given status category.
     */
    private function createIssueInMilestone(Project $project, Milestone $milestone, array $statuses, string $category, string $title = 'Issue'): Issue
    {
        return CreateIssue::run(app(WorkspaceContext::class)->getOrFail(), [
            'project_id' => $project->id,
            'title' => $title,
            'status_id' => $statuses[$category]->id,
            'milestone_id' => $milestone->id,
        ]);
    }

    /**
     * Set created_at and target_date on a project (bypassing fillable).
     */
    private function setProjectDates(Project $project, string $createdAt, ?string $targetDate): Project
    {
        \DB::table('projects')->where('id', $project->id)->update([
            'created_at' => $createdAt,
            'target_date' => $targetDate,
        ]);

        return $project->fresh();
    }

    private function firstRow(Project $project, ?Carbon $today = null): array
    {
        $health = GetMilestoneHealth::run($project, $today);

        $this->assertCount(1, $health->milestones);

        return $health->milestones[0];
    }

    // ── Expected percentage ──────────────────────────────────────────

    public function test_expected_percentage_is_linear_between_start_and_target(): void
    {
        $this->travelTo('2026-11-27 12:00:00');
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $this->createMilestone($project, [
            'start_date' => '2026-11-17',
            'target_date' => '2026-12-27',
        ]);

        // 10 days elapsed over a 40-day window → 25 %.
        $row = $this->firstRow($project);

        $this->assertSame(25.0, $row['expectedPercentage']);
    }

    public function test_expected_percentage_falls_back_to_project_created_at_without_start_date(): void
    {
        $this->travelTo('2026-11-27 12:00:00');
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        // Created 10 days ago, no start date → the project creation date is the start.
        $project = $this->setProjectDates($project, '2026-11-17 09:00:00', null);

        $this->createMilestone($project, [
            'target_date' => '2026-12-27',
        ]);

        $row = $this->firstRow($project);

        $this->assertSame(25.0, $row['expectedPercentage']);
    }

    public function test_expected_percentage_is_100_when_target_on_or_before_start(): void
    {
        $this->travelTo('2026-11-27 12:00:00');
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $this->createMilestone($project, [
            'start_date' => '2026-11-27',
            'target_date' => '2026-11-27',
        ]);

        $row = $this->firstRow($project);

        $this->assertSame(100.0, $row['expectedPercentage']);
    }

    public function test_expected_percentage_is_null_without_target_date(): void
    {
        $this->travelTo('2026-11-27 12:00:00');
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $this->createMilestone($project, [
            'start_date' => '2026-11-17',
        ]);

        $row = $this->firstRow($project);

        $this->assertNull($row['expectedPercentage']);
        $this->assertNull($row['status']);
        $this->assertFalse($row['inLatePanel']);
    }

    public function test_expected_percentage_is_clamped_to_bounds(): void
    {
        $this->travelTo('2026-11-27 12:00:00');
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        // Past target → 100 % (clamped).
        $this->createMilestone($project, [
            'title' => 'Past',
            'start_date' => '2026-11-17',
            'target_date' => '2026-11-22',
        ]);

        // Start in the future → 0 % (clamped).
        $this->createMilestone($project, [
            'title' => 'Future',
            'start_date' => '2026-11-30',
            'target_date' => '2026-12-10',
        ]);

        $health = GetMilestoneHealth::run($project);

        $this->assertSame(100.0, $health->milestones[0]['expectedPercentage']);
        $this->assertSame(0.0, $health->milestones[1]['expectedPercentage']);
    }

    public function test_today_is_injectable(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $this->createMilestone($project, [
            'start_date' => '2026-11-17',
            'target_date' => '2026-12-27',
        ]);

        // Same window, two "todays": 10 days elapsed → 25 %, 5 days → 12.5 %.
        $row = GetMilestoneHealth::run($project, Carbon::parse('2026-11-27 12:00:00'))->milestones[0];
        $this->assertSame(25.0, $row['expectedPercentage']);

        $row = GetMilestoneHealth::run($project, Carbon::parse('2026-11-22 12:00:00'))->milestones[0];
        $this->assertSame(12.5, $row['expectedPercentage']);
    }

    // ── Real percentage ──────────────────────────────────────────────

    public function test_real_percentage_counts_cancelled_in_denominator_only(): void
    {
        $this->travelTo('2026-11-27 12:00:00');
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $milestone = $this->createMilestone($project, ['target_date' => '2026-12-27']);

        $this->createIssueInMilestone($project, $milestone, $statuses, 'done', 'Done 1');
        $this->createIssueInMilestone($project, $milestone, $statuses, 'backlog', 'Open 1');
        $this->createIssueInMilestone($project, $milestone, $statuses, 'cancelled', 'Cancelled 1');

        $row = $this->firstRow($project);

        // 1 done over 3 unarchived (cancelled in the denominator, never the numerator).
        $this->assertSame(3, $row['totalIssues']);
        $this->assertSame(1, $row['doneIssues']);
        $this->assertSame(33.3, $row['realPercentage']);
    }

    public function test_real_percentage_is_null_when_no_tickets(): void
    {
        $this->travelTo('2026-11-27 12:00:00');
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $this->createMilestone($project, ['target_date' => '2026-12-27']);

        $row = $this->firstRow($project);

        $this->assertSame(0, $row['totalIssues']);
        $this->assertNull($row['realPercentage']);
    }

    // ── Badge rules ──────────────────────────────────────────────────

    public function test_badge_is_null_and_not_in_late_panel_when_milestone_has_no_tickets(): void
    {
        $this->travelTo('2026-11-27 12:00:00');
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        // Past target, but no tickets → no badge, never in the late panel.
        $this->createMilestone($project, ['target_date' => '2026-11-20']);

        $row = $this->firstRow($project);

        $this->assertNull($row['status']);
        $this->assertFalse($row['inLatePanel']);
    }

    public function test_badge_achieved_when_closed_on_time(): void
    {
        $this->travelTo('2026-11-27 12:00:00');
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $milestone = $this->createMilestone($project, [
            'target_date' => '2026-11-27',
            'completed_at' => '2026-11-27 10:00:00',
        ]);

        $this->createIssueInMilestone($project, $milestone, $statuses, 'done');

        $row = $this->firstRow($project);

        $this->assertSame('achieved', $row['status']);
        $this->assertFalse($row['inLatePanel']);
    }

    public function test_badge_missed_when_closed_late_but_not_in_late_panel(): void
    {
        $this->travelTo('2026-11-27 12:00:00');
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $milestone = $this->createMilestone($project, [
            'target_date' => '2026-11-26',
            'completed_at' => '2026-11-27 10:00:00',
        ]);

        $this->createIssueInMilestone($project, $milestone, $statuses, 'done');

        $row = $this->firstRow($project);

        // Closed after the end of the target day → missed, but out of the late panel.
        $this->assertSame('missed', $row['status']);
        $this->assertFalse($row['inLatePanel']);
    }

    public function test_badge_achieved_when_not_closed_and_all_done(): void
    {
        $this->travelTo('2026-11-27 12:00:00');
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $milestone = $this->createMilestone($project, [
            'target_date' => '2026-11-20',
        ]);

        $this->createIssueInMilestone($project, $milestone, $statuses, 'done');

        $row = $this->firstRow($project);

        // All tickets done → achieved, even though the target date is past.
        $this->assertSame('achieved', $row['status']);
        $this->assertFalse($row['inLatePanel']);
    }

    public function test_badge_missed_and_in_late_panel_when_target_passed_and_not_complete(): void
    {
        $this->travelTo('2026-11-27 12:00:00');
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $milestone = $this->createMilestone($project, [
            'target_date' => '2026-11-26',
        ]);

        $this->createIssueInMilestone($project, $milestone, $statuses, 'done', 'Done 1');
        $this->createIssueInMilestone($project, $milestone, $statuses, 'backlog', 'Open 1');

        $row = $this->firstRow($project);

        $this->assertSame('missed', $row['status']);
        $this->assertTrue($row['inLatePanel']);
    }

    public function test_badge_upcoming_when_target_is_today_or_future(): void
    {
        $this->travelTo('2026-11-27 12:00:00');
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        // Target day itself is still on time.
        $milestone = $this->createMilestone($project, [
            'title' => 'Today',
            'target_date' => '2026-11-27',
        ]);
        $this->createIssueInMilestone($project, $milestone, $statuses, 'done', 'Done 1');
        $this->createIssueInMilestone($project, $milestone, $statuses, 'backlog', 'Open 1');

        // Target in the future.
        $milestone2 = $this->createMilestone($project, [
            'title' => 'Future',
            'target_date' => '2026-12-01',
        ]);
        $this->createIssueInMilestone($project, $milestone2, $statuses, 'done', 'Done 2');
        $this->createIssueInMilestone($project, $milestone2, $statuses, 'backlog', 'Open 2');

        $health = GetMilestoneHealth::run($project);

        $this->assertSame('upcoming', $health->milestones[0]['status']);
        $this->assertFalse($health->milestones[0]['inLatePanel']);
        $this->assertSame('upcoming', $health->milestones[1]['status']);
        $this->assertFalse($health->milestones[1]['inLatePanel']);
    }

    public function test_late_panel_contains_only_unclosed_missed_milestones(): void
    {
        $this->travelTo('2026-11-27 12:00:00');
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        // Closed late → missed, but out of the late panel.
        $closedLate = $this->createMilestone($project, [
            'title' => 'Closed late',
            'target_date' => '2026-11-26',
            'completed_at' => '2026-11-27 10:00:00',
        ]);
        $this->createIssueInMilestone($project, $closedLate, $statuses, 'done');

        // Unclosed, target passed, not complete → in the late panel.
        $unclosedMissed = $this->createMilestone($project, [
            'title' => 'Unclosed missed',
            'target_date' => '2026-11-26',
        ]);
        $this->createIssueInMilestone($project, $unclosedMissed, $statuses, 'done', 'Done 1');
        $this->createIssueInMilestone($project, $unclosedMissed, $statuses, 'backlog', 'Open 1');

        // Upcoming → never in the panel.
        $upcoming = $this->createMilestone($project, [
            'title' => 'Upcoming',
            'target_date' => '2026-12-01',
        ]);
        $this->createIssueInMilestone($project, $upcoming, $statuses, 'done', 'Done 1');
        $this->createIssueInMilestone($project, $upcoming, $statuses, 'backlog', 'Open 1');

        // Empty → never in the panel.
        $this->createMilestone($project, [
            'title' => 'Empty',
            'target_date' => '2026-11-26',
        ]);

        // Unclosed, target passed, all done → achieved, never in the panel.
        $unclosedAchieved = $this->createMilestone($project, [
            'title' => 'Unclosed achieved',
            'target_date' => '2026-11-26',
        ]);
        $this->createIssueInMilestone($project, $unclosedAchieved, $statuses, 'done');

        $health = GetMilestoneHealth::run($project);

        $inLatePanel = collect($health->milestones)->where('inLatePanel', true)->pluck('title')->all();

        $this->assertSame(['Unclosed missed'], $inLatePanel);
    }

    // ── Sorting, empty project, perimeter, payload unchanged ─────────

    public function test_milestones_are_sorted_by_target_date_then_title_with_undated_last(): void
    {
        $this->travelTo('2026-11-27 12:00:00');
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $this->createMilestone($project, ['title' => 'B-target-Dec1', 'target_date' => '2026-12-01']);
        $this->createMilestone($project, ['title' => 'A-target-Dec1', 'target_date' => '2026-12-01']);
        $this->createMilestone($project, ['title' => 'Z-target-Nov1', 'target_date' => '2026-11-01']);
        $this->createMilestone($project, ['title' => 'D-undated']);
        $this->createMilestone($project, ['title' => 'A-undated']);

        $health = GetMilestoneHealth::run($project);

        $this->assertSame(
            ['Z-target-Nov1', 'A-target-Dec1', 'B-target-Dec1', 'A-undated', 'D-undated'],
            collect($health->milestones)->pluck('title')->all(),
        );
    }

    public function test_empty_project_has_empty_milestone_health(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $health = GetMilestoneHealth::run($project);

        $this->assertSame([], $health->milestones);
    }

    public function test_global_health_output_is_unchanged_when_milestones_exist(): void
    {
        $this->travelTo('2026-11-27 12:00:00');
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $project = $this->setProjectDates(
            $project,
            '2026-11-17 09:00:00',
            '2026-12-27',
        );

        CreateIssue::run(app(WorkspaceContext::class)->getOrFail(), [
            'project_id' => $project->id,
            'title' => 'Done 1',
            'status_id' => $statuses['done']->id,
        ]);
        CreateIssue::run(app(WorkspaceContext::class)->getOrFail(), [
            'project_id' => $project->id,
            'title' => 'Open 1',
            'status_id' => $statuses['backlog']->id,
        ]);

        $before = GetProjectHealth::run($project)->toArray();

        // Attach issues to milestones — the global indicators must not move.
        $milestone = $this->createMilestone($project, ['target_date' => '2026-12-27']);
        $issue = Issue::where('project_id', $project->id)->where('title', 'Done 1')->firstOrFail();
        $issue->update(['milestone_id' => $milestone->id]);

        $after = GetProjectHealth::run($project)->toArray();

        $this->assertSame($before, $after);

        $map = app(GetProjectHealth::class)->handleForProjects([$project]);
        $this->assertSame($before, $map[$project->id]->toArray());
    }

    public function test_manager_receives_milestone_health_via_http(): void
    {
        $this->travelTo('2026-11-27 12:00:00');
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $milestone = $this->createMilestone($project, ['title' => 'V1', 'target_date' => '2026-12-27']);
        $this->createIssueInMilestone($project, $milestone, $statuses, 'backlog');

        $this->actingAs($owner)
            ->get(route('projects.health', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Health')
                ->has('milestoneHealth.milestones', 1)
                ->where('milestoneHealth.milestones.0.id', $milestone->id)
                ->where('milestoneHealth.milestones.0.title', 'V1')
                ->where('milestoneHealth.milestones.0.status', 'upcoming')
                ->where('milestoneHealth.milestones.0.totalIssues', 1)
                ->where('milestoneHealth.milestones.0.realPercentage', 0));
    }

    public function test_member_receives_no_milestone_health(): void
    {
        $this->travelTo('2026-11-27 12:00:00');
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);

        $this->createMilestone($project, ['target_date' => '2026-12-27']);

        $this->actingAs($member)
            ->get(route('projects.health', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Health')
                ->where('canSeeGlobalKpis', false)
                ->where('milestoneHealth', null)
                ->has('myWorkload'));
    }
}
