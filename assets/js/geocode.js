/**
 * assets/js/geocode.js — the ONE place the browser does address lookup.
 *
 * WHY THIS FILE EXISTS
 *
 * Until 2026-07-31 there were eleven copies of this logic, each with
 * `https://nominatim.openstreetmap.org` typed into it by hand, in six files.
 * The Settings page had a Geocoding Provider dropdown that no code read, so
 * choosing a different provider — including your own server — did nothing at
 * all. Eleven copies also meant eleven different failure behaviours: one showed
 * an alert, one showed nothing, one only wrote to the console, so a dispatcher
 * pressing Lookup during an outage got a different (or invisible) answer
 * depending on which page they were on.
 *
 * Everything now goes through Geocode.search() / Geocode.reverse(), which
 * return the SAME shape and the SAME error contract everywhere.
 * tools/geocode_audit.php fails the build if a twelfth call site appears with
 * a geocoder hostname in it, and in the shipped (server) mode the Content
 * Security Policy no longer allows the browser to reach a geocoder at all — so
 * a hardcoded call site fails visibly on every install instead of silently
 * leaking the address on all of them.
 *
 * THE RESULT SHAPE (see inc/geocode.php — "THE NEWUI GEOCODE RESULT SHAPE")
 *
 *   { lat: "44.9778", lon: "-93.2650", display_name: "...",
 *     address: { house_number, road, city, town, village, state, postcode,
 *                neighbourhood, suburb, county, country, country_code,
 *                "ISO3166-2-lvl4" } }
 *
 * Every address key is always present (empty string when the provider does not
 * supply it), so `addr.city || addr.town || addr.village` behaves the same for
 * every provider.
 *
 * THE ERROR CONTRACT
 *
 * Neither method rejects. Both resolve with:
 *   { ok: true,  results: [ ... ] }
 *   { ok: false, results: [], error: '<code>', message: '<sentence for a human>' }
 *
 * Callers MUST show `message` and MUST re-enable whatever control they
 * disabled. A geocoder that cannot answer is normal during an outage; a button
 * stuck on a spinner is not.
 */
(function () {
    'use strict';

    // Injected synchronously by inc/navbar.php (window.GEOCODING). If it is
    // missing — an old page, or navbar failed — fall back to server mode
    // rather than to a hardcoded provider: the endpoint can at least explain
    // itself, and it keeps the address off the third party by default.
    function config() {
        var c = window.GEOCODING;
        if (!c || typeof c !== 'object') {
            return { mode: 'server', provider: '', label: '', endpoint: 'api/geocode.php',
                     direct_base: '', unsupported: [], reason: '' };
        }
        return c;
    }

    function blankResult(code, message) {
        return { ok: false, results: [], error: code, message: message,
                 provider: config().provider, source: 'none' };
    }

    /**
     * Always-present address keys, so callers never branch on undefined.
     * Must stay in step with _geocode_result() in inc/geocode.php — the two
     * lists are the same contract seen from either side, and
     * tests/test_geocode.php asserts they match.
     */
    var ADDRESS_KEYS = ['house_number', 'road', 'pedestrian', 'path', 'city', 'town',
                        'village', 'hamlet', 'state', 'postcode', 'neighbourhood',
                        'suburb', 'county', 'country', 'country_code', 'ISO3166-2-lvl4'];

    function normalizeOne(r) {
        if (!r || r.lat === undefined || r.lon === undefined) return null;
        var src = r.address || {};
        var addr = {};
        for (var i = 0; i < ADDRESS_KEYS.length; i++) {
            var k = ADDRESS_KEYS[i];
            addr[k] = (src[k] === undefined || src[k] === null) ? '' : String(src[k]);
        }
        return {
            lat: String(r.lat),
            lon: String(r.lon),
            display_name: r.display_name ? String(r.display_name) : '',
            address: addr
        };
    }

    function normalizeList(list) {
        var out = [];
        if (!list) return out;
        if (!(list instanceof Array)) list = [list];
        for (var i = 0; i < list.length; i++) {
            var one = normalizeOne(list[i]);
            if (one) out.push(one);
        }
        return out;
    }

    // ── Direct mode ──────────────────────────────────────────────────────
    //
    // Only ever reached for a provider the server has declared direct-capable,
    // which today means Nominatim (public or self-hosted): no API key to leak
    // into the browser, and a response already in our shape. `direct_base` is
    // built and validated on the server — this file never assembles a host.

    function directUrl(action, opts) {
        var base = config().direct_base;
        if (!base) return '';
        var qs;
        if (action === 'search') {
            qs = 'format=json&addressdetails=1&limit=' + encodeURIComponent(String(opts.limit || 1))
               + '&q=' + encodeURIComponent(opts.q || '');
            if (opts.viewbox) {
                qs += '&viewbox=' + encodeURIComponent(opts.viewbox) + '&bounded=0';
            }
            if (opts.countrycodes) {
                qs += '&countrycodes=' + encodeURIComponent(opts.countrycodes);
            }
            return base + '/search?' + qs;
        }
        return base + '/reverse?format=json&addressdetails=1'
             + '&lat=' + encodeURIComponent(String(opts.lat))
             + '&lon=' + encodeURIComponent(String(opts.lon));
    }

    // ── Server mode ──────────────────────────────────────────────────────

    function serverUrl(action, opts) {
        var u = config().endpoint + '?action=' + action;
        if (action === 'search') {
            u += '&q=' + encodeURIComponent(opts.q || '')
               + '&limit=' + encodeURIComponent(String(opts.limit || 1));
            if (opts.viewbox)      u += '&viewbox=' + encodeURIComponent(opts.viewbox);
            if (opts.countrycodes) u += '&countrycodes=' + encodeURIComponent(opts.countrycodes);
        } else {
            u += '&lat=' + encodeURIComponent(String(opts.lat))
               + '&lon=' + encodeURIComponent(String(opts.lon));
        }
        return u;
    }

    function run(action, opts) {
        var cfg = config();

        if (cfg.mode === 'off') {
            return Promise.resolve(blankResult('disabled',
                'Address lookup is turned off on this system. Click the map to set the location.'));
        }
        if (typeof fetch !== 'function') {
            return Promise.resolve(blankResult('unsupported',
                'This browser cannot perform address lookup. Click the map to set the location.'));
        }

        if (cfg.mode === 'direct') {
            var du = directUrl(action, opts);
            if (!du) {
                return Promise.resolve(blankResult('not_configured',
                    'No address is configured for the geocoding server.'));
            }
            return fetch(du)
                .then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function (data) {
                    return { ok: true, results: normalizeList(data), error: '', message: '',
                             provider: cfg.provider, source: 'direct' };
                })
                .catch(function () {
                    // Same sentence the server path produces, so a dispatcher
                    // sees one behaviour regardless of the configured mode.
                    return blankResult('upstream_down',
                        'The address lookup service did not answer. You can still place the pin '
                        + 'on the map.');
                });
        }

        return fetch(serverUrl(action, opts), { credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                if (!data || data.ok !== true) {
                    return blankResult(
                        (data && data.error) || 'failed',
                        (data && data.message) || 'Address lookup failed.');
                }
                return { ok: true, results: normalizeList(data.results), error: '', message: '',
                         provider: data.provider || cfg.provider, source: data.source || 'server' };
            })
            .catch(function () {
                return blankResult('unreachable',
                    'Address lookup could not reach this server. You can still place the pin '
                    + 'on the map.');
            });
    }

    window.Geocode = {
        /** 'server' | 'direct' | 'off' — the mode actually in force. */
        mode: function () { return config().mode; },

        /** False when an administrator has switched address lookup off. */
        available: function () { return config().mode !== 'off'; },

        /**
         * Forward geocode.
         * @param {{q:string, limit?:number, viewbox?:string, countrycodes?:string}} opts
         * @returns {Promise<{ok:boolean,results:Array,error:string,message:string}>}
         */
        search: function (opts) {
            opts = opts || {};
            if (!opts.q || !String(opts.q).trim()) {
                return Promise.resolve(blankResult('bad_request', 'Enter an address to look up.'));
            }
            return run('search', opts);
        },

        /**
         * Reverse geocode.
         * @returns {Promise<{ok:boolean,results:Array,error:string,message:string}>}
         */
        reverse: function (lat, lon) {
            if (lat === null || lat === undefined || lon === null || lon === undefined) {
                return Promise.resolve(blankResult('bad_request', 'No coordinates to look up.'));
            }
            return run('reverse', { lat: lat, lon: lon });
        },

        /**
         * Leaflet map bounds → the viewbox string the geocoders expect
         * (west,north,east,south). Returns '' when there is no map, so callers
         * can pass it unconditionally.
         */
        viewboxFromMap: function (map) {
            if (!map || typeof map.getBounds !== 'function') return '';
            try {
                var b = map.getBounds();
                return b.getWest().toFixed(4) + ',' + b.getNorth().toFixed(4) + ','
                     + b.getEast().toFixed(4) + ',' + b.getSouth().toFixed(4);
            } catch (e) {
                return '';
            }
        },

        /** Result fields the configured provider cannot supply. */
        unsupported: function () { return config().unsupported || []; }
    };
})();
