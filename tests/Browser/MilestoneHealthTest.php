<?php

use App\Actions\Issues\CreateIssue;
use App\Actions\Milestones\CloseMilestone;
use App\Actions\Milestones\CreateMilestone;
use App\Actions\Milestones\SetIssueMilestone;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;

/**
 * Browser fixture of the milestone-health scenarios: an owner with a
 * project, statuses keyed by category, ready to seed milestones with
 * dates, closure and attached issues.
 *
 * @return array{owner: User, workspace: Workspace, project: Project, statuses: array<string, Status>}
 */
function seedMilestoneHealthScenario(): array
{
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);

    $statuses = Status::withoutWorkspaceScope()
        ->where('workspace_id', $workspace->id)
        ->get()
        ->keyBy(fn (Status $status) => $status->category->value);

    return [
        'owner' => $owner,
        'workspace' => $workspace,
        'project' => $project,
        'statuses' => $statuses,
    ];
}

/** Creates an issue in the project and attaches it to the milestone. */
function attachIssueToHealthMilestone(Workspace $workspace, Project $project, Milestone $milestone, $statuses, string $category, string $title, User $owner): Issue
{
    $issue = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => $title,
        'status_id' => $statuses[$category]->id,
    ]);
    SetIssueMilestone::run($issue, $milestone->id, $owner);

    return $issue;
}

it('renders the per-milestone trajectory with badges, real percentages and the late panel absent', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'project' => $project, 'statuses' => $statuses] = seedMilestoneHealthScenario();

    // Version 1: started a month ago, target a month ahead, 1 done + 1 open issue.
    $version = CreateMilestone::run($project, [
        'title' => 'Version 1',
        'start_date' => now()->subMonth()->toDateString(),
        'target_date' => now()->addMonth()->toDateString(),
    ], $owner);
    attachIssueToHealthMilestone($workspace, $project, $version, $statuses, 'done', 'Done issue', $owner);
    attachIssueToHealthMilestone($workspace, $project, $version, $statuses, 'backlog', 'Open issue', $owner);

    // Sprint 1: no dates, no issues — no badge, expected "—".
    $sprint = CreateMilestone::run($project, ['title' => 'Sprint 1'], $owner);

    // Closed on time: target today, closed now → achieved.
    $closedOnTime = CreateMilestone::run($project, [
        'title' => 'Closed on time',
        'target_date' => now()->toDateString(),
    ], $owner);
    attachIssueToHealthMilestone($workspace, $project, $closedOnTime, $statuses, 'done', 'Done 2', $owner);
    CloseMilestone::run($closedOnTime, $owner);

    // Closed late: target yesterday, closed now → missed, never in the late panel.
    $closedLate = CreateMilestone::run($project, [
        'title' => 'Closed late',
        'target_date' => now()->subDay()->toDateString(),
    ], $owner);
    attachIssueToHealthMilestone($workspace, $project, $closedLate, $statuses, 'done', 'Done 3', $owner);
    CloseMilestone::run($closedLate, $owner);

    // All done: target yesterday, not closed, every ticket done → achieved.
    $allDone = CreateMilestone::run($project, [
        'title' => 'All done',
        'target_date' => now()->subDay()->toDateString(),
    ], $owner);
    attachIssueToHealthMilestone($workspace, $project, $allDone, $statuses, 'done', 'Done 4', $owner);

    $this->actingAs($owner);

    $page = visit('/projects/'.$project->id.'/health');

    $page->assertPresent('[data-test="milestone-health-section"]');

    // Version 1: badge Upcoming, real 50%, expected present (not "—").
    $versionRow = "[data-test='milestone-health-row'][data-milestone-id='{$version->id}']";
    $page->assertPresent($versionRow);
    $page->assertSeeIn($versionRow.' [data-test="milestone-health-badge"]', 'Upcoming');
    $page->assertSeeIn($versionRow, '50%');
    $page->assertPresent($versionRow.' [title="Expected pace from the start to the target"]');

    // Sprint 1: no badge, expected "—".
    $sprintRow = "[data-test='milestone-health-row'][data-milestone-id='{$sprint->id}']";
    $page->assertPresent($sprintRow);
    $page->assertNotPresent($sprintRow.' [data-test="milestone-health-badge"]');
    $page->assertNotPresent($sprintRow.' [title="Expected pace from the start to the target"]');
    $page->assertNotPresent($sprintRow.' [title="Done issues / unarchived issues"]');

    // Closed on time → Achieved.
    $page->assertSeeIn("[data-test='milestone-health-row'][data-milestone-id='{$closedOnTime->id}'] [data-test='milestone-health-badge']", 'Achieved');

    // Closed late → Missed.
    $page->assertSeeIn("[data-test='milestone-health-row'][data-milestone-id='{$closedLate->id}'] [data-test='milestone-health-badge']", 'Missed');

    // All done → Achieved.
    $page->assertSeeIn("[data-test='milestone-health-row'][data-milestone-id='{$allDone->id}'] [data-test='milestone-health-badge']", 'Achieved');

    // No missed-and-open milestone → no late panel (the closed-late one is excluded too).
    $page->assertNotPresent('[data-test="late-milestones"]');

    $page->assertNoSmoke();
});

it('creates a milestone from the tab, attaches an issue, shows it in the late panel and links to its fiche', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'project' => $project] = seedMilestoneHealthScenario();
    $this->actingAs($owner);

    $page = visit('/projects/'.$project->id.'/milestones');

    // Creation through the Échéances tab: target date yesterday.
    $page->click('@milestone-create-open');
    $page->fill('@milestone-title', 'Sprint 0');
    $page->fill('@milestone-target', now()->subDay()->toDateString());
    $page->click('[data-test="form-dialog-submit"]');
    $page->waitForEvent('networkidle');
    $page->assertSee('Milestone created.');

    app(WorkspaceContext::class)->set($workspace);
    $issue = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Attach me',
    ]);

    // Attachment from the issue fiche.
    $issuePage = visit('/issues/'.$issue->id);
    $issuePage->click('@milestone-dialog-open');
    $issuePage->click('@milestone-select');
    $issuePage->click('[role="option"]:has-text("Sprint 0")');
    $issuePage->click('@milestone-submit');
    $issuePage->waitForEvent('networkidle');
    $issuePage->assertSeeIn('@issue-milestone', 'Sprint 0');

    app(WorkspaceContext::class)->set($workspace);
    $milestone = $project->milestones()->where('title', 'Sprint 0')->firstOrFail();

    // Health: the row carries the Missed badge and the late panel lists Sprint 0.
    $healthPage = visit('/projects/'.$project->id.'/health');
    $healthPage->assertSeeIn("[data-test='milestone-health-row'][data-milestone-id='{$milestone->id}'] [data-test='milestone-health-badge']", 'Missed');
    $healthPage->assertSeeIn("[data-test='late-milestones'] [data-test='late-milestone-row'][data-milestone-id='{$milestone->id}']", 'Sprint 0');

    // The row title links to the milestone fiche.
    $healthPage->click("[data-test='milestone-health-row'][data-milestone-id='{$milestone->id}'] a:has-text('Sprint 0')");
    $healthPage->waitForEvent('networkidle');
    $healthPage->assertPathIs('/milestones/'.$milestone->id);

    $healthPage->assertNoSmoke();
});

it('shows the empty state when the project has no milestone', function () {
    ['owner' => $owner, 'project' => $project] = seedMilestoneHealthScenario();
    $this->actingAs($owner);

    $page = visit('/projects/'.$project->id.'/health');

    $page->assertSeeIn('[data-test="milestone-health-empty"]', 'No milestone yet.');
    $page->assertNotPresent('[data-test="milestone-health-row"]');
    $page->assertNotPresent('[data-test="late-milestones"]');

    $page->assertNoSmoke();
});

it('shows a guest only the my-workload view and the read-only milestones tab', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'project' => $project] = seedMilestoneHealthScenario();

    $guest = User::factory()->create();
    Membership::create([
        'user_id' => $guest->id,
        'workspace_id' => $workspace->id,
        'role' => 'guest',
    ]);
    ProjectMember::create([
        'project_id' => $project->id,
        'user_id' => $guest->id,
        'role' => 'guest',
    ]);

    // A milestone for the guest to see in the tab (created by the owner).
    CreateMilestone::run($project, [
        'title' => 'Guest visible milestone',
        'target_date' => now()->addMonth()->toDateString(),
    ], $owner);

    $this->actingAs($guest);

    $page = visit('/projects/'.$project->id.'/health');
    $page->assertVisible('@my-workload');
    $page->assertNotPresent('[data-test="milestone-health-section"]');
    $page->assertNotPresent('[data-test="milestone-health-badge"]');
    $page->assertNotPresent('[data-test="late-milestones"]');

    // The milestones tab stays read-only: the milestone is visible, no create button.
    $milestonesPage = visit('/projects/'.$project->id.'/milestones');
    $milestonesPage->assertSee('Guest visible milestone');
    $milestonesPage->assertNotPresent('@milestone-create-open');

    $milestonesPage->assertNoSmoke();
});
