/*
 * KILL SWITCH — this is not a service worker we want, it is one we are
 * removing.
 *
 * A foreign service worker (cache `storefront-shell-v1`, from another
 * tenant's storefront on this host) was registered against these origins
 * during an earlier port/vhost mixup. It was never in this repository, it
 * outlives every deploy, and it intercepts every request on the origin —
 * including caching FAILURES. A 404 served during the seconds a Next.js
 * service restarts got stored permanently, so the app then failed to load
 * its own chunks until the user did a hard refresh (owner report,
 * 2026-08-19: "This page couldn't load ... until hard refresh").
 *
 * A registration cannot be removed from the server side. The only way out is
 * to answer the browser's update check with a worker that deletes itself:
 * the browser fetches /sw.js periodically, installs this, and this unregisters
 * the registration and empties every cache it left behind.
 *
 * Do not "fix" this into a real service worker. If Manfaa ever wants one, it
 * should be introduced deliberately, with a name of its own, and it must
 * never cache a response that is not ok.
 */
self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      // Everything the old worker stored, including the poisoned 404s.
      for (const key of await caches.keys()) {
        await caches.delete(key);
      }

      await self.registration.unregister();

      // Pages controlled by the dying worker are still holding its
      // responses; reload them so they fetch from the network once.
      for (const client of await self.clients.matchAll({ type: 'window' })) {
        client.navigate(client.url).catch(() => {});
      }
    })(),
  );
});

// Until it is gone, never serve anything from a cache and never store a
// response — straight to the network, every time.
self.addEventListener('fetch', (event) => {
  event.respondWith(fetch(event.request));
});
