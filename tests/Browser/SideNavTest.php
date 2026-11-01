<?php

use App\Support\WorkspaceContext;

it('groups the project management entries under a dedicated section', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);
    $this->actingAs($owner);

    $page = visit('/');

    $section = 'nav[aria-label="Main"] [data-test="nav-section"]:has-text("Project management")';
    $page->assertSeeIn($section, 'Project management')
        ->assertPresent($section.' a[href$="/projects"]')
        ->assertPresent($section.' a[href$="/issues"]')
        ->assertNoSmoke();
});

it('groups the knowledge entries under a dedicated section', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);
    $this->actingAs($owner);

    $page = visit('/');

    $section = 'nav[aria-label="Main"] [data-test="nav-section"]:has-text("Knowledge")';
    $page->assertSeeIn($section.' p', 'Knowledge')
        ->assertPresent($section.' a[href$="/knowledge"]')
        ->assertPresent($section.' a[href$="/knowledge/trash"]')
        ->assertNoSmoke();
});

it('keeps home mentions and settings as direct top-level nav links', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);
    $this->actingAs($owner);

    $page = visit('/');

    // Only the two families get a labelled section; the rest stays flat.
    $page->assertCount('nav[aria-label="Main"] [data-test="nav-section"]', 2)
        ->assertPresent('nav[aria-label="Main"] > a[href$="/"]')
        ->assertPresent('nav[aria-label="Main"] > a[href$="/mentions"]')
        ->assertPresent('nav[aria-label="Main"] > a[href$="/settings/statuses"]')
        ->assertNoSmoke();
});

it('navigates through the grouped entries', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);
    $this->actingAs($owner);

    $page = visit('/');
    $page->click('nav[aria-label="Main"] [data-test="nav-section"]:has-text("Project management") a[href$="/projects"]');
    $page->assertPathIs('/projects');

    $page = visit('/');
    $page->click('nav[aria-label="Main"] [data-test="nav-section"]:has-text("Project management") a[href$="/issues"]');
    $page->assertPathIs('/issues');

    $page = visit('/');
    $page->click('nav[aria-label="Main"] [data-test="nav-section"]:has-text("Knowledge") a[href$="/knowledge/trash"]');
    $page->assertPathIs('/knowledge/trash');

    $page->assertNoSmoke();
});

it('keeps the active state on the grouped entries', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);
    $this->actingAs($owner);

    $page = visit('/issues');
    $page->assertPresent('nav[aria-label="Main"] a[aria-current="page"]:has-text("Issues")')
        ->assertNotPresent('nav[aria-label="Main"] a[aria-current="page"]:has-text("Projects")');

    // Knowledge stays active on its sub-pages, except the trash.
    $page = visit('/knowledge');
    $page->assertPresent('nav[aria-label="Main"] a[aria-current="page"]:has-text("Knowledge")')
        ->assertNotPresent('nav[aria-label="Main"] a[aria-current="page"]:has-text("Trash")');

    $page = visit('/knowledge/trash');
    $page->assertPresent('nav[aria-label="Main"] a[aria-current="page"]:has-text("Trash")')
        ->assertNotPresent('nav[aria-label="Main"] a[aria-current="page"]:has-text("Knowledge")');

    $page->assertNoSmoke();
});

it('keeps the nav usable on a mobile viewport', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);
    $this->actingAs($owner);

    $page = visit('/')->on()->mobile();

    // Section labels are desktop-only; every link stays in the horizontal bar.
    $page->assertScript("getComputedStyle(document.querySelector('nav[aria-label=\"Main\"] [data-test=\"nav-section\"] p')).display === 'none'")
        ->assertPresent('nav[aria-label="Main"] a[href$="/"]')
        ->assertPresent('nav[aria-label="Main"] a[href$="/projects"]')
        ->assertPresent('nav[aria-label="Main"] a[href$="/issues"]')
        ->assertPresent('nav[aria-label="Main"] a[href$="/mentions"]')
        ->assertPresent('nav[aria-label="Main"] a[href$="/knowledge"]')
        ->assertPresent('nav[aria-label="Main"] a[href$="/knowledge/trash"]')
        ->assertPresent('nav[aria-label="Main"] a[href$="/settings/statuses"]')
        ->assertNoSmoke();
});

it('has no accessibility issues in the grouped nav, desktop and mobile', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);
    $this->actingAs($owner);

    $page = visit('/');
    $page->assertNoAccessibilityIssues()
        ->assertNoSmoke();

    $mobile = visit('/')->on()->mobile();
    $mobile->assertNoAccessibilityIssues()
        ->assertNoSmoke();
});
