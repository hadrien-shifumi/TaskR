<?php

use App\Support\WorkspaceContext;

/**
 * Mobile-viewport harness gotchas (see the knowledge base): trusted clicks
 * are never delivered, and the served document can be re-delivered between
 * two commands — no React state survives a PHP round-trip. Every mobile
 * scenario therefore runs as a self-contained in-page probe (the same
 * pattern as the layout probes): the script waits for the app to be mounted
 * — the Inertia <Head> title replaces the raw server title — drives the
 * drawer, and reports the outcome, all within one evaluation.
 */
const MOBILE_DRAWER_OPEN_PROBE = <<<'JS'
    new Promise((resolve) => {
        const start = Date.now();
        const wait = () => {
            const button = document.querySelector('[data-test="mobile-menu-button"]');
            if (button && document.title !== 'Laravel') {
                button.click();
                const opened = Date.now();
                const checkDrawer = () => {
                    const drawer = document.querySelector('[data-test="mobile-nav-drawer"]');
                    if (drawer) {
                        resolve({
                            opened: true,
                            projectFamily: drawer.textContent.includes('Project management'),
                            projectsLink: !!drawer.querySelector('a[href$="/projects"]'),
                            issuesLink: !!drawer.querySelector('a[href$="/issues"]'),
                            knowledgeFamily: drawer.textContent.includes('Knowledge'),
                            knowledgeLink: !!drawer.querySelector('a[href$="/knowledge"]'),
                            trashLink: !!drawer.querySelector('a[href$="/knowledge/trash"]'),
                            homeLink: !!drawer.querySelector('a[href$="/"]'),
                            mentionsLink: !!drawer.querySelector('a[href$="/mentions"]'),
                            settingsLink: !!drawer.querySelector('a[href$="/settings/statuses"]'),
                            workspaceSwitcher: !!drawer.querySelector('[aria-label="Switch workspace"]'),
                        });
                        return;
                    }
                    if (Date.now() - opened > 5000) {
                        resolve({ opened: false });
                        return;
                    }
                    setTimeout(checkDrawer, 50);
                };
                checkDrawer();
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
    JS;

const MOBILE_DRAWER_CLOSE_PROBE = <<<'JS'
    new Promise((resolve) => {
        const start = Date.now();
        const openDrawer = (onOpen) => {
            document.querySelector('[data-test="mobile-menu-button"]').click();
            const opened = Date.now();
            const check = () => {
                if (document.querySelector('[data-test="mobile-nav-drawer"]')) {
                    onOpen();
                    return;
                }
                if (Date.now() - opened > 3000) {
                    resolve({ opened: false, closed: false });
                    return;
                }
                setTimeout(check, 40);
            };
            check();
        };
        const awaitClose = (onDone) => {
            const closing = Date.now();
            const check = () => {
                if (!document.querySelector('[data-test="mobile-nav-drawer"]')) {
                    onDone(true);
                    return;
                }
                if (Date.now() - closing > 3000) {
                    onDone(false);
                    return;
                }
                setTimeout(check, 40);
            };
            check();
        };
        const wait = () => {
            const button = document.querySelector('[data-test="mobile-menu-button"]');
            if (button && document.title !== 'Laravel') {
                openDrawer(() => {
                    document.querySelector('[data-test="mobile-nav-drawer"] [data-slot="sheet-close"]').click();
                    awaitClose((closed) => {
                        resolve({ opened: true, closed });
                    });
                });
                return;
            }
            if (Date.now() - start > 15000) {
                resolve({ opened: false, closed: false, timeout: true });
                return;
            }
            setTimeout(wait, 100);
        };
        wait();
    })
    JS;

const MOBILE_DRAWER_ESCAPE_PROBE = <<<'JS'
    new Promise((resolve) => {
        const start = Date.now();
        const openDrawer = (onOpen) => {
            document.querySelector('[data-test="mobile-menu-button"]').click();
            const opened = Date.now();
            const check = () => {
                if (document.querySelector('[data-test="mobile-nav-drawer"]')) {
                    onOpen();
                    return;
                }
                if (Date.now() - opened > 3000) {
                    resolve({ opened: false, closed: false });
                    return;
                }
                setTimeout(check, 40);
            };
            check();
        };
        const awaitClose = (onDone) => {
            const closing = Date.now();
            const check = () => {
                if (!document.querySelector('[data-test="mobile-nav-drawer"]')) {
                    onDone(true);
                    return;
                }
                if (Date.now() - closing > 3000) {
                    onDone(false);
                    return;
                }
                setTimeout(check, 40);
            };
            check();
        };
        const wait = () => {
            const button = document.querySelector('[data-test="mobile-menu-button"]');
            if (button && document.title !== 'Laravel') {
                openDrawer(() => {
                    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
                    awaitClose((closed) => {
                        resolve({ opened: true, closed });
                    });
                });
                return;
            }
            if (Date.now() - start > 15000) {
                resolve({ opened: false, closed: false, timeout: true });
                return;
            }
            setTimeout(wait, 100);
        };
        wait();
    })
    JS;

const MOBILE_DRAWER_BACKDROP_PROBE = <<<'JS'
    new Promise((resolve) => {
        const start = Date.now();
        const openDrawer = (onOpen) => {
            document.querySelector('[data-test="mobile-menu-button"]').click();
            const opened = Date.now();
            const check = () => {
                if (document.querySelector('[data-test="mobile-nav-drawer"]')) {
                    onOpen();
                    return;
                }
                if (Date.now() - opened > 3000) {
                    resolve({ opened: false, closed: false });
                    return;
                }
                setTimeout(check, 40);
            };
            check();
        };
        const awaitClose = (onDone) => {
            const closing = Date.now();
            const check = () => {
                if (!document.querySelector('[data-test="mobile-nav-drawer"]')) {
                    onDone(true);
                    return;
                }
                if (Date.now() - closing > 3000) {
                    onDone(false);
                    return;
                }
                setTimeout(check, 40);
            };
            check();
        };
        const wait = () => {
            const button = document.querySelector('[data-test="mobile-menu-button"]');
            if (button && document.title !== 'Laravel') {
                openDrawer(() => {
                    const backdrop = document.querySelector('[data-slot="sheet-overlay"]');
                    backdrop.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, cancelable: true }));
                    backdrop.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, cancelable: true }));
                    backdrop.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
                    awaitClose((closed) => {
                        resolve({ opened: true, closed });
                    });
                });
                return;
            }
            if (Date.now() - start > 15000) {
                resolve({ opened: false, closed: false, timeout: true });
                return;
            }
            setTimeout(wait, 100);
        };
        wait();
    })
    JS;

const MOBILE_DRAWER_LINK_PROBE = <<<'JS'
    new Promise((resolve) => {
        const start = Date.now();
        const wait = () => {
            const button = document.querySelector('[data-test="mobile-menu-button"]');
            if (button && document.title !== 'Laravel') {
                button.click();
                const opened = Date.now();
                const checkDrawer = () => {
                    const link = document.querySelector('[data-test="mobile-nav-drawer"] a[href$="/issues"]');
                    if (link) {
                        link.click();
                        const closing = Date.now();
                        const checkClosed = () => {
                            if (!document.querySelector('[data-test="mobile-nav-drawer"]')) {
                                resolve({ closed: true });
                                return;
                            }
                            if (Date.now() - closing > 5000) {
                                resolve({ closed: false });
                                return;
                            }
                            setTimeout(checkClosed, 50);
                        };
                        checkClosed();
                        return;
                    }
                    if (Date.now() - opened > 5000) {
                        resolve({ closed: false, neverOpened: true });
                        return;
                    }
                    setTimeout(checkDrawer, 50);
                };
                checkDrawer();
                return;
            }
            if (Date.now() - start > 15000) {
                resolve({ closed: false, timeout: true });
                return;
            }
            setTimeout(wait, 100);
        };
        wait();
    })
    JS;

it('opens the navigation drawer from the header on a mobile viewport', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);
    $this->actingAs($owner);

    $page = visit('/')->on()->mobile();

    $result = $page->script(MOBILE_DRAWER_OPEN_PROBE);

    expect($result['opened'])->toBeTrue('the menu button must open the drawer')
        ->and($result['projectFamily'])->toBeTrue('the project family label must be visible')
        ->and($result['projectsLink'])->toBeTrue()
        ->and($result['issuesLink'])->toBeTrue()
        ->and($result['knowledgeFamily'])->toBeTrue('the knowledge family label must be visible')
        ->and($result['knowledgeLink'])->toBeTrue()
        ->and($result['trashLink'])->toBeTrue()
        ->and($result['homeLink'])->toBeTrue()
        ->and($result['mentionsLink'])->toBeTrue()
        ->and($result['settingsLink'])->toBeTrue()
        ->and($result['workspaceSwitcher'])->toBeTrue('the drawer must carry the workspace switcher');

    $page->assertNoSmoke();
});

it('closes the navigation drawer with the close button, the Escape key and a tap outside', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);
    $this->actingAs($owner);

    $page = visit('/')->on()->mobile();

    // One probe per closing mechanism — each keeps under the harness'
    // per-operation timeout (the drawer waits for its exit animation).
    $button = $page->script(MOBILE_DRAWER_CLOSE_PROBE);
    $escape = $page->script(MOBILE_DRAWER_ESCAPE_PROBE);
    $backdrop = $page->script(MOBILE_DRAWER_BACKDROP_PROBE);

    expect($button['opened'])->toBeTrue('the menu button must open the drawer')
        ->and($button['closed'])->toBeTrue('the close button must close the drawer')
        ->and($escape['opened'])->toBeTrue()
        ->and($escape['closed'])->toBeTrue('the Escape key must close the drawer')
        ->and($backdrop['opened'])->toBeTrue()
        ->and($backdrop['closed'])->toBeTrue('a tap on the backdrop must close the drawer');

    $page->assertNoSmoke();
});

it('closes the drawer when one of its navigation links is tapped', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);
    $this->actingAs($owner);

    $page = visit('/')->on()->mobile();

    // The harness never follows nav-link clicks on mobile (known gotcha —
    // the SmokeTest navigates with navigate()); the tap itself must close
    // the drawer, the hrefs carry the navigation.
    $result = $page->script(MOBILE_DRAWER_LINK_PROBE);

    expect($result['closed'])->toBeTrue('tapping a navigation link must close the drawer');

    $page->assertNoSmoke();
});

it('keeps the menu button hidden on desktop', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);
    $this->actingAs($owner);

    $page = visit('/');

    $page->assertScript("getComputedStyle(document.querySelector('[data-test=\"mobile-menu-button\"]')).display === 'none'")
        ->assertNotPresent('[data-test="mobile-nav-drawer"]')
        ->assertPresent('nav[aria-label="Main"] [data-test="nav-section"]')
        ->assertNoSmoke();
});
