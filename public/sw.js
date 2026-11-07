// Taskr service worker — installability + shell cache. No offline mode:
// navigations (authenticated pages) and any non-static request always hit
// the network, so authenticated data is never served from the cache.
const VERSION = 'taskr-shell-v1';

// Stable, committed shell entries precached at install.
const SHELL = [
    '/manifest.webmanifest',
    '/assets/taskr-app-icon.svg',
    '/favicon.ico',
];

// Static, versioned assets only — runtime-cached on first fetch.
const CACHEABLE = [/^\/build\//, /^\/assets\//, /^\/favicon/];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(VERSION).then((cache) => cache.addAll(SHELL)));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(
                keys.filter((key) => key !== VERSION).map((key) => caches.delete(key)),
            ))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    if (event.request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    // Navigations are never served from the cache.
    if (event.request.mode === 'navigate' || !CACHEABLE.some((pattern) => pattern.test(url.pathname))) {
        return;
    }

    event.respondWith(
        caches.match(event.request).then(
            (cached) =>
                cached ||
                fetch(event.request).then((response) => {
                    if (response.ok) {
                        const copy = response.clone();
                        caches.open(VERSION).then((cache) => cache.put(event.request, copy));
                    }

                    return response;
                }),
        ),
    );
});
