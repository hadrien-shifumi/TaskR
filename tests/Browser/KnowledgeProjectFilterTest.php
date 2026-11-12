<?php

use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Projects\CreateProject;
use App\Models\KnowledgePage;
use App\Support\WorkspaceContext;

it('ships an empty tree by default and shows one scope at a time', function () {
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

    // No scope chosen yet: placeholder in the select, empty tree, invite.
    $page->assertSee('No project selected')
        ->assertSee('Select a project')
        ->assertDontSee('Page Alpha')
        ->assertDontSee('Page Beta')
        ->assertDontSee('Page transverse');

    // The "All projects" entry is gone from the options.
    $page->click('@filter-select-project');
    $page->assertDontSee('All projects');

    // Pick Alpha — only its page shows, one scope at a time.
    $page->click('[role="option"]:has-text("Alpha")');
    $page->assertQueryStringHas('project', $alpha->id)
        ->assertSee('Page Alpha')
        ->assertDontSee('Page Beta')
        ->assertDontSee('Page transverse');

    // Switch to transverse — only the cross-workspace page remains.
    $page->click('@filter-select-project');
    $page->click('[role="option"]:has-text("Cross-workspace only")');
    $page->assertQueryStringHas('project', 'transverse')
        ->assertSee('Page transverse')
        ->assertDontSee('Page Alpha')
        ->assertDontSee('Page Beta');

    $page->assertNoSmoke();
});

it('scopes the sidebar to the page project on a deep link', function () {
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

    // Deep link without ?project=: the sidebar scopes itself to Alpha.
    $page = visit('/knowledge/'.$pageAlpha->id);

    $page->assertPathIs('/knowledge/'.$pageAlpha->id)
        ->assertSee('Page Alpha')
        ->assertDontSee('Page Beta')
        ->assertDontSee('Page transverse');

    expect($page->text('@filter-select-project'))->toContain('Alpha');

    $page->assertNoSmoke();
});

it('scopes the versions page sidebar to the consulted page project', function () {
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

    $page = visit('/knowledge/'.$pageAlpha->id.'/versions');

    $page->assertSee('Page Alpha')
        ->assertDontSee('Page Beta')
        ->assertDontSee('Page transverse')
        ->assertNoSmoke();
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
        'dimensions' => 2,
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
