<?php

use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Projects\CreateProject;
use App\Support\WorkspaceContext;

it('filters the knowledge tree by project from the sidebar select', function () {
    Queue::fake();
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
    $beta = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

    CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'Page Alpha', 'body' => 'Corps', 'project_id' => $alpha->id,
    ]);
    CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'Page Beta', 'body' => 'Corps', 'project_id' => $beta->id,
    ]);
    CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'Page transverse', 'body' => 'Corps',
    ]);

    $this->actingAs($owner);

    $page = visit('/knowledge');

    // All three pages visible by default.
    $page->assertSee('Page Alpha')
        ->assertSee('Page Beta')
        ->assertSee('Page transverse');

    // Filter by project Alpha — only its page remains in the tree.
    $page->click('@filter-select-project');
    $page->click('[role="option"]:has-text("Alpha")');
    $page->assertQueryStringHas('project', $alpha->id)
        ->assertSee('Page Alpha')
        ->assertDontSee('Page Beta')
        ->assertDontSee('Page transverse');

    // Filter by transverse only — only the cross-workspace page remains.
    $page->click('@filter-select-project');
    $page->click('[role="option"]:has-text("Cross-workspace only")');
    $page->assertQueryStringHas('project', 'transverse')
        ->assertSee('Page transverse')
        ->assertDontSee('Page Alpha')
        ->assertDontSee('Page Beta');

    // Back to all projects — every page is visible again.
    $page->click('@filter-select-project');
    $page->click('[role="option"]:has-text("All projects")');
    $page->assertQueryStringMissing('project')
        ->assertSee('Page Alpha')
        ->assertSee('Page Beta')
        ->assertSee('Page transverse');

    $page->assertNoSmoke();
});
