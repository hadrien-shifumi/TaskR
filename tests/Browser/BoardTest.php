<?php

use App\Actions\Issues\CreateIssue;
use App\Models\Issue;
use App\Models\Status;
use App\Support\WorkspaceContext;

it('moves a card across columns by drag and drop', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $backlog = $workspace->statuses()->where('name', 'Backlog')->firstOrFail();
    $issue = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Webhook retry',
        'status_id' => $backlog->id,
    ]);
    $this->actingAs($owner);

    $page = visit("/projects/{$project->id}/board");

    // Scenario "Déplacement d'une carte": the drop calls issues.move and the
    // board reflects the move after the response.
    $page->drag("[data-test=\"board-card-{$issue->id}\"]", '[data-test="board-column-done"]');

    $page->waitForText('Issue moved.');

    app(WorkspaceContext::class)->set($workspace);
    expect(
        Issue::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->whereKey($issue->id)->first()->status_id
    )->toBe(
        Status::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->where('name', 'Done')->first()->id
    );

    $page->assertNoSmoke();
});

it('moves a card through the keyboard move-to menu', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $backlog = $workspace->statuses()->where('name', 'Backlog')->firstOrFail();
    $issue = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Keyboard move',
        'status_id' => $backlog->id,
    ]);
    $this->actingAs($owner);

    $page = visit("/projects/{$project->id}/board");

    $page->click("[data-test=\"board-card-{$issue->id}\"] button:has-text(\"Move to\")");
    $page->click('[role="menuitem"]:has-text("In Progress")');

    $page->waitForText('Issue moved.');

    app(WorkspaceContext::class)->set($workspace);
    expect(
        Issue::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->whereKey($issue->id)->first()->status_id
    )->toBe(
        Status::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->where('name', 'In Progress')->first()->id
    );
});

it('creates an issue in a column through its add button', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $this->actingAs($owner);

    $page = visit("/projects/{$project->id}/board");

    // Maquette: each column header carries a contextual "+" (aria-label
    // "Add an issue in <status>") that opens the creation dialog with the
    // column status preselected and the project locked to this board.
    $page->click('[data-test="board-column-add-in_progress"]')
        ->assertSee('New issue')
        ->assertSeeIn('[data-test="board-issue-status"]', 'In Progress')
        ->fill('board-issue-title', 'Added from column')
        ->click('button[type="submit"]');

    $page->waitForText('Issue created.');

    app(WorkspaceContext::class)->set($workspace);
    $issue = Issue::withoutWorkspaceScope()
        ->where('workspace_id', $workspace->id)
        ->where('title', 'Added from column')
        ->firstOrFail();

    expect($issue->project_id)->toBe($project->id)
        ->and($issue->status_id)->toBe(
            Status::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->where('name', 'In Progress')->first()->id
        );

    $page->assertNoSmoke();
});

it('creates an issue with an estimate from the board dialog', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $this->actingAs($owner);

    $page = visit("/projects/{$project->id}/board");

    $page->click('New issue')
        ->fill('board-issue-title', 'Estimated task')
        ->fill('board-issue-estimate', '6.5')
        ->click('button[type="submit"]');

    // The estimate is not part of issue creation: it rides the dedicated
    // route once the issue exists (creation redirects to the issue page).
    $page->waitForText('Estimate updated.');

    app(WorkspaceContext::class)->set($workspace);
    $issue = Issue::withoutWorkspaceScope()
        ->where('workspace_id', $workspace->id)
        ->where('title', 'Estimated task')
        ->firstOrFail();

    expect($issue->project_id)->toBe($project->id)
        ->and($issue->status_id)->toBe(
            Status::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->where('name', 'Backlog')->first()->id
        )
        ->and((float) $issue->estimated_hours)->toBe(6.5);

    $page->assertNoSmoke();
});
