/**
 * assets/js/map-status.js — tell the dispatcher when the map BACKGROUND has
 * gone, and that the dispatch picture has not.
 *
 * THE PROBLEM (docs/OFFLINE-OPERATION.md D4)
 *
 * When the tile proxy holds no copy of a tile and cannot fetch one, it serves
 * a blank image. That is the right call: a blank tile keeps the map usable,
 * where a broken-image icon or an error status can wedge the whole layer, and
 * incident markers, units, facilities, geofences and routes all stay exactly
 * where they were.
 *
 * But the dispatcher was told nothing at all. No toast, no banner — only
 * console errors and an `X-Tile-Proxy: error` response header nobody reads.
 * Half the screen turns grey mid-incident and the reasonable conclusion is
 * "the CAD has crashed". It has not. One sentence is the difference between a
 * dispatcher who keeps working and one who starts rebooting things.
 *
 * HOW IT DETECTS THE FAILURE
 *
 * Two signals, because the two tile modes fail differently:
 *
 *   * DIRECT mode — the browser fetches the provider itself, so a dead link
 *     produces real `tileerror` events. Count them.
 *   * PROXY mode — the proxy answers 200 with a genuine transparent PNG, so
 *     `tileerror` never fires (deliberately). The failure is carried in the
 *     `X-Tile-Proxy: error` header instead. The tile is same-origin, so this
 *     re-reads one already-loaded tile URL and inspects that header. The
 *     failed tile is served with `Cache-Control: private, max-age=60`, so the
 *     re-read comes from the browser's HTTP cache and costs no network; it is
 *     throttled regardless.
 *
 * `api/tile-proxy.php?action=status` would be the obvious source, but it
 * requires action.manage_config — a dispatcher cannot read it, and a
 * dispatcher is exactly who needs to be told.
 *
 * The banner clears itself the moment a tile loads cleanly again.
 *
 * Admin-configurable via `map_offline_banner` (window.MAP_STATUS_BANNER):
 * on by default, because a dispatcher misreading a grey map as a dead CAD is
 * the more expensive mistake — but an unattended wall display may prefer the
 * map to stay uncluttered.
 */
(function () {
    'use strict';

    /** Consecutive failures before we say anything. One dead tile is noise. */
    var FAIL_THRESHOLD = 6;

    /** Don't re-read a tile's headers more than this often, ms. */
    var HEADER_PROBE_INTERVAL = 4000;

    var _state = {};        // per-map: { fails, banner, lastProbe }

    function enabled() {
        // Undefined means "not configured", which is ON — the safe default is
        // that the dispatcher is told.
        return window.MAP_STATUS_BANNER !== false && window.MAP_STATUS_BANNER !== 0
            && window.MAP_STATUS_BANNER !== '0';
    }

    function stateFor(map) {
        var id = map._leaflet_id;
        if (!_state[id]) { _state[id] = { fails: 0, banner: null, lastProbe: 0 }; }
        return _state[id];
    }

    function showBanner(map) {
        var st = stateFor(map);
        if (st.banner || !enabled()) { return; }
        var container = map.getContainer();
        if (!container) { return; }

        var el = document.createElement('div');
        el.className = 'map-status-banner alert alert-warning py-1 px-2 mb-0 small shadow-sm';
        el.setAttribute('role', 'status');
        el.style.cssText = 'position:absolute;top:8px;left:50%;transform:translateX(-50%);'
                         + 'z-index:1000;max-width:92%;pointer-events:auto;';
        // The second half of the sentence is the important half.
        el.innerHTML = '<i class="bi bi-wifi-off me-1"></i>'
                     + '<strong>Map background unavailable</strong> &mdash; incident data is still live.'
                     + ' <button type="button" class="btn-close btn-close-sm ms-2 align-middle"'
                     + ' aria-label="Dismiss"></button>';
        var close = el.querySelector('.btn-close');
        if (close) {
            close.addEventListener('click', function () {
                hideBanner(map);
                // Dismissing suppresses it until tiles recover and fail again,
                // rather than for ever — an operator who dismisses it once
                // should not be silently blind for the rest of the shift.
                stateFor(map).fails = -FAIL_THRESHOLD;
            });
        }
        container.appendChild(el);
        st.banner = el;
    }

    function hideBanner(map) {
        var st = stateFor(map);
        if (st.banner && st.banner.parentNode) {
            st.banner.parentNode.removeChild(st.banner);
        }
        st.banner = null;
    }

    function recordFailure(map) {
        var st = stateFor(map);
        st.fails++;
        if (st.fails >= FAIL_THRESHOLD) { showBanner(map); }
    }

    function recordSuccess(map) {
        var st = stateFor(map);
        st.fails = 0;
        hideBanner(map);
    }

    /**
     * Proxy mode: re-read one loaded tile and look at X-Tile-Proxy.
     * Same-origin, cached, throttled.
     */
    function probeHeader(map, url) {
        var st = stateFor(map);
        var now = (new Date()).getTime();
        if (now - st.lastProbe < HEADER_PROBE_INTERVAL) { return; }
        st.lastProbe = now;
        if (typeof fetch !== 'function' || !url || url.indexOf('http') === 0) {
            // Absolute URL = a third-party provider; its headers are not
            // readable cross-origin, and that mode reports via tileerror.
            return;
        }
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) {
                var verdict = r.headers.get('X-Tile-Proxy');
                if (verdict === 'error') { recordFailure(map); }
                else if (verdict) { recordSuccess(map); }
            })
            .catch(function () { recordFailure(map); });
    }

    window.MapStatus = {
        /**
         * Watch a base tile layer on a map. Safe to call more than once, and
         * safe to call with a layer that never fails.
         */
        watch: function (map, layer) {
            if (!map || !layer || typeof layer.on !== 'function') { return; }
            if (layer._mapStatusWatched) { return; }
            layer._mapStatusWatched = true;

            // Direct mode — a real failure.
            layer.on('tileerror', function () { recordFailure(map); });

            // Proxy mode — a 200 with a blank pixel. The header tells the truth.
            layer.on('tileload', function (e) {
                var src = (e && e.tile && e.tile.src) || '';
                if (src.indexOf('tile-proxy.php') >= 0) {
                    probeHeader(map, src);
                } else {
                    recordSuccess(map);
                }
            });
        },

        /** For tests and for pages that want to drive it themselves. */
        _recordFailure: recordFailure,
        _recordSuccess: recordSuccess,
        _threshold: FAIL_THRESHOLD
    };
})();
