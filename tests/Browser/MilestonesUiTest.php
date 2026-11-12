<?php

use App\Actions\Issues\CreateIssue;
use App\Actions\Milestones\CreateMilestone;
use App\Actions\Milestones\SetIssueMilestone;
use App\Actions\Milestones\SetMilestoneParent;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;

/**
 * Browser fixture of the milestone scenarios: an owner with a project
 * carrying a started "Version 1" (one done + one open issue attached)
 * and its child "Sprint 1".
 *
 * @return array{owner: User, workspace: Workspace, project: Project, statuses: array<string, Status>}
 */
function seedMilestoneScenario(): array
{
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);

    $statuses = Status::withoutWorkspaceScope()
        ->where('workspace_id', $workspace->id)
        ->get()
        ->keyBy(fn (Status $status) => $status->category->value);

    $version = CreateMilestone::run($project, [
        'title' => 'Version 1',
        'start_date' => now()->toDateString(),
        'target_date' => now()->addMonth()->toDateString(),
    ], $owner);
    $sprint = CreateMilestone::run($project, ['title' => 'Sprint 1'], $owner);
    SetMilestoneParent::run($sprint, $version->id, $owner);

    $done = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Terminer le paiement',
        'status_id' => $statuses['done']->id,
    ]);
    $open = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Page de profil',
    ]);
    SetIssueMilestone::run($done, $version->id, $owner);
    SetIssueMilestone::run($open, $version->id, $owner);

    return [
        'owner' => $owner,
        'workspace' => $workspace,
        'project' => $project,
        'statuses' => $statuses,
    ];
}

/** Measures the tree indentation: the child row sits deeper than its parent. */
const MILESTONE_INDENT_PROBE = <<<'JS'
    (() => {
        const rows = [...document.querySelectorAll('[data-test="milestone-row"]')];
        const pad = (title) => {
            const row = rows.find((r) => r.textContent.includes(title));
            return row === undefined ? null : parseFloat(getComputedStyle(row).paddingLeft);
        };
        return { parentPad: pad('Version 1'), childPad: pad('Sprint 1') };
    })()
    JS;

it('renders the milestones tab as an indented tree with state and progression', function () {
    ['owner' => $owner, 'project' => $project] = seedMilestoneScenario();
    $this->actingAs($owner);

    $page = visit('/projects/'.$project->id.'/milestones');

    $page->assertPresent('[data-test="milestones-list"]');
    $page->assertSeeIn('[data-test="milestone-row"]:has-text("Version 1") [data-test="milestone-state"]', 'In progress');
    $page->assertSeeIn('[data-test="milestone-row"]:has-text("Sprint 1") [data-test="milestone-state"]', 'Upcoming');
    $page->assertSeeIn('[data-test="milestone-row"]:has-text("Version 1") [data-test="milestone-progress"]', '1 / 2 issues done');

    // The flat list is rebuilt as a tree: the child is indented deeper.
    $indent = $page->script(MILESTONE_INDENT_PROBE);
    expect($indent['childPad'])->toBeGreaterThan($indent['parentPad']);

    $page->assertNoSmoke();
});

it('creates, renames and closes a milestone from the tab', function () {
    ['owner' => $owner, 'project' => $project] = seedMilestoneScenario();
    $this->actingAs($owner);

    $page = visit('/projects/'.$project->id.'/milestones');

    // Creation: title and target date are enough.
    $page->click('@milestone-create-open');
    $page->fill('@milestone-title', 'Beta launch');
    $page->fill('@milestone-target', now()->addYear()->toDateString());
    $page->click('[data-test="form-dialog-submit"]');
    $page->waitForEvent('networkidle');
    $page->assertSee('Milestone created.');
    $page->assertSee('Beta launch');

    // Rename through the row menu.
    $page->click('button[aria-label="Actions for Beta launch"]');
    $page->click('@milestone-edit');
    $page->fill('@milestone-title', 'Beta rollout');
    $page->click('[data-test="form-dialog-submit"]');
    $page->waitForEvent('networkidle');
    $page->assertSee('Milestone updated.');
    $page->assertSee('Beta rollout');

    // Close: the state badge turns to Closed.
    $page->click('button[aria-label="Actions for Beta rollout"]');
    $page->click('@milestone-close');
    $page->waitForEvent('networkidle');
    $page->assertSee('Milestone closed.');
    $page->assertSeeIn('[data-test="milestone-row"]:has-text("Beta rollout") [data-test="milestone-state"]', 'Closed');

    $page->assertNoSmoke();
});

it('refuses a parent that would create a cycle, with a danger banner', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'project' => $project] = seedMilestoneScenario();
    $this->actingAs($owner);

    $page = visit('/projects/'.$project->id.'/milestones');

    // Version 1's own child as parent: refused by the action. The
    // autocomplete searches the title live, the result is picked from the list.
    $page->click('button[aria-label="Actions for Version 1"]');
    $page->click('@milestone-set-parent');
    $page->fill('@milestone-parent-search-input', 'Sprint 1');
    $page->waitForEvent('networkidle');
    $page->click('[data-test="milestone-parent-search-result"]:has-text("Sprint 1")');
    $page->click('@milestone-parent-submit');

    // The 422 refusal surfaces as a dedicated banner inside the dialog.
    $page->waitForEvent('networkidle');
    $page->assertPresent('@milestone-parent-error');

    app(WorkspaceContext::class)->set($workspace);
    $version = $project->milestones()->where('title', 'Version 1')->firstOrFail();
    expect($version->fresh()->parent_id)->toBeNull();

    $page->assertNoSmoke();
});

it('sets and detaches the parent from the milestone dialog', function () {
    ['owner' => $owner, 'project' => $project] = seedMilestoneScenario();
    $this->actingAs($owner);

    $page = visit('/projects/'.$project->id.'/milestones');

    // Detach: Sprint 1 leaves Version 1's tree and becomes a root — the
    // indentation probe shows both rows at the same depth afterwards.
    $page->click('button[aria-label="Actions for Sprint 1"]');
    $page->click('@milestone-set-parent');
    $page->fill('@milestone-parent-search-input', '');
    $page->click('[data-test="milestone-parent-none"]');
    $page->click('@milestone-parent-submit');
    $page->waitForEvent('networkidle');
    $page->assertSee('Milestone updated.');

    $indent = $page->script(MILESTONE_INDENT_PROBE);
    expect($indent['childPad'])->toEqual($indent['parentPad']);

    $page->assertNoSmoke();
});

it('offers deletion to the managers only, with a confirmation, a flash and detached issues on return', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'project' => $project] = seedMilestoneScenario();

    // A plain project member contributes but never deletes.
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

    $this->actingAs($member);
    $page = visit('/projects/'.$project->id.'/milestones');

    $page->assertPresent('@milestone-create-open');
    $page->click('button[aria-label="Actions for Sprint 1"]');
    $page->assertPresent('@milestone-edit');
    $page->assertNotPresent('@milestone-delete');
    $page->assertNoSmoke();

    // The owner deletes: destructive confirmation, success flash, the
    // row disappears and the attached issue comes back detached.
    app(WorkspaceContext::class)->set($workspace);
    $sprintIssue = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Revue de sprint',
    ]);
    $sprint = $project->milestones()->where('title', 'Sprint 1')->firstOrFail();
    SetIssueMilestone::run($sprintIssue, $sprint->id, $owner);

    $this->actingAs($owner);
    $page = visit('/projects/'.$project->id.'/milestones');
    $page->click('button[aria-label="Actions for Sprint 1"]');
    $page->click('@milestone-delete');
    $page->assertPresent('@confirm-delete-milestone');
    $page->click('@confirm-delete-milestone');
    $page->waitForEvent('networkidle');
    $page->assertSee('Milestone deleted.');
    $page->assertNotPresent('[data-test="milestone-row"]:has-text("Sprint 1")');

    $issuePage = visit('/issues/'.$sprintIssue->id);
    $issuePage->assertNotPresent('@issue-milestone');
    $issuePage->assertNoSmoke();
});

it('shows the milestone detail page with its issues, its children and its stats', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'project' => $project] = seedMilestoneScenario();
    $this->actingAs($owner);

    app(WorkspaceContext::class)->set($workspace);
    $version = $project->milestones()->where('title', 'Version 1')->firstOrFail();

    $page = visit('/milestones/'.$version->id);

    $page->assertSeeIn('@milestone-progress', '1 / 2 issues done');
    $page->assertPresent('[data-test="milestone-issues"]:has-text("Terminer le paiement")');
    $page->assertPresent('[data-test="milestone-issues"]:has-text("Page de profil")');
    $page->assertPresent('[data-test="milestone-children"]:has-text("Sprint 1")');

    // The issue rows link to the fiche.
    $page->click('[data-test="milestone-issues"] a:has-text("Page de profil")');
    $page->waitForEvent('networkidle');
    $page->assertPresent('@issue-sidebar');

    $page->assertNoSmoke();
});

it('sets, changes and detaches the milestone of an issue from the sidebar', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'project' => $project] = seedMilestoneScenario();
    $this->actingAs($owner);

    app(WorkspaceContext::class)->set($workspace);
    $issue = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Nouveau ticket',
    ]);

    $page = visit('/issues/'.$issue->id);

    // Attach.
    $page->click('@milestone-dialog-open');
    $page->click('@milestone-select');
    $page->click('[role="option"]:has-text("Version 1")');
    $page->click('@milestone-submit');
    $page->waitForEvent('networkidle');
    $page->assertSeeIn('@issue-milestone', 'Version 1');

    app(WorkspaceContext::class)->set($workspace);
    expect($issue->fresh()->milestone_id)->not->toBeNull();

    // Move to another milestone of the project.
    app(WorkspaceContext::class)->set($workspace);
    $sprint = $project->milestones()->where('title', 'Sprint 1')->firstOrFail();

    $page->click('@milestone-dialog-open');
    $page->click('@milestone-select');
    $page->click('[role="option"]:has-text("Sprint 1")');
    $page->click('@milestone-submit');
    $page->waitForEvent('networkidle');
    $page->assertSeeIn('@issue-milestone', 'Sprint 1');

    app(WorkspaceContext::class)->set($workspace);
    expect($issue->fresh()->milestone_id)->toBe($sprint->id);

    // Detach.
    $page->click('@milestone-dialog-open');
    $page->click('@milestone-select');
    $page->click('[role="option"]:has-text("No milestone")');
    $page->click('@milestone-submit');
    $page->waitForEvent('networkidle');
    $page->assertNotPresent('@issue-milestone');

    app(WorkspaceContext::class)->set($workspace);
    expect($issue->fresh()->milestone_id)->toBeNull();

    $page->assertNoSmoke();
});

it('creates an issue already attached to a milestone from the board dialog', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'project' => $project] = seedMilestoneScenario();
    $this->actingAs($owner);

    $page = visit('/projects/'.$project->id.'/board');

    $page->click('button:has-text("New issue")');
    $page->fill('#board-issue-title', 'Ticket né rattaché');
    $page->click('@board-issue-milestone');
    $page->click('[role="option"]:has-text("Version 1")');
    $page->click('[data-test="form-dialog-submit"]');
    $page->waitForEvent('networkidle');

    // Creation redirects to the fiche: the milestone is already there.
    $page->assertSeeIn('@issue-milestone', 'Version 1');

    app(WorkspaceContext::class)->set($workspace);
    $issue = $workspace->issues()->where('title', 'Ticket né rattaché')->firstOrFail();
    expect($issue->milestone_id)->not->toBeNull();

    $page->assertNoSmoke();
});
