<?php

use App\Support\WorkspaceContext;

/**
 * Self-contained probes — the harness may re-serve the document between two
 * commands (see the knowledge base): nothing survives a PHP round-trip, so
 * every scenario runs as one in-page evaluation.
 */
const PWA_MANIFEST_PROBE = <<<'JS'
    new Promise((resolve) => {
        const fail = () => resolve({ served: false });
        fetch('/manifest.webmanifest').then(async (response) => {
            if (!response.ok) { fail(); return; }
            const manifest = await response.json();
            const fetched = [];
            for (const icon of manifest.icons ?? []) {
                const iconResponse = await fetch(icon.src);
                fetched.push({ ok: iconResponse.ok, type: iconResponse.headers.get('content-type') });
            }
            resolve({
                served: true,
                name: manifest.name,
                shortName: manifest.short_name,
                startUrl: manifest.start_url,
                display: manifest.display,
                themeColor: manifest.theme_color,
                icons: manifest.icons,
                fetchedOk: fetched.length === (manifest.icons ?? []).length && fetched.every((icon) => icon.ok),
            });
        }, fail);
    })
    JS;

const SW_REGISTERED_PROBE = <<<'JS'
    new Promise((resolve) => {
        const start = Date.now();
        const check = () => {
            navigator.serviceWorker.getRegistration('/').then((registration) => {
                if (registration) {
                    resolve({ registered: true, scope: registration.scope });
                    return;
                }
                if (Date.now() - start > 10000) {
                    resolve({ registered: false, timeout: true });
                    return;
                }
                setTimeout(check, 100);
            }, () => resolve({ registered: false, timeout: true }));
        };
        check();
    })
    JS;

it('serves an installable manifest with the required icons', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);
    $this->actingAs($owner);

    $page = visit('/');

    $result = $page->script(PWA_MANIFEST_PROBE);

    expect($result['served'])->toBeTrue('the manifest must be served')
        ->and($result['name'])->toBe('Taskr')
        ->and($result['shortName'])->toBe('Taskr')
        ->and($result['startUrl'])->toBe('/')
        ->and($result['display'])->toBe('standalone')
        ->and($result['themeColor'])->toBe('#026FD7')
        ->and($result['icons'])->toHaveCount(3)
        ->and($result['fetchedOk'])->toBeTrue('every manifest icon must be served');

    $page->assertNoSmoke();
});

it('registers the service worker at the root scope', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);
    $this->actingAs($owner);

    $page = visit('/');

    $result = $page->script(SW_REGISTERED_PROBE);

    expect($result['registered'])->toBeTrue('the service worker must be registered at the root scope')
        ->and($result['scope'])->toEndWith('/');

    $page->assertNoSmoke();
});

it('carries the install links in the head', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);
    $this->actingAs($owner);

    visit('/')
        ->assertPresent('link[rel="manifest"]')
        ->assertPresent('meta[name="theme-color"]')
        ->assertPresent('link[rel="apple-touch-icon"]')
        ->assertPresent('meta[name="apple-mobile-web-app-capable"]')
        ->assertPresent('meta[name="apple-mobile-web-app-status-bar-style"]')
        ->assertPresent('meta[name="apple-mobile-web-app-title"]')
        ->assertNoSmoke();
});
