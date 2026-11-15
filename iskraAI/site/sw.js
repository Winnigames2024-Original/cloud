// Service worker Искры — включает установку как приложение (PWA).
const CACHE = 'iskra-shell-v1';
const SHELL = ['/assets/app.css?v=12', '/assets/app.js?v=12', '/assets/icon-192.png'];

self.addEventListener('install', e => {
  self.skipWaiting();
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL).catch(() => {})));
});
self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k)))));
  self.clients.claim();
});
self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;
  // статику — из кэша с обновлением, остальное — сеть
  if (/\/assets\//.test(req.url)) {
    e.respondWith(caches.match(req).then(r => r || fetch(req)));
  } else {
    e.respondWith(fetch(req).catch(() => caches.match(req)));
  }
});
