<?php

use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\SetIssueDependencies;
use App\Models\Issue;
use App\Models\Status;
use App\Support\WorkspaceContext;

const ISSUE_DEPENDENT_ROWS = '[data-test^="issue-row-"]';

/**
 * Browser fixtures for the issue-dependency scenarios (knowledge base:
 * "Dépendances entre tickets"). `seedDependencyScenario()` creates an owner
 * with a workspace/project and two issues: `blocker` (B) and `dependent`
 * (A) — no link yet. `seedBlockedFilterScenario()` adds a third unlinked
 * issue for the "Blocked" filter of the tickets page.
 *
 * @return array{owner: User, workspace: Workspace, project: Project, statuses: array<string, Status>, blocker: Issue, dependent: Issue}
 */
function seedDependencyScenario(): array
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
        'blocker' => CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Ship database migration',
        ]),
        'dependent' => CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Deploy API v2',
        ]),
    ];
}

/**
 * @return array{owner: User, workspace: Workspace, project: Project, blocker: Issue, dependent: Issue, free: Issue}
 */
function seedBlockedFilterScenario(): array
{
    $scenario = seedDependencyScenario();

    $free = CreateIssue::run($scenario['workspace'], [
        'project_id' => $scenario['project']->id,
        'title' => 'Write release notes',
    ]);

    return [...$scenario, 'free' => $free];
}

/** Opens a filter select and picks the option carrying the given label. */
function pickFilterOption($page, string $selectTestId, string $optionLabel): void
{
    $page->click("@{$selectTestId}");
    $page->click("[role=\"option\"]:has-text(\"{$optionLabel}\")");
}

/** Links the dependent (A) onto the blocker (B) through the domain action. */
function linkBlocker(Issue $dependent, Issue $blocker): void
{
    SetIssueDependencies::run($dependent, [$blocker->id]);
}

it('adds a blocker through the dependencies dialog', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'blocker' => $blocker, 'dependent' => $dependent] = seedDependencyScenario();
    $this->actingAs($owner);

    $page = visit('/issues/'.$dependent->id);

    // Maquette: the "Dépendances" prop row starts empty.
    $page->assertVisible('@no-blockers');
    $page->assertNotPresent('@blocked-badge');

    $page->click('@dependencies-dialog-open');
    $page->assertSee('Manage blockers');
    $page->assertPresent('@dependency-candidates');

    // The candidate row starts unchecked; clicking it checks the checkbox.
    $candidate = 'label:has-text("Ship database migration")';
    $page->assertAttributeContains($candidate.' [data-test="dependency-candidate"]', 'aria-checked', 'false');
    $page->click($candidate);
    $page->assertAttributeContains($candidate.' [data-test="dependency-candidate"]', 'aria-checked', 'true');

    $page->click('@dependency-submit');
    $page->waitForEvent('networkidle');

    // The dialog closes, the blocker lands in the list with the blocked badge.
    $page->assertNotPresent('[data-slot="dialog-content"]');
    $page->assertSee('Ship database migration');
    $page->assertVisible('@blocked-badge');
    $page->assertNotPresent('@no-blockers');

    app(WorkspaceContext::class)->set($workspace);
    expect($dependent->fresh()->blockedBy->modelKeys())->toBe([$blocker->id]);

    $page->assertNoSmoke();
});

it('removes a blocker from the dependencies list', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'blocker' => $blocker, 'dependent' => $dependent] = seedDependencyScenario();
    linkBlocker($dependent, $blocker);
    $this->actingAs($owner);

    $page = visit('/issues/'.$dependent->id);

    $page->assertVisible('@blocked-badge');
    $page->assertSee('Ship database migration');

    $page->click('@blocker-remove');
    $page->waitForEvent('networkidle');

    $page->assertVisible('@no-blockers');
    $page->assertNotPresent('@blocked-badge');
    $page->assertDontSee('Ship database migration');

    app(WorkspaceContext::class)->set($workspace);
    expect($dependent->fresh()->blockedBy)->toHaveCount(0);

    $page->assertNoSmoke();
});

it('shows and clears the mirrored "This ticket blocks" row from the blocker side', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'blocker' => $blocker, 'dependent' => $dependent] = seedDependencyScenario();
    linkBlocker($dependent, $blocker);
    $this->actingAs($owner);

    // Open the BLOCKER's fiche: the mirror row lists the dependent.
    $page = visit('/issues/'.$blocker->id);

    $page->assertSee('This ticket blocks');
    $page->assertSee('Deploy API v2');
    $page->assertVisible('@no-blockers');
    $page->assertNotPresent('@blocked-badge');

    // The ✕ of the mirror row deletes the edge from the other side.
    $page->click('@blocks-remove');
    $page->waitForEvent('networkidle');

    $page->assertDontSee('Deploy API v2');

    app(WorkspaceContext::class)->set($workspace);
    expect($dependent->fresh()->blockedBy)->toHaveCount(0)
        ->and($blocker->fresh()->blocks)->toHaveCount(0);

    $page->assertNoSmoke();
});

it('flips the blocked badge to Unblockable once the blocker is done', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'statuses' => $statuses, 'blocker' => $blocker, 'dependent' => $dependent] = seedDependencyScenario();
    linkBlocker($dependent, $blocker);
    $this->actingAs($owner);

    // Move the blocker to Done through the status select of its own fiche.
    $page = visit('/issues/'.$blocker->id);
    $page->click('@status-select');
    $page->click('[role="option"]:has-text("Done")');
    $page->waitForEvent('networkidle');

    // The dependent keeps the link but turns green: every blocker is done.
    $fiche = visit('/issues/'.$dependent->id);
    $fiche->assertVisible('@unblockable-badge');
    $fiche->assertNotPresent('@blocked-badge');
    $fiche->assertSee('Ship database migration');

    app(WorkspaceContext::class)->set($workspace);
    expect($dependent->fresh()->blockedBy->modelKeys())->toBe([$blocker->id]);

    $fiche->assertNoSmoke();
});

it('shows the Blocked badge on the list row, the kanban card and the project board card', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'project' => $project, 'blocker' => $blocker, 'dependent' => $dependent] = seedDependencyScenario();
    linkBlocker($dependent, $blocker);
    $this->actingAs($owner);

    // Transverse list: the dependent row carries the badge, the blocker's doesn't.
    $list = visit('/issues');
    $list->assertSeeIn("[data-test=\"issue-row-{$dependent->id}\"]", 'Blocked');
    $list->assertDontSeeIn("[data-test=\"issue-row-{$blocker->id}\"]", 'Blocked');

    // Kanban view of the same page.
    $kanban = visit('/issues?view=kanban');
    $kanban->assertSeeIn("[data-test=\"kanban-card-{$dependent->id}\"]", 'Blocked');
    $kanban->assertDontSeeIn("[data-test=\"kanban-card-{$blocker->id}\"]", 'Blocked');

    // Project board card.
    $board = visit("/projects/{$project->id}/board");
    $board->assertSeeIn("[data-test=\"board-card-{$dependent->id}\"]", 'Blocked');
    $board->assertDontSeeIn("[data-test=\"board-card-{$blocker->id}\"]", 'Blocked');

    $list->assertNoSmoke();
    $kanban->assertNoSmoke();
    $board->assertNoSmoke();
});

it('renders the dependencies section inside the board modal', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'project' => $project, 'blocker' => $blocker, 'dependent' => $dependent] = seedDependencyScenario();
    linkBlocker($dependent, $blocker);
    $this->actingAs($owner);

    $page = visit("/projects/{$project->id}/board?issue={$dependent->id}");

    $dialog = '[data-test="issue-detail-dialog"]';
    $page->assertSeeIn($dialog, 'Deploy API v2');
    $page->assertSeeIn($dialog, 'Dependencies');
    $page->assertSeeIn($dialog, 'Blocked');
    $page->assertSeeIn($dialog, 'Ship database migration');

    $page->assertNoSmoke();
});

it('filters the tickets list to blocked issues and cumulates with the priority filter', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'project' => $project, 'blocker' => $blocker, 'dependent' => $dependent, 'free' => $free] = seedBlockedFilterScenario();

    app(WorkspaceContext::class)->set($workspace);
    $dependent->update(['priority' => 'urgent']);
    linkBlocker($dependent, $blocker);

    $this->actingAs($owner);
    $page = visit('/issues');

    // Only the dependent is blocked: the blocker and the free issue drop out.
    pickFilterOption($page, 'filter-select-blocked', 'Blocked');
    $page->assertQueryStringHas('blocked', '1');
    $page->assertCount(ISSUE_DEPENDENT_ROWS, 1);
    $page->assertSee('Deploy API v2');
    $page->assertDontSee('Write release notes');

    // Filters cumulate (AND): urgent keeps it, low matches nothing.
    pickFilterOption($page, 'filter-select-priority', 'Urgent');
    $page->assertQueryStringHas('blocked', '1');
    $page->assertQueryStringHas('priority', 'urgent');
    $page->assertCount(ISSUE_DEPENDENT_ROWS, 1);
    $page->assertSee('Deploy API v2');

    pickFilterOption($page, 'filter-select-priority', 'Low');
    $page->assertCount(ISSUE_DEPENDENT_ROWS, 0);
    $page->assertVisible('@issues-empty');

    // Back to "All issues": the blocked param leaves the URL.
    pickFilterOption($page, 'filter-select-blocked', 'All issues');
    $page->assertQueryStringMissing('blocked');
    $page->assertQueryStringHas('priority', 'low');
    $page->assertCount(ISSUE_DEPENDENT_ROWS, 0);

    pickFilterOption($page, 'filter-select-priority', 'All priorities');
    $page->assertCount(ISSUE_DEPENDENT_ROWS, 3);

    $page->assertNoSmoke();
});

it('restores the Blocked filter from a shared URL', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'blocker' => $blocker, 'dependent' => $dependent, 'free' => $free] = seedBlockedFilterScenario();
    linkBlocker($dependent, $blocker);
    $this->actingAs($owner);

    $page = visit('/issues?blocked=1');

    $page->assertCount(ISSUE_DEPENDENT_ROWS, 1);
    $page->assertSee('Deploy API v2');
    $page->assertSeeIn('@filter-select-blocked', 'Blocked');

    $page->assertNoSmoke();
});

it('refuses a dependency cycle with a danger banner', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'blocker' => $blocker, 'dependent' => $dependent] = seedDependencyScenario();
    linkBlocker($dependent, $blocker);
    $this->actingAs($owner);

    // A is blocked by B. Making B blocked by A would close the loop.
    $page = visit('/issues/'.$blocker->id);

    $page->click('@dependencies-dialog-open');
    $page->assertSee('Manage blockers');
    // A is a candidate: the dialog never pre-computes the refusal.
    $page->click('label:has-text("Deploy API v2")');
    $page->click('@dependency-submit');

    // The 422 refusal surfaces as a danger banner inside the dialog.
    $page->waitForEvent('networkidle');
    $page->assertPresent('@dependency-cycle-error');
    $page->assertSee('Attachment refused');
    // The dialog stays open so the user can fix their selection.
    $page->assertPresent('[data-slot="dialog-content"]');

    app(WorkspaceContext::class)->set($workspace);
    expect($blocker->fresh()->blockedBy)->toHaveCount(0)
        ->and($dependent->fresh()->blockedBy->modelKeys())->toBe([$blocker->id]);

    $page->assertNoSmoke();
});
