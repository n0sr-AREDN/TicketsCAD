/**
 * Service worker — Phase 96 Web Push.
 *
 * Receives encrypted push messages from FCM/APNs, decrypts them via
 * the browser's built-in Web Push crypto (the SW doesn't see the
 * private key — only the browser knows it), and displays the
 * notification in the OS tray.
 *
 * On click, opens the URL the notification carried (incident detail,
 * unit detail, etc.).
 *
 * Lives at the web root (NOT under /assets/) so its scope can be
 * '/' — service workers can only control pages within their own
 * directory scope.
 *
 * ── THERE IS DELIBERATELY NO `fetch` HANDLER AND NO CACHE ─────────────
 *
 * docs/FAQ.md used to claim this worker "caches static assets so the UI
 * shell loads offline". It never has, and after review (2026-07-31,
 * docs/OFFLINE-OPERATION.md D10) it should not. The FAQ was corrected
 * rather than the code, for four reasons worth recording so the omission
 * is not read as an oversight and "fixed":
 *
 *   1. On a LAN install the server IS the box in the closet. When the
 *      internet fails, the server is still up and still serving these
 *      assets in milliseconds. A shell cache optimises a problem that
 *      configuration does not have.
 *   2. A CAD shell without data is worse than an honest error page. A
 *      dispatcher who reaches a loaded UI showing an empty incident list
 *      may reasonably conclude there are no active incidents. A failed
 *      page load cannot be misread that way.
 *   3. Stale cached JavaScript running against an upgraded API is this
 *      project's documented worst bug class (the API/JS contract gate,
 *      tools/api_contract_audit.php), and a service-worker cache makes it
 *      invisible AND unfixable by phone: Ctrl-Shift-R does not clear one.
 *      The About page reads the version server-side, so it would report the
 *      new version while the browser ran the old one.
 *   4. Asset URLs are not consistently versioned yet (several navbar
 *      scripts carry no ?v= at all), so a cache-first shell would pin
 *      exactly those files indefinitely.
 *
 * If you are here to add caching: fix asset versioning first, cache a
 * NAMED allowlist of files rather than a URL pattern, never cache a
 * response fetched with credentials, key the cache name to the app
 * version so an upgrade evicts it — and update docs/FAQ.md and the
 * outbound-disclosure table in the same change. tests/test_service_worker.php
 * enforces the current state.
 */

'use strict';

// On install, take over immediately (no waiting for old SW to expire).
self.addEventListener('install', function (event) {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

// Push event — fired when FCM/APNs delivers a push to this browser.
self.addEventListener('push', function (event) {
    var payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch (e) {
        // Fallback if server sent plain text
        payload = { title: 'TicketsCAD', body: event.data ? event.data.text() : '' };
    }

    var title = payload.title || 'TicketsCAD';
    var options = {
        body:  payload.body || '',
        // RELATIVE, not '/assets/...'. A service worker resolves a relative
        // URL against its own location, so this works on an install served
        // from a subdirectory (http://host/newui/) as well as from a domain
        // root. The absolute form 404'd on every subdirectory install — and
        // assets/icons/ did not exist at all until 2026-07-31, so until then
        // every push notification rendered with no icon everywhere.
        icon:  'assets/icons/icon-192.png',
        badge: 'assets/icons/badge-72.png',
        tag:   payload.tag || 'tcad-notification',
        renotify: true,         // re-alert even if tag matches
        requireInteraction: false,
        data: payload.data || { url: payload.url || '/' },
    };

    // Pull URL out into data so the click handler can find it.
    if (payload.url) options.data.url = payload.url;

    event.waitUntil(self.registration.showNotification(title, options));
});

// Click on notification — focus / open the relevant page.
self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var targetUrl = (event.notification.data && event.notification.data.url) || '/';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            // If a TicketsCAD tab is already open, focus it + navigate
            for (var i = 0; i < clientList.length; i++) {
                var c = clientList[i];
                if ('focus' in c) {
                    c.focus();
                    if ('navigate' in c && targetUrl !== '/') {
                        c.navigate(targetUrl);
                    }
                    return;
                }
            }
            // Otherwise open a new window
            if (clients.openWindow) {
                return clients.openWindow(targetUrl);
            }
        })
    );
});

// Subscription change — re-register if the push service rotates the
// subscription (rare but possible per the Web Push spec).
self.addEventListener('pushsubscriptionchange', function (event) {
    event.waitUntil(
        self.registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: event.oldSubscription
                ? event.oldSubscription.options.applicationServerKey
                : undefined,
        }).then(function (newSub) {
            // Best-effort re-POST to the server. May fail if user
            // session expired; that's fine, they'll re-subscribe next
            // time they visit.
            return fetch('/api/push-subscribe.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    endpoint: newSub.endpoint,
                    keys: {
                        p256dh: arrayBufferToBase64Url(newSub.getKey('p256dh')),
                        auth:   arrayBufferToBase64Url(newSub.getKey('auth')),
                    },
                    csrf_token: '', // best-effort; server may reject
                }),
            }).catch(function () {});
        })
    );
});

function arrayBufferToBase64Url(buffer) {
    var bytes = new Uint8Array(buffer);
    var bin = '';
    for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
    return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}
