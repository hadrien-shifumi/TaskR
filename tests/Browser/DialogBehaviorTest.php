<?php

use App\Actions\Issues\CreateIssue;
use App\Support\WorkspaceContext;

it('does not close the dialog on outside pointer press (backdrop click)', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Test issue']);
    $this->actingAs($owner);

    $page = visit('/issues/'.$issue->id);

    // Open the log time dialog
    $page->click('@log-time');
    $page->assertPresent('@log-time-hours');

    // Simulate an outside pointer press on the backdrop via JavaScript
    // Base UI listens to pointerdown on document to detect outside presses
    $page->script(<<<'JS'
        const backdrop = document.querySelector('[data-slot="dialog-overlay"]');
        if (backdrop) {
            backdrop.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, cancelable: true }));
            backdrop.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, cancelable: true }));
            backdrop.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
        }
    JS);

    // Dialog should still be open — this assertion fails before the fix
    $page->assertPresent('@log-time-hours');

    $page->assertNoSmoke();
});

it('closes the dialog when pressing Escape', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Test issue']);
    $this->actingAs($owner);

    $page = visit('/issues/'.$issue->id);

    // Open the log time dialog
    $page->click('@log-time');
    $page->assertPresent('@log-time-hours');

    // Press Escape key — dispatch keydown event on document
    $page->script(<<<'JS'
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
    JS);

    // Dialog should be closed
    $page->assertNotPresent('@log-time-hours');

    $page->assertNoSmoke();
});

it('closes the dialog when clicking the close button', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Test issue']);
    $this->actingAs($owner);

    $page = visit('/issues/'.$issue->id);

    // Open the log time dialog
    $page->click('@log-time');
    $page->assertPresent('@log-time-hours');

    // Click the close button (X)
    $page->click('[data-slot="dialog-close"]');

    // Dialog should be closed — check input is gone (button text "Log time" always visible)
    $page->assertNotPresent('@log-time-hours');

    $page->assertNoSmoke();
});
