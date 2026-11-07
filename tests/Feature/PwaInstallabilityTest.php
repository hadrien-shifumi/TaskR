<?php

/**
 * PWA installability contract: the head declares the metadata (manifest,
 * theme, Apple web-app metas, notch-aware viewport) and the manifest and
 * service worker ship as committed static files at the root scope. The
 * in-process test kernel does not serve public/ statics (404) — serving is
 * proven by the Browser gate; here the file contract is asserted on disk.
 */
it('declares the pwa metadata in the head', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('manifest.webmanifest')
        ->assertSee('viewport-fit=cover')
        ->assertSee('#026FD7')
        ->assertSee('apple-touch-icon.png')
        ->assertSee('apple-mobile-web-app-capable')
        ->assertSee('apple-mobile-web-app-status-bar-style')
        ->assertSee('apple-mobile-web-app-title');
});

it('serves the manifest with the installability fields', function () {
    $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true);

    expect($manifest)->toMatchArray([
        'name' => 'Taskr',
        'short_name' => 'Taskr',
        'start_url' => '/',
        'scope' => '/',
        'display' => 'standalone',
        'theme_color' => '#026FD7',
        'background_color' => '#FCFCFD',
        'icons' => [
            ['src' => '/android-chrome-192x192.png', 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => '/android-chrome-512x512.png', 'sizes' => '512x512', 'type' => 'image/png'],
            ['src' => '/android-chrome-512x512-maskable.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
    ]);
});

it('serves the service worker at the root scope', function () {
    $worker = (string) file_get_contents(public_path('sw.js'));

    expect($worker)->toContain('taskr-shell-v1')
        ->toContain("addEventListener('fetch'");
});

it('ships the committed icon files', function () {
    foreach ([
        'apple-touch-icon.png',
        'android-chrome-192x192.png',
        'android-chrome-512x512.png',
        'android-chrome-512x512-maskable.png',
    ] as $file) {
        expect(public_path($file))->toBeFile();
    }
});
