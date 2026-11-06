<?php

use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\SetIssueParent;
use App\Actions\Projects\CreateProject;
use App\Actions\TimeTracking\SetIssueEstimate;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;

/**
 * Browser fixture for the hierarchy scenarios (knowledge base: "Hiérarchie :
 * propagation parent/enfants et complétion pondérée"). `seedHierarchyScenario()`
 * creates an owner with a workspace/project and a backlog parent carrying two
 * backlog children — nothing toggled or moved yet.
 *
 * @return array{owner: User, workspace: Workspace, project: Project, statuses: array<string, Status>, parent: Issue, childOne: Issue, childTwo: Issue}
 */
function seedHierarchyScenario(): array
{
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);

    $statuses = Status::withoutWorkspaceScope()
        ->where('workspace_id', $workspace->id)
        ->get()
        ->keyBy(fn (Status $status) => $status->category->value);

    $issue = fn (string $title) => CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => $title,
        'status_id' => $statuses['backlog']->id,
    ]);

    $parent = $issue('Refonte du tunnel de paiement');
    $childOne = $issue('Page de paiement');
    $childTwo = $issue('Email de confirmation');
    SetIssueParent::run($childOne, $parent->id);
    SetIssueParent::run($childTwo, $parent->id);

    return [
        'owner' => $owner,
        'workspace' => $workspace,
        'project' => $project,
        'statuses' => $statuses,
        'parent' => $parent,
        'childOne' => $childOne,
        'childTwo' => $childTwo,
    ];
}

/** Creates a guest membership scoped to the hierarchy project. */
function createHierarchyGuest(Workspace $workspace, Project $project): User
{
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

    return $guest;
}

it('proposes to bring the whole sub-tree along when a parent moves, and refuses a lone Done while a child is open', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'statuses' => $statuses, 'parent' => $parent, 'childOne' => $childOne, 'childTwo' => $childTwo] = seedHierarchyScenario();
    $this->actingAs($owner);

    // The parent's fiche: moving it offers to bring the sub-tree along —
    // the dialog names the count and the target status.
    $page = visit('/issues/'.$parent->id);
    $page->click('@status-select')
        ->click('[role="option"]:has-text("In Progress")');
    $page->assertPresent('[data-test="cascade-move-dialog"]')
        ->assertSee('This ticket has 2 sub-tickets in another status.')
        ->click('[data-test="cascade-move-accept"]');
    // The fiche only shows the new status once the move landed: the
    // assertion (auto-retried) closes the race with the request.
    $page->assertSeeIn('@status-select', 'In Progress');

    app(WorkspaceContext::class)->set($workspace);
    expect($parent->fresh()->status->name)->toBe('In Progress')
        ->and($childOne->fresh()->status->name)->toBe('In Progress')
        ->and($childTwo->fresh()->status->name)->toBe('In Progress');

    // A child whose descendants all share the target status: the move is
    // direct, no dialog.
    $page = visit('/issues/'.$childOne->id);
    $page->click('@status-select')
        ->click('[role="option"]:has-text("Backlog")');
    $page->assertSeeIn('@status-select', 'Backlog')
        ->assertNotPresent('[data-test="cascade-move-dialog"]');

    app(WorkspaceContext::class)->set($workspace);
    expect($childOne->fresh()->status->name)->toBe('Backlog');

    // The Done guard: a lone parent-to-done is refused — the sub-tree
    // answer "No" keeps the sub-tickets, the refusal surfaces on the fiche.
    $page = visit('/issues/'.$parent->id);
    $page->click('@status-select')
        ->click('[role="option"]:has-text("Done")');
    $page->assertPresent('[data-test="cascade-move-dialog"]')
        ->click('[data-test="cascade-move-decline"]');
    $page->assertPresent('[data-test="move-error"]')
        ->assertSee('The move was refused.');

    app(WorkspaceContext::class)->set($workspace);
    expect($parent->fresh()->status->name)->toBe('In Progress')
        ->and($childOne->fresh()->status->name)->toBe('Backlog');

    // The sub-tree answer "Yes" closes the whole tree: the guard is
    // satisfied by the cascade, the parent follows.
    $page = visit('/issues/'.$parent->id);
    $page->click('@status-select')
        ->click('[role="option"]:has-text("Done")');
    $page->assertPresent('[data-test="cascade-move-dialog"]')
        ->click('[data-test="cascade-move-accept"]');
    $page->assertSeeIn('@status-select', 'Done');

    app(WorkspaceContext::class)->set($workspace);
    expect($parent->fresh()->status->name)->toBe('Done')
        ->and($childOne->fresh()->status->name)->toBe('Done')
        ->and($childTwo->fresh()->status->name)->toBe('Done');

    $page->assertNoSmoke();
});

it('completes the parent through the UI once every child is done, and a reopened child never reopens it', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'parent' => $parent, 'childOne' => $childOne, 'childTwo' => $childTwo] = seedHierarchyScenario();
    $this->actingAs($owner);

    // The option is enabled from the parent's own fiche.
    $page = visit('/issues/'.$parent->id);
    $page->click('@auto-complete-toggle');
    $page->waitForEvent('networkidle');
    $page->assertAttributeContains('@auto-complete-toggle', 'aria-checked', 'true');

    app(WorkspaceContext::class)->set($workspace);
    expect($parent->fresh()->auto_complete_children)->toBeTrue();

    // First child done: the parent stays open — the second one is still active.
    $page = visit('/issues/'.$childOne->id);
    $page->click('@status-select')
        ->click('[role="option"]:has-text("Done")');
    $page->waitForEvent('networkidle');

    $page = visit('/issues/'.$parent->id);
    $page->assertSeeIn('@status-select', 'Backlog');

    // Second child done: the parent follows into done automatically.
    $page = visit('/issues/'.$childTwo->id);
    $page->click('@status-select')
        ->click('[role="option"]:has-text("Done")');
    $page->waitForEvent('networkidle');

    app(WorkspaceContext::class)->set($workspace);
    expect($parent->fresh()->status->name)->toBe('Done');

    $page = visit('/issues/'.$parent->id);
    $page->assertSeeIn('@status-select', 'Done');

    // The parent's journal tells the automatic completion in plain words —
    // the dedicated label, never a raw technical key — with its before → after.
    $page->click('[data-test="activity-accordion-trigger"]');
    $page->assertSeeIn('[data-test="activity-section"]', 'Auto-completed')
        ->assertSeeIn('[data-test="activity-section"]', 'Backlog → Done')
        ->assertDontSeeIn('[data-test="activity-section"]', 'audit.types');

    // Reopening a child never reopens the parent.
    $page = visit('/issues/'.$childOne->id);
    $page->click('@status-select')
        ->click('[role="option"]:has-text("Backlog")');
    $page->waitForEvent('networkidle');

    app(WorkspaceContext::class)->set($workspace);
    expect($parent->fresh()->status->name)->toBe('Done');

    $page = visit('/issues/'.$parent->id);
    $page->assertSeeIn('@status-select', 'Done');

    $page->assertNoSmoke();
});

it('shows the auto-completion option off by default, hints it per children, and honours the project perimeter', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'project' => $project, 'parent' => $parent] = seedHierarchyScenario();
    app(WorkspaceContext::class)->set($workspace);

    // A childless issue: the option sits unchecked with the dedicated hint.
    $solo = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Ticket isole',
        'status_id' => $workspace->statuses()->where('name', 'Backlog')->firstOrFail()->id,
    ]);

    $this->actingAs($owner);

    $page = visit('/issues/'.$solo->id);
    $page->assertAttributeContains('@auto-complete-toggle', 'aria-checked', 'false')
        ->assertSee('This issue has no child issues yet — the option will have no effect.');

    // The parent carries children: the hint switches to the propagation wording.
    $page = visit('/issues/'.$parent->id);
    $page->assertAttributeContains('@auto-complete-toggle', 'aria-checked', 'false')
        ->assertSee('When every active sub-issue is done or cancelled, the parent follows automatically.');

    // The authorization follows the project perimeter, not the role: a guest
    // attached to the project can enable the option (delivered behavior).
    $guest = createHierarchyGuest($workspace, $project);
    $this->actingAs($guest);

    $page = visit('/issues/'.$parent->id);
    $page->click('@auto-complete-toggle');
    $page->waitForEvent('networkidle');
    $page->assertAttributeContains('@auto-complete-toggle', 'aria-checked', 'true');

    app(WorkspaceContext::class)->set($workspace);
    expect($parent->fresh()->auto_complete_children)->toBeTrue();

    $page->assertNoSmoke();
});

it('flags a done parent with a reopened child on every card surface, tooltip included', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'project' => $project, 'statuses' => $statuses, 'parent' => $parent, 'childOne' => $childOne] = seedHierarchyScenario();
    app(WorkspaceContext::class)->set($workspace);

    // The parent is done while its child stays open: the alert signal rides
    // the parent's cards. The state is written directly: the move action
    // refuses a lone parent-to-done while a child is open.
    $parent->update(['status_id' => $statuses['done']->id]);

    $this->actingAs($owner);

    // Transversal list: the parent's row carries the badge with its tooltip,
    // the reopened child's doesn't.
    $list = visit('/issues');
    $list->assertSeeIn("[data-test=\"issue-row-{$parent->id}\"]", 'child reopened')
        ->assertAttributeContains("[data-test=\"issue-row-{$parent->id}\"] [data-test=\"open-child-badge\"]", 'title', 'A sub-issue of this completed ticket has been reopened.')
        ->assertNotPresent("[data-test=\"issue-row-{$childOne->id}\"] [data-test=\"open-child-badge\"]");

    // Kanban view: the badge gates on the DONE column — the parent sits in
    // it, its open child does not.
    $kanban = visit('/issues?view=kanban');
    $kanban->assertPresent("[data-test=\"kanban-card-{$parent->id}\"] [data-test=\"open-child-badge\"]")
        ->assertNotPresent("[data-test=\"kanban-card-{$childOne->id}\"] [data-test=\"open-child-badge\"]");

    // Project board: same signal on the board card.
    $board = visit("/projects/{$project->id}/board");
    $board->assertPresent("[data-test=\"board-card-{$parent->id}\"] [data-test=\"open-child-badge\"]");

    // The signal counts every active sub-issue without perimeter restriction:
    // a guest attached to the project sees the badge too.
    $guest = createHierarchyGuest($workspace, $project);
    $this->actingAs($guest);

    $guestPage = visit('/issues');
    $guestPage->assertSeeIn("[data-test=\"issue-row-{$parent->id}\"]", 'child reopened');

    $list->assertNoSmoke();
    $kanban->assertNoSmoke();
    $board->assertNoSmoke();
    $guestPage->assertNoSmoke();
});

it('shows the weighted and leaf completion indicators with their empty values on the health page', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $project = CreateProject::run($workspace, $owner, ['name' => 'Refonte API']);
    $statuses = Status::withoutWorkspaceScope()
        ->where('workspace_id', $workspace->id)
        ->get()
        ->keyBy(fn (Status $status) => $status->category->value);

    $issue = fn (string $title, string $category) => CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => $title,
        'status_id' => $statuses[$category]->id,
    ]);

    // A done parent with a done child: the parent is a container, the child
    // a leaf. Three estimated leaves: done 4h, open 6h, cancelled 2h.
    $parent = $issue('Parent', 'done');
    $child = $issue('Child', 'done');
    SetIssueParent::run($child, $parent->id);
    $leafDone = $issue('Leaf done', 'done');
    SetIssueEstimate::run($leafDone, 4.0);
    $leafOpen = $issue('Leaf open', 'in_progress');
    SetIssueEstimate::run($leafOpen, 6.0);
    $leafCancelled = $issue('Leaf cancelled', 'cancelled');
    SetIssueEstimate::run($leafCancelled, 2.0);

    $this->actingAs($owner);

    $page = visit('/projects/'.$project->id.'/health');

    // Raw count: 3 done of 5 issues (parent, child, leaf done).
    $page->assertSeeIn('@stat-completed', '60 %');
    // Weighted: 4h done of 12h estimated (cancelled included) → 33 %.
    $page->assertSeeIn('@stat-weighted', '33 %');
    // Leaves only: 2 done (child, leaf done) of 4 leaves → 50 % — the
    // container parent never counts as a unit of progress.
    $page->assertSeeIn('@stat-leaf', '50 %');

    // A project without any estimate: no misleading "0 %" — the weighted
    // indicator stays empty while the leaf one counts its issues.
    $unestimated = CreateProject::run($workspace, $owner, ['name' => 'Sans estimation']);
    CreateIssue::run($workspace, [
        'project_id' => $unestimated->id,
        'title' => 'Ticket ouvert',
        'status_id' => $statuses['backlog']->id,
    ]);

    $page = visit('/projects/'.$unestimated->id.'/health');
    $page->assertSeeIn('@stat-weighted', '—')
        ->assertSeeIn('@stat-leaf', '0 %');

    // An empty project: both indicators stay empty.
    $empty = CreateProject::run($workspace, $owner, ['name' => 'Projet vide']);

    $page = visit('/projects/'.$empty->id.'/health');
    $page->assertSeeIn('@stat-weighted', '—')
        ->assertSeeIn('@stat-leaf', '—');

    $page->assertNoSmoke();
});

it('keeps the parents-as-containers setting owner-only and applies it to the health indicators', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);

    $statuses = Status::withoutWorkspaceScope()
        ->where('workspace_id', $workspace->id)
        ->get()
        ->keyBy(fn (Status $status) => $status->category->value);

    // Container parent 10h estimated with a 4h child, plus a 6h done leaf:
    // off → 14h open, 6h done of 20h; on → 4h open, 6h of 10h.
    $parent = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Parent conteneur',
        'status_id' => $statuses['in_progress']->id,
    ]);
    SetIssueEstimate::run($parent, 10.0);
    $child = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Enfant',
        'status_id' => $statuses['backlog']->id,
    ]);
    SetIssueEstimate::run($child, 4.0);
    SetIssueParent::run($child, $parent->id);
    $leafDone = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Feuille terminee',
        'status_id' => $statuses['done']->id,
    ]);
    SetIssueEstimate::run($leafDone, 6.0);

    $this->actingAs($owner);

    // Off by default: the parent keeps its weight in both indicators.
    $health = visit('/projects/'.$project->id.'/health');
    $health->assertSeeIn('@stat-weighted', '30 %')
        ->assertSeeIn('@stat-hours', '0 / 14 h');

    // The owner flips the setting from the general settings page: the card
    // carries its help text, the confirmation flashes, the value persists.
    $page = visit('/settings/general');
    $page->assertAttributeContains('@parents-as-containers-toggle', 'aria-checked', 'false')
        ->assertSee('The leaves of the tree carry the estimated load, not the containers.')
        ->click('@parents-as-containers-toggle');
    $page->waitForEvent('networkidle');
    $page->assertAttributeContains('@parents-as-containers-toggle', 'aria-checked', 'true')
        ->assertSee('General settings saved.');

    app(WorkspaceContext::class)->set($workspace);
    expect($workspace->fresh()->parents_as_containers)->toBeTrue();

    // The container parent leaves the estimated sums: open hours drop to
    // the child's 4h, the weighted completion doubles.
    $health = visit('/projects/'.$project->id.'/health');
    $health->assertSeeIn('@stat-weighted', '60 %')
        ->assertSeeIn('@stat-hours', '0 / 4 h');

    // A regular member reads the setting but cannot touch it.
    $member = User::factory()->create();
    Membership::create([
        'user_id' => $member->id,
        'workspace_id' => $workspace->id,
        'role' => 'member',
    ]);
    $this->actingAs($member);

    $page = visit('/settings/general');
    $page->assertSee('The leaves of the tree carry the estimated load, not the containers.');

    // Base UI renders the checkbox as a non-native control: the lock rides
    // aria-disabled, not a native disabled attribute.
    $page->assertAttributeContains('@parents-as-containers-toggle', 'aria-disabled', 'true');

    $health->assertNoSmoke();
    $page->assertNoSmoke();
});
