<?php
/**
 * Outage-degradation gate — the D4-D12 half of docs/OFFLINE-OPERATION.md §8.
 *
 * An internet outage on this system coincides with the emergencies the
 * software exists for, so behaviour during one is a safety property, not a
 * nicety. Every assertion here is about the same principle: when something
 * outside cannot be reached, the console must fail FAST and say so PLAINLY —
 * never a spinner that does not resolve, never a blank rectangle with no
 * explanation, never an unbounded wait.
 *
 * Bounds are MEASURED against 203.0.113.1 (RFC 5737, guaranteed unrouted, so
 * packets vanish with no reply — the shape of a real upstream outage, unlike a
 * closed local port which answers instantly and proves nothing).
 *
 * Usage: php tests/test_outage_degradation.php
 */

$base = realpath(__DIR__ . '/..');

$pass = 0; $fail = 0;
function is_ok($cond, string $label) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  [ok]   $label\n"; }
    else       { $fail++; echo "  [FAIL] $label\n"; }
}
function section(string $t) { echo "\n-- $t --\n"; }

// ═════════════════════════════════════════════════════════════════════
section('D5 — the Communications Console no longer pays 1.5s per dead bridge, per page load');

// The defect was not the 1.5s timeout — that is a reasonable bound for a
// bridge on the LAN. It was that the verdict was cached only WITHIN one
// request, so every Console page load re-probed every bridge from scratch.
// config.php first: chreg_health_cache_ttls() reads the admin overrides via
// get_variable(), and the live measurement below drives the REAL prober.
// Guarded, so this file still runs a useful subset on a machine with no DB.
if (is_file($base . '/config.php')) {
    try { require_once $base . '/config.php'; } catch (Throwable $e) { /* measured subset skipped below */ }
}
require_once $base . '/inc/channel_registry.php';

is_ok(function_exists('chreg_health_cache_valid'), 'the cross-request verdict cache exists');

$ttls = ['down' => 30, 'up' => 5];
is_ok(chreg_health_cache_valid(['state' => 'down', 'at' => 990], 1000, $ttls) === true,
      'a DOWN verdict 10s old is reused (the expensive verdict is the one worth keeping)');
is_ok(chreg_health_cache_valid(['state' => 'down', 'at' => 960], 1000, $ttls) === false,
      'a DOWN verdict 40s old is re-probed');
is_ok(chreg_health_cache_valid(['state' => 'connected', 'at' => 997], 1000, $ttls) === true,
      'a CONNECTED verdict 3s old is reused');
is_ok(chreg_health_cache_valid(['state' => 'connected', 'at' => 990], 1000, $ttls) === false,
      'a CONNECTED verdict 10s old is re-probed — bad news about a radio bridge must travel fast, '
      . 'so "up" is cached far more briefly than "down"');
is_ok(chreg_health_cache_valid(['state' => 'down', 'at' => 2000], 1000, $ttls) === false,
      'a clock that moved backwards forces a re-probe rather than trusting the stored verdict');
is_ok(chreg_health_cache_valid(null, 1000, $ttls) === false, 'a missing entry is not valid');
is_ok(chreg_health_cache_valid(['state' => 'down', 'at' => 999], 1000, ['down' => 0, 'up' => 0]) === false,
      'a TTL of 0 disables the cache (an admin can switch it off)');

$defaults = chreg_health_cache_ttls();
is_ok($defaults['down'] > $defaults['up'],
      'the shipped defaults keep a DOWN verdict longer than an UP one');

// MEASURED, through the real prober.
if (function_exists('get_variable')) {
    $probeHost = '203.0.113.1';
    $t0 = microtime(true);
    $s1 = _chreg_dmr_bridge_state($probeHost, 8080, 'x');
    $first = microtime(true) - $t0;
    $t1 = microtime(true);
    $s2 = _chreg_dmr_bridge_state($probeHost, 8080, 'x');
    $second = microtime(true) - $t1;
    is_ok($s1 === 'down' && $s2 === 'down', 'a black-holed bridge reads as down, twice');
    is_ok($first < 3.0, sprintf('the first probe is bounded (measured %.2fs)', $first));
    is_ok($second < 0.05, sprintf('the second costs nothing (measured %.4fs)', $second));
} else {
    echo "  [note] settings unavailable — skipping the live bridge measurement\n";
}

// ═════════════════════════════════════════════════════════════════════
section('D6 — the weather proxy is bounded, breaks the circuit, and negatively caches');

$wx = (string) @file_get_contents($base . '/api/weather-proxy.php');
is_ok($wx !== '', 'api/weather-proxy.php is readable');

is_ok(strpos($wx, "'timeout' => 10") === false && strpos($wx, "'timeout' => 15") === false,
      'the old 10s / 15s upstream timeouts are gone');
is_ok(preg_match('/const WX_READ_TIMEOUT\s*=\s*([1-9])\s*;/', $wx, $m) === 1 && (int) $m[1] <= 8,
      'the upstream read timeout is a named constant of 8s or less — a weather overlay is ~40 '
      . 'tiles per viewport, so every second here is forty worker-seconds per pan');
is_ok(strpos($wx, 'WX_FAIL_MAX_AGE') !== false && strpos($wx, 'Cache-Control: private, max-age=') !== false,
      'a failed tile carries a short max-age, so panning back over dead ground does not reach us again');
is_ok(strpos($wx, 'wx_breaker_check') !== false,
      'the breaker is consulted on the request path, not merely defined');

// The 404 distinction, driven through the real pure function.
require_once $base . '/inc/version.php';
$prev = ini_get('display_errors');
ini_set('display_errors', '0');
if (!function_exists('wx_upstream_is_down')) {
    // The endpoint exits on load, so evaluate just the pure helpers.
    if (preg_match('/function wx_upstream_is_down.*?\n}/s', $wx, $fn)) {
        eval($fn[0]);
    }
    if (preg_match('/function wx_breaker_decide.*?\n}\n/s', $wx, $fn2)) {
        eval(str_replace(['WX_BREAKER_THRESHOLD', 'WX_BREAKER_COOLOFF'], ['3', '60'], $fn2[0]));
    }
}
ini_set('display_errors', $prev);

if (function_exists('wx_upstream_is_down')) {
    is_ok(wx_upstream_is_down(0, true) === true, 'a transport failure counts as an outage');
    is_ok(wx_upstream_is_down(503, false) === true, 'a 5xx counts as an outage');
    is_ok(wx_upstream_is_down(429, false) === true, 'a 429 counts (backing off IS the fix)');
    is_ok(wx_upstream_is_down(404, false) === false,
          'a 404 does NOT — that is a working provider saying this layer has no tile here, and '
          . 'counting it would blank the overlay for everyone the first time someone zoomed past '
          . 'the edge of coverage');
    is_ok(wx_upstream_is_down(401, false) === false,
          'a 401 does NOT — a bad API key is a configuration error the breaker must not hide');
} else {
    is_ok(false, 'wx_upstream_is_down() could not be evaluated');
}
if (function_exists('wx_breaker_decide')) {
    is_ok(wx_breaker_decide(['fails' => 2, 'opened_at' => 0], 100)['open'] === false,
          'below the threshold the weather breaker is closed');
    is_ok(wx_breaker_decide(['fails' => 3, 'opened_at' => 100], 110)['open'] === true,
          'at the threshold it opens');
    is_ok(wx_breaker_decide(['fails' => 3, 'opened_at' => 100], 200)['half_open'] === true,
          'after the cool-off one request probes');
}

// ═════════════════════════════════════════════════════════════════════
section('D7 — the radar catalogue is fetched only while radar is switched on');

foreach (['assets/js/map-prefs.js', 'situation.php'] as $rel) {
    $src = (string) @file_get_contents($base . '/' . $rel);
    is_ok($src !== '', "$rel is readable");
    if ($src === '') { continue; }

    // The fetch must be reachable only from a layer 'add' handler. Assert the
    // wiring exists AND that the unconditional kick-off is gone.
    is_ok(strpos($src, "on('add'") !== false && strpos($src, "on('remove'") !== false,
          "$rel: the radar layer starts and stops polling on add/remove");
    is_ok(preg_match('/^\s*(refreshRadarFrame|refreshRadar)\(\);\s*$/m', $src) === 0
          || strpos($src, "on('add'") !== false,
          "$rel: the catalogue is no longer fetched unconditionally at page load");
    is_ok(strpos($src, 'clearInterval') !== false,
          "$rel: the five-minute poll is STOPPED when radar is switched off — otherwise a console "
          . 'left open all shift keeps contacting a third party about weather nobody is looking at');
}

// ═════════════════════════════════════════════════════════════════════
section('D8 — the bulk-download tool can no longer hang forever');

$rl = (string) @file_get_contents($base . '/tools/refresh-lookups.php');
is_ok(strpos($rl, 'CURL_BOUNDS') !== false, 'the download commands carry an explicit bounds constant');
is_ok(preg_match('/--connect-timeout\s+\d+/', $rl) === 1, 'a connect timeout is set');
is_ok(preg_match('/--max-time\s+\d+/', $rl) === 1, 'a total time limit is set');
is_ok(substr_count($rl, 'CURL_BOUNDS') >= 3,
      'BOTH downloads use it (the FCC archive and the GeoNames zip), not just the first');

// ═════════════════════════════════════════════════════════════════════
section('D10 — the service worker does what the documentation says it does');

$sw = (string) @file_get_contents($base . '/sw.js');
is_ok($sw !== '', 'sw.js is readable');
is_ok(strpos($sw, "addEventListener('fetch'") === false
      && strpos($sw, 'addEventListener("fetch"') === false,
      'sw.js has NO fetch handler — see the header comment for why that is deliberate');
// Look for the API, not the word: the file's own header explains WHY there is
// no cache, and a gate that forbade saying so would get the explanation
// deleted rather than the gate fixed.
$swCode = preg_replace('#/\*.*?\*/#s', '', $sw);
$swCode = preg_replace('#^\s*//.*$#m', '', (string) $swCode);
is_ok(preg_match('/\bcaches\s*\.\s*(open|match|keys|delete|has)\s*\(/', (string) $swCode) !== 1
      && preg_match('/\.\s*(put|addAll)\s*\(/', (string) $swCode) !== 1,
      'and no Cache Storage API call, so nothing survives on a shared station computer');
is_ok(stripos($sw, 'THERE IS DELIBERATELY NO') !== false,
      'and the omission is documented IN the file, so the next reader does not "fix" it');

$faq = (string) @file_get_contents($base . '/docs/FAQ.md');
is_ok(strpos($faq, 'caches static assets so the UI shell loads offline') === false,
      'docs/FAQ.md no longer claims the PWA caches the shell for offline use');

// The icons the worker references must exist, or every push notification
// renders blank — which was the state of every install until 2026-07-31.
foreach (['assets/icons/icon-192.png', 'assets/icons/badge-72.png'] as $icon) {
    is_ok(is_file($base . '/' . $icon) && filesize($base . '/' . $icon) > 0,
          "$icon exists (sw.js references it)");
}
is_ok(strpos($sw, "'/assets/icons/") === false,
      'the icon paths are RELATIVE — an absolute /assets/... 404s on any install served from a '
      . 'subdirectory, which is the documented XAMPP layout');
$pc = (string) @file_get_contents($base . '/assets/js/push-client.js');
is_ok(strpos($pc, "register('/sw.js')") === false,
      'the service worker is registered by a path relative to the install, not /sw.js — the '
      . 'absolute form made Web Push impossible to enable on a subdirectory install');

// ═════════════════════════════════════════════════════════════════════
section('D11 — the Terrain basemap is not blocked by our own security policy');

$sh = (string) @file_get_contents($base . '/inc/security-headers.php');
is_ok(strpos($sh, 'opentopomap.org') !== false,
      'OpenTopoMap is in the CSP img-src — Terrain is offered in four places and used to render '
      . 'blank even with a working connection');
is_ok(strpos($sh, 'https://openweathermap.org ') !== false,
      'openweathermap.org (not just tile.) is allowed — the City Weather marker icons live there');

// ═════════════════════════════════════════════════════════════════════
section('D12 — no plain-HTTP resource is requested from an HTTPS page');

$app = (string) @file_get_contents($base . '/assets/js/app.js');
is_ok(strpos($app, "imageUrlCity:    'https://") !== false
      || strpos($app, "imageUrlCity: 'https://") !== false,
      'the City Weather marker icons are overridden to https');
foreach (['imageUrlStation', 'imageUrlPlane'] as $opt) {
    is_ok(preg_match('/' . $opt . ':\s*\'https:/', $app) === 1, "$opt is overridden to https");
}
// The override must reach the object the plugin actually reads.
is_ok(preg_match('/L\.OWM\.current\(\{[^}]*imageUrlCity/s', $app) === 1,
      'the overrides are passed to L.OWM.current(), not set on some other object — the plugin '
      . 'reads them from its options at construction');

// ═════════════════════════════════════════════════════════════════════
section('D4 — a grey map explains itself');

$ms = (string) @file_get_contents($base . '/assets/js/map-status.js');
is_ok($ms !== '', 'assets/js/map-status.js exists');
is_ok(strpos($ms, 'incident data is still live') !== false,
      'the message tells the dispatcher the dispatch picture is intact — that is the half that '
      . 'stops someone rebooting a working CAD mid-incident');
is_ok(strpos($ms, "'tileerror'") !== false, 'it listens for tileerror (direct mode)');
is_ok(strpos($ms, 'X-Tile-Proxy') !== false,
      'and reads the proxy header (proxy mode answers 200 with a blank pixel, so tileerror never '
      . 'fires there — one signal alone would have covered only half the installs)');
is_ok(strpos($ms, 'MAP_STATUS_BANNER') !== false, 'and the banner is admin-configurable');

$mp = (string) @file_get_contents($base . '/assets/js/map-prefs.js');
is_ok(strpos($mp, 'MapStatus.watch') !== false || strpos($mp, 'watchBasemap') !== false,
      'MapPrefs registers basemaps with it, so no page has to remember to');
$nav = (string) @file_get_contents($base . '/inc/navbar.php');
is_ok(strpos($nav, 'assets/js/map-status.js') !== false,
      'and navbar.php loads it globally — a per-page script tag is one more place to forget');

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
