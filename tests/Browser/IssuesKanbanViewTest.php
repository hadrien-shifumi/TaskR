<?php

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Models\Issue;
use App\Models\Status;
use App\Support\WorkspaceContext;

/**
 * E2E coverage for the kanban view on /issues (?view=kanban).
 * The view shows one board per active project, stacked vertically,
 * with drag & drop wired to the existing issues.move route.
 */
it('switches between list and kanban views', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Une issue',
        'status_id' => $workspace->statuses()->where('category', 'backlog')->first()->id,
    ]);
    $this->actingAs($owner);

    $page = visit('/issues');

    // Default view is list: the table is visible, the kanban view is not.
    $page->assertSeeIn('[data-test="filter-count"]', '1')
        ->assertPresent('[data-test^="issue-row-"]')
        ->assertMissing('[data-test="issues-kanban-view"]');

    // Switch to kanban.
    $page->click('[data-test="issues-view-kanban"]')
        ->waitForEvent('networkidle')
        ->assertPresent('[data-test="project-board-'.$project->id.'"]')
        ->assertMissing('[data-test^="issue-row-"]')
        ->assertQueryStringHas('view', 'kanban');

    // Switch back to list.
    $page->click('[data-test="issues-view-list"]')
        ->waitForEvent('networkidle')
        ->assertPresent('[data-test^="issue-row-"]')
        ->assertQueryStringMissing('view');
});

it('shows one board per active project in the kanban view', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $payments = CreateProject::run($workspace, $owner, ['name' => 'Payments']);
    $mobile = CreateProject::run($workspace, $owner, ['name' => 'Mobile']);

    $backlog = $workspace->statuses()->where('category', 'backlog')->first();
    $inProgress = $workspace->statuses()->where('category', 'in_progress')->first();

    CreateIssue::run($workspace, [
        'project_id' => $payments->id,
        'title' => 'Paiement Stripe',
        'status_id' => $inProgress->id,
    ]);
    CreateIssue::run($workspace, [
        'project_id' => $mobile->id,
        'title' => 'Migration API',
        'status_id' => $backlog->id,
    ]);

    $this->actingAs($owner);

    $page = visit('/issues?view=kanban');

    // Two boards, one per project, ordered by name (Mobile before Payments).
    $page->assertSee('Mobile')
        ->assertSee('Payments')
        ->assertSee('Paiement Stripe')
        ->assertSee('Migration API')
        ->assertPresent('[data-test="project-board-'.$mobile->id.'"]')
        ->assertPresent('[data-test="project-board-'.$payments->id.'"]');

    // Each board has its 4 columns.
    $page->assertPresent('[data-test="kanban-column-'.$mobile->id.'-backlog"]')
        ->assertPresent('[data-test="kanban-column-'.$mobile->id.'-in_progress"]')
        ->assertPresent('[data-test="kanban-column-'.$mobile->id.'-done"]')
        ->assertPresent('[data-test="kanban-column-'.$mobile->id.'-cancelled"]');

    $page->assertNoSmoke();
});

it('moves a card across columns by drag and drop in the kanban view', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);

    $backlog = $workspace->statuses()->where('category', 'backlog')->first();
    $done = $workspace->statuses()->where('category', 'done')->first();

    $issue = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Webhook retry',
        'status_id' => $backlog->id,
    ]);

    $this->actingAs($owner);

    $page = visit('/issues?view=kanban');

    // The card is in the backlog column.
    $page->assertPresent('[data-test="kanban-card-'.$issue->id.'"]');

    // Drag & drop to the done column.
    $page->drag(
        '[data-test="kanban-card-'.$issue->id.'"]',
        '[data-test="kanban-column-'.$project->id.'-done"]',
    );

    $page->waitForText('Issue moved.');

    // The issue status changed in the database.
    app(WorkspaceContext::class)->set($workspace);
    expect(
        Issue::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->whereKey($issue->id)
            ->first()
            ->status_id
    )->toBe($done->id);

    $page->assertNoSmoke();
});

it('moves a card through the keyboard move-to menu in the kanban view', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);

    $backlog = $workspace->statuses()->where('category', 'backlog')->first();
    $inProgress = $workspace->statuses()->where('category', 'in_progress')->first();

    $issue = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Keyboard move',
        'status_id' => $backlog->id,
    ]);

    $this->actingAs($owner);

    $page = visit('/issues?view=kanban');

    // Open the move-to menu on the card and select "In Progress".
    $page->click('[data-test="kanban-card-'.$issue->id.'"] button:has-text("Move to")');
    $page->click('[role="menuitem"]:has-text("In Progress")');

    $page->waitForText('Issue moved.');

    app(WorkspaceContext::class)->set($workspace);
    expect(
        Issue::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->whereKey($issue->id)
            ->first()
            ->status_id
    )->toBe($inProgress->id);
});

it('applies filters in the kanban view', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $payments = CreateProject::run($workspace, $owner, ['name' => 'Payments']);
    $mobile = CreateProject::run($workspace, $owner, ['name' => 'Mobile']);

    $backlog = $workspace->statuses()->where('category', 'backlog')->first();
    $inProgress = $workspace->statuses()->where('category', 'in_progress')->first();

    CreateIssue::run($workspace, [
        'project_id' => $payments->id,
        'title' => 'Paiement en cours',
        'status_id' => $inProgress->id,
    ]);
    CreateIssue::run($workspace, [
        'project_id' => $payments->id,
        'title' => 'Paiement en backlog',
        'status_id' => $backlog->id,
    ]);
    CreateIssue::run($workspace, [
        'project_id' => $mobile->id,
        'title' => 'Migration mobile',
        'status_id' => $backlog->id,
    ]);

    $this->actingAs($owner);

    // Filter by category=in_progress: only the in-progress issue appears.
    $page = visit('/issues?view=kanban&category=in_progress');

    $page->assertSee('Paiement en cours')
        ->assertDontSee('Paiement en backlog')
        ->assertDontSee('Migration mobile');

    // Filter by project: only the Payments board is shown.
    $page = visit('/issues?view=kanban&project='.$payments->id);

    $page->assertPresent('[data-test="project-board-'.$payments->id.'"]')
        ->assertMissing('[data-test="project-board-'.$mobile->id.'"]')
        ->assertSee('Paiement en cours')
        ->assertSee('Paiement en backlog');

    $page->assertNoSmoke();
});

it('shows an empty state when no issues match in the kanban view', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $this->actingAs($owner);

    $page = visit('/issues?view=kanban');

    // The board is present with empty columns.
    $page->assertPresent('[data-test="project-board-'.$project->id.'"]')
        ->assertSee('No issue'); // empty column placeholder

    $page->assertNoSmoke();
});
