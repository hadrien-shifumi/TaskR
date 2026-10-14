<?php

use App\Models\Issue;
use App\Support\WorkspaceContext;

it('shows the required-title error inline under the field', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    $this->actingAs($owner);

    $page = visit('/issues');

    $page->click('button:has-text("New issue")');
    $page->click('button[type="submit"]');

    // Scenario "Validation inline": the server refusal renders under the
    // title field, never as a duplicated client-side rule.
    $page->assertSeeIn('[data-test="title-error"]', 'The title field is required');

    app(WorkspaceContext::class)->set($workspace);
    expect(Issue::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->count())->toBe(0);
});
