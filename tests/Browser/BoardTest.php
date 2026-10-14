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
