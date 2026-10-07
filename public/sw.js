/* Lumiere Premium service worker — app shell + visited pages available offline. */
const VERSION = 'lumiere-v2';
const PAGES = VERSION + '-pages';
const ASSETS = VERSION + '-assets';

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(PAGES).then((c) => c.add('/offline')).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

const NEVER_CACHE = [/^\/logout/, /^\/csrf/, /^\/expenses\/ocr/, /^\/login/, /\/pdf$/, /export=/];

self.addEventListener('fetch', (e) => {
    const req = e.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== location.origin) return;
    if (NEVER_CACHE.some((r) => r.test(url.pathname + url.search))) return;

    // static assets: cache-first, refreshed in background
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/') || url.pathname.startsWith('/storage/')) {
        e.respondWith(
            caches.open(ASSETS).then(async (c) => {
                const hit = await c.match(req);
                const net = fetch(req).then((r) => { if (r.ok) c.put(req, r.clone()); return r; }).catch(() => hit);
                return hit || net;
            })
        );
        return;
    }

    // pages: network first, fall back to the last visited copy, then the offline screen
    if (req.mode === 'navigate' || req.headers.get('accept')?.includes('text/html')) {
        e.respondWith(
            fetch(req)
                .then((r) => {
                    if (r.ok && !r.redirected) caches.open(PAGES).then((c) => c.put(req, r.clone()));
                    return r;
                })
                .catch(async () => (await caches.match(req)) || (await caches.match('/offline')))
        );
    }
});

self.addEventListener('message', (e) => {
    const d = e.data || {};
    if (d.type === 'precache') {
        e.waitUntil(
            caches.open(PAGES).then((c) =>
                Promise.all((d.urls || []).map((u) =>
                    fetch(u, { credentials: 'same-origin' }).then((r) => { if (r.ok && !r.redirected) c.put(u, r); }).catch(() => {})))
            )
        );
    }
    if (d.type === 'clear') {
        e.waitUntil(caches.delete(PAGES).then(() => caches.open(PAGES)).then((c) => c.add('/offline')));
    }
});
