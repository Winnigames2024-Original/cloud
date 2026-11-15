// Service worker Искры — включает установку как приложение (PWA).
// Стратегия: сеть в первую очередь (всегда свежий код), кэш — только запасной
// вариант офлайн. Имя кэша с версией, чтобы старое чистилось при обновлении.
const CACHE = 'iskra-shell-v14';
const SHELL = ['/assets/icon-192.png'];

self.addEventListener('install', e => {
  self.skipWaiting();
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL).catch(() => {})));
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys()
      .then(ks => Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;
  // Всегда сначала сеть. Успешную статику кладём в кэш как офлайн-резерв.
  e.respondWith(
    fetch(req).then(r => {
      if (r && r.ok && /\/assets\//.test(req.url)) {
        const copy = r.clone();
        caches.open(CACHE).then(c => c.put(req, copy));
      }
      return r;
    }).catch(() => caches.match(req))
  );
});
