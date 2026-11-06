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

    // On mobile the sidebar folds into a drawer. The harness re-delivers the
    // document between commands there, so the scenario runs as one probe:
    // wait for the app to remount (the Inertia title replaces the raw server
    // title), open the drawer, and report the families and their entries.
    $result = $page->script(<<<'JS'
        new Promise((resolve) => {
            const start = Date.now();
            const wait = () => {
                const button = document.querySelector('[data-test="mobile-menu-button"]');
                if (button && document.title !== 'Laravel') {
                    button.click();
                    const opened = Date.now();
                    const check = () => {
                        const drawer = document.querySelector('[data-test="mobile-nav-drawer"]');
                        if (drawer) {
                            resolve({
                                opened: true,
                                projectFamily: drawer.textContent.includes('Project management'),
                                homeLink: !!drawer.querySelector('a[href$="/"]'),
                                projectsLink: !!drawer.querySelector('a[href$="/projects"]'),
                                issuesLink: !!drawer.querySelector('a[href$="/issues"]'),
                                mentionsLink: !!drawer.querySelector('a[href$="/mentions"]'),
                                knowledgeLink: !!drawer.querySelector('a[href$="/knowledge"]'),
                                trashLink: !!drawer.querySelector('a[href$="/knowledge/trash"]'),
                                settingsLink: !!drawer.querySelector('a[href$="/settings/statuses"]'),
                            });
                            return;
                        }
                        if (Date.now() - opened > 5000) {
                            resolve({ opened: false });
                            return;
                        }
                        setTimeout(check, 50);
                    };
                    check();
                    return;
                }
                if (Date.now() - start > 15000) {
                    resolve({ opened: false, timeout: true });
                    return;
                }
                setTimeout(wait, 100);
            };
            wait();
        })
        JS);

    expect($result['opened'])->toBeTrue('the menu button must open the drawer')
        ->and($result['projectFamily'])->toBeTrue('the project family label must be visible')
        ->and($result['projectsLink'])->toBeTrue()
        ->and($result['issuesLink'])->toBeTrue()
        ->and($result['mentionsLink'])->toBeTrue()
        ->and($result['knowledgeLink'])->toBeTrue()
        ->and($result['trashLink'])->toBeTrue()
        ->and($result['settingsLink'])->toBeTrue();

    $page->assertNoSmoke();
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
