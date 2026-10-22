/* Madingo service worker (Tahap 19). Sengaja minimal dan aman untuk perangkat bersama:
   - hanya menyimpan halaman "tidak ada koneksi"; data pengguna TIDAK pernah disimpan di cache
   - menerima Web Push dan menampilkan notifikasi
   Ubah VERSION agar peramban memasang pembaruan. */
const VERSION = 'madingo-sw-1';
const OFFLINE = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(VERSION).then((c) => c.add(OFFLINE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.mode !== 'navigate') return; // aset dan API langsung ke jaringan
    event.respondWith(fetch(req).catch(() => caches.match(OFFLINE)));
});

self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) { data = { title: 'Madingo', body: event.data ? event.data.text() : '' }; }
    const title = String(data.title || 'Madingo').slice(0, 120);
    event.waitUntil(self.registration.showNotification(title, {
        body: data.body ? String(data.body).slice(0, 240) : undefined,
        icon: '/icons/icon-192.png',
        badge: '/icons/badge-96.png',
        tag: data.tag || 'madingo',
        renotify: false,
        data: { url: typeof data.url === 'string' && data.url.startsWith('/') ? data.url : '/' },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(event.notification.data?.url || '/', self.location.origin).href;
    event.waitUntil((async () => {
        const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const c of all) {
            if (new URL(c.url).origin === self.location.origin && 'focus' in c) {
                await c.focus();
                if ('navigate' in c) return c.navigate(url);
                return;
            }
        }
        return self.clients.openWindow(url);
    })());
});
