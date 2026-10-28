<?php

use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Projects\CreateProject;
use App\Models\KnowledgePage;
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

it('keeps the project filter when opening a page from the filtered tree', function () {
    Queue::fake();
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
    $beta = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

    $pageAlpha = CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'Page Alpha', 'body' => 'Corps', 'project_id' => $alpha->id,
    ]);
    CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'Page Beta', 'body' => 'Corps', 'project_id' => $beta->id,
    ]);
    CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'Page transverse', 'body' => 'Corps',
    ]);

    $this->actingAs($owner);

    $page = visit('/knowledge?project='.$alpha->id);

    $page->assertSee('Page Alpha')
        ->assertDontSee('Page Beta')
        ->assertDontSee('Page transverse');

    // Clicking a page of the filtered tree must carry ?project= along.
    $page->click('nav[aria-label="Knowledge tree"] a:has-text("Page Alpha")');

    $page->assertPathIs('/knowledge/'.$pageAlpha->id)
        ->assertQueryStringHas('project', $alpha->id)
        ->assertSee('Page Alpha')
        ->assertDontSee('Page Beta')
        ->assertDontSee('Page transverse');

    // The sidebar select still shows the filtered project, not "All projects".
    expect($page->text('@filter-select-project'))->toContain('Alpha');

    $page->assertNoSmoke();
});

it('keeps the project filter when opening an ancestor from the breadcrumb', function () {
    Queue::fake();
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);

    $pageAlpha = CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'Page Alpha', 'body' => 'Corps', 'project_id' => $alpha->id,
    ]);
    $child = CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'Sous-page Alpha', 'body' => 'Corps',
        'parent_id' => $pageAlpha->id, 'project_id' => $alpha->id,
    ]);
    CreateKnowledgePage::run($workspace, $owner, [
        'title' => 'Page transverse', 'body' => 'Corps',
    ]);

    $this->actingAs($owner);

    $page = visit('/knowledge?project='.$alpha->id);

    $page->click('nav[aria-label="Knowledge tree"] a:has-text("Sous-page Alpha")');

    $page->assertPathIs('/knowledge/'.$child->id)
        ->assertQueryStringHas('project', $alpha->id);

    // The breadcrumb ancestor link must preserve the filter as well.
    $page->click('nav[aria-label="Breadcrumb"] a:has-text("Page Alpha")');

    $page->assertPathIs('/knowledge/'.$pageAlpha->id)
        ->assertQueryStringHas('project', $alpha->id)
        ->assertDontSee('Page transverse')
        ->assertNoSmoke();
});

it('keeps the project filter when opening a page from search results', function () {
    Queue::fake();
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $alpha = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
    $beta = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

    $pageAlpha = KnowledgePage::factory()->vectorized([1.0, 0.0])->create([
        'workspace_id' => $workspace->id,
        'project_id' => $alpha->id,
        'title' => 'Page Alpha',
        'body' => 'Corps Alpha.',
    ]);
    KnowledgePage::factory()->vectorized([0.0, 1.0])->create([
        'workspace_id' => $workspace->id,
        'project_id' => $beta->id,
        'title' => 'Page Beta',
        'body' => 'Corps Beta.',
    ]);

    config()->set('knowledge.embeddings', [
        'base_url' => 'https://embeddings.test/v1',
        'api_key' => 'test-key',
        'model' => 'text-embedding-3-small',
    ]);
    Http::fake([
        'embeddings.test/v1/embeddings' => Http::response(['data' => [['embedding' => [1.0, 0.0]]]]),
    ]);

    $this->actingAs($owner);

    $page = visit('/knowledge?project='.$alpha->id);

    $page->fill('input[aria-label="Semantic search"]', 'alpha');
    $page->wait(1);

    $page->assertSee('Page Alpha')->assertDontSee('Page Beta');

    // Clicking a search result must carry ?project= along.
    $page->click('section[aria-label="Semantic search"] a:has-text("Page Alpha")');

    $page->assertPathIs('/knowledge/'.$pageAlpha->id)
        ->assertQueryStringHas('project', $alpha->id)
        ->assertDontSee('Page Beta')
        ->assertNoSmoke();
});
