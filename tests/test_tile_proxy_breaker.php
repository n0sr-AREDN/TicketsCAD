<?php
/**
 * The tile proxy must not be able to exhaust the web server. 2026-07-31.
 *
 * WHAT THIS IS DEFENDING AGAINST
 * -----------------------------
 * The server-side tile proxy shipped on 2026-07-31 (commit 872143d) with a
 * 5-second connect timeout and `Cache-Control: no-store` on failures.
 * Measured against 203.0.113.1 (RFC 5737 TEST-NET-3 — unrouted, so a SYN gets
 * no reply at all, the shape of an upstream outage rather than a local
 * refusal):
 *
 *     one tile ............................ 5.02 s
 *     a 1920x1080 viewport (~40 tiles) .... 200.6 worker-seconds
 *
 * and because the failure was `no-store`, the browser forgot it instantly and
 * re-requested the same dead tiles on the next pan. Repeated for the whole
 * outage. A handful of dispatchers panning over uncached ground can occupy a
 * small server's ~150 request slots — self-inflicted denial of service at the
 * moment the map matters most.
 *
 * Three mechanisms fix it and each is asserted here: a smaller timeout, a
 * per-provider circuit breaker, and negative caching of the failure.
 *
 * The fourth property matters just as much and is the last section: a failed
 * tile must degrade the BASEMAP without breaking the map. That is why the
 * proxy answers 200 with a real transparent PNG instead of an error status —
 * Leaflet raises no `tileerror`, the layer is not wedged, and every incident
 * marker, unit, facility and drawing keeps rendering on its own pane.
 */

require_once __DIR__ . '/../inc/tile-proxy.php';

$pass = 0; $fail = 0; $skipped = 0;
function ok(string $m): void   { global $pass; $pass++; echo "  PASS: $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  FAIL: $m\n"; }
function is_ok($cond, string $m): void { $cond ? ok($m) : bad($m); }
function skip(string $m): void { global $skipped; $skipped++; echo "  SKIP: $m\n"; }

echo "\n=== Tile proxy — a dead upstream must cost almost nothing ===\n";

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 1. The timeouts are small, because they are paid ~40x per pan --\n";

is_ok(TILE_PROXY_CONNECT_TIMEOUT > 0 && TILE_PROXY_CONNECT_TIMEOUT <= 3,
      'connect timeout is ' . TILE_PROXY_CONNECT_TIMEOUT . 's (<= 3s: 40 tiles x this is the pan cost)');
is_ok(TILE_PROXY_READ_TIMEOUT > TILE_PROXY_CONNECT_TIMEOUT && TILE_PROXY_READ_TIMEOUT <= 10,
      'total timeout is ' . TILE_PROXY_READ_TIMEOUT . 's (> connect, <= 10s)');
is_ok(TILE_PROXY_CONNECT_TIMEOUT * 40 <= 120,
      'worst case for one uncached viewport is ' . (TILE_PROXY_CONNECT_TIMEOUT * 40)
      . ' worker-seconds before the breaker gets involved (was 200)');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. What counts as \"upstream is down\" (pure) --\n";

// This distinction is the difference between a breaker that protects the
// server and one that blanks a healthy provider's map. A 404 means the
// provider ANSWERED — it is telling the truth about its coverage, which is
// what a dispatcher gets by zooming past the edge of a map.
is_ok(tile_upstream_is_down(0, 'Connection timeout after 2008 ms') === true,
      'a connect timeout is down');
is_ok(tile_upstream_is_down(0, '') === true,
      'a transfer that never produced a status is down');
is_ok(tile_upstream_is_down(503, '') === true,   '503 is down');
is_ok(tile_upstream_is_down(500, '') === true,   '500 is down');
is_ok(tile_upstream_is_down(429, '') === true,   '429 is down — backing off IS the correct response');
is_ok(tile_upstream_is_down(404, '') === false,  '404 is NOT down — that tile simply does not exist');
is_ok(tile_upstream_is_down(200, '') === false,  '200 is not down');
is_ok(tile_upstream_is_down(304, '') === false,  '304 is not down');
is_ok(tile_upstream_is_down(403, '') === false,
      '403 is not down — a key problem is not an outage, and blanking every tile would hide it');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. The breaker decision (pure) --\n";

$T     = 1800000000;
$armed = ['fails' => TILE_BREAKER_THRESHOLD, 'opened_at' => $T, 'last_error' => 'HTTP 0'];

is_ok(tile_breaker_decide(['fails' => 0, 'opened_at' => 0], $T)['open'] === false,
      'a clean provider is not broken');
is_ok(tile_breaker_decide(['fails' => TILE_BREAKER_THRESHOLD - 1, 'opened_at' => 0], $T)['open'] === false,
      'one failure short of the threshold — still closed (a single flaky tile must not blank the map)');
is_ok(tile_breaker_decide($armed, $T)['open'] === true,
      'at the threshold — open');
is_ok(tile_breaker_decide($armed, $T + TILE_BREAKER_COOLOFF - 1)['open'] === true,
      'one second before the cool-off ends — still open');
$half = tile_breaker_decide($armed, $T + TILE_BREAKER_COOLOFF);
is_ok($half['open'] === false && $half['half_open'] === true,
      'at the cool-off — half-open, one request probes');
is_ok(tile_breaker_decide($armed, $T + 10)['retry_in'] === TILE_BREAKER_COOLOFF - 10,
      'retry_in counts down honestly');
is_ok(tile_breaker_decide($armed, $T, 0)['open'] === false,
      'threshold 0 disables the breaker');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. The stored state machine, through the real read/write --\n";

$P = '__test_provider';
if (!tile_proxy_valid_provider_id($P)) {
    // The identifier rules are also the path-traversal guard; if this name is
    // not acceptable the persistence tests cannot run, and that is worth
    // saying out loud rather than quietly skipping the whole section.
    $P = 'osm';
}
$dir = tile_breaker_dir();
$writable = is_dir($dir) || @mkdir($dir, 0755, true) || is_dir($dir);
if (!$writable) {
    skip('cannot create ' . $dir . ' — breaker persistence not assertable here');
} else {
    tile_breaker_reset($P);
    is_ok(tile_breaker_is_open($P) === false, 'a provider with no history is closed');

    for ($i = 1; $i < TILE_BREAKER_THRESHOLD; $i++) {
        tile_breaker_record_failure($P, 'HTTP 0 probe');
        is_ok(tile_breaker_is_open($P) === false,
              "after {$i} failure(s) — still closed (threshold is " . TILE_BREAKER_THRESHOLD . ')');
    }
    tile_breaker_record_failure($P, 'HTTP 0 probe');
    is_ok(tile_breaker_is_open($P) === true,
          'at the threshold the breaker opens');

    // The window must not keep sliding while failures continue, or a busy map
    // would push the retry away forever and the basemap would never come back.
    $st1 = tile_breaker_read($P);
    tile_breaker_record_failure($P, 'HTTP 0 probe');
    $st2 = tile_breaker_read($P);
    is_ok($st1['opened_at'] === $st2['opened_at'],
          'further failures do NOT push the retry time away (the window is stamped once)');

    $status = tile_breaker_status();
    $mine = null;
    foreach ($status as $s) if ($s['provider'] === $P) $mine = $s;
    is_ok($mine !== null && $mine['open'] === true,
          'action=status reports the open breaker so an admin can see it');
    is_ok($mine !== null && strpos((string) $mine['last_error'], 'HTTP 0') !== false,
          'and names the last error');

    tile_breaker_record_success($P);
    is_ok(tile_breaker_is_open($P) === false,
          'one success closes it again — the map comes back by itself');
    is_ok(tile_breaker_read($P)['fails'] === 0,
          'and the counter resets, so the next outage starts from zero');

    tile_breaker_reset($P);
    is_ok(tile_breaker_read($P)['fails'] === 0, 'reset clears the state');
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 5. The bound, against a genuinely black-holed upstream --\n";
echo "   203.0.113.1 is RFC 5737 TEST-NET-3: never routed, so packets vanish.\n";

// Upper bounds only. On a network that answers with an ICMP unreachable this
// returns instantly, which is fine — the assertion is that it can never take
// LONG, never that it must take a particular time.
$t0  = microtime(true);
$res = tile_http_get('http://203.0.113.1/12/1023/1479.png', ['User-Agent: TicketsCAD-test']);
$one = microtime(true) - $t0;
printf("   one tile against a black hole: %.2fs (status=%d)\n", $one, $res['status']);

$ceiling = TILE_PROXY_READ_TIMEOUT + 2;
is_ok($one <= $ceiling,
      sprintf('a single tile fetch is bounded: %.2fs <= %ds', $one, $ceiling));
is_ok($res['status'] === 0 || $res['status'] >= 400,
      'and it reports failure rather than a plausible-looking tile');
is_ok(tile_upstream_is_down((int) $res['status'], (string) $res['error']) === true,
      'the failure is classified as "upstream down", so it counts toward the breaker');

if ($writable) {
    // The property that actually matters: the SECOND pan is free. Model the
    // endpoint's decision sequence — check, fetch, record — for 40 tiles.
    $P2 = 'osm';
    tile_breaker_reset($P2);
    $pan = function (int $tiles) use ($P2) {
        $t = microtime(true); $net = 0;
        for ($i = 0; $i < $tiles; $i++) {
            if (tile_breaker_check($P2)['open']) continue;
            $r = tile_http_get('http://203.0.113.1/12/1023/' . (9000 + $i) . '.png', []);
            $net++;
            if (tile_upstream_is_down((int) $r['status'], (string) $r['error'])) {
                tile_breaker_record_failure($P2, 'HTTP ' . $r['status']);
            } else {
                tile_breaker_record_success($P2);
            }
        }
        return ['s' => microtime(true) - $t, 'net' => $net];
    };
    $pan1 = $pan(40);
    $pan2 = $pan(40);
    printf("   pan 1: %.2f worker-seconds, %d upstream attempts\n", $pan1['s'], $pan1['net']);
    printf("   pan 2: %.2f worker-seconds, %d upstream attempts\n", $pan2['s'], $pan2['net']);

    is_ok($pan1['net'] <= TILE_BREAKER_THRESHOLD,
          'the first uncached pan makes at most ' . TILE_BREAKER_THRESHOLD
          . ' upstream attempts, not 40 (' . $pan1['net'] . ')');
    is_ok($pan2['net'] === 0,
          'the second pan makes NO upstream attempt at all while the breaker is open');
    is_ok($pan2['s'] < 1.0,
          sprintf('and costs %.2fs of server time instead of 200', $pan2['s']));
    tile_breaker_reset($P2);
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 6. Negative caching: a failed tile is not re-requested immediately --\n";

$api = (string) @file_get_contents(__DIR__ . '/../api/tile-proxy.php');
is_ok($api !== '', 'api/tile-proxy.php is readable');

// Isolate the function body rather than searching the whole file: the header
// comment explains what `no-store` used to do, and an assertion that matched
// prose would fail on a correct file. (It did, on the first run of this test.)
$soft = '';
if (preg_match('/function tile_fail_soft\([^)]*\)[^{]*\{(.*?)\n\}/s', $api, $mm) === 1) {
    $soft = $mm[1];
}
is_ok($soft !== '', 'tile_fail_soft() body located');
is_ok($soft !== '' && strpos($soft, "'Cache-Control: no-store'") === false,
      'failures are no longer sent no-store — that is what made the browser retry every pan');
is_ok($soft !== '' && strpos($soft, 'Cache-Control: private, max-age') !== false,
      'the blank tile carries a max-age, so a pan back over the same ground does not reach us');
is_ok(TILE_FAIL_MAX_AGE > 0 && TILE_FAIL_MAX_AGE <= 300,
      'the negative cache lives ' . TILE_FAIL_MAX_AGE . 's — long enough to help, short enough '
      . 'that the map comes back promptly when the link does');

// Ordering: the breaker has to be consulted BEFORE the fetch, or it saves
// nothing. Asserting the order in the source is crude, but it catches the
// refactor that moves the check below the call and quietly restores the cost.
$posCheck = strpos($api, 'tile_breaker_check(');
$posFetch = strpos($api, 'tile_http_get(');
is_ok($posCheck !== false && $posFetch !== false && $posCheck < $posFetch,
      'the endpoint consults the breaker BEFORE it opens a socket');
is_ok(strpos($api, 'tile_breaker_record_failure(') !== false
      && strpos($api, 'tile_breaker_record_success(') !== false,
      'and records both outcomes, so the breaker can open and close');

// Stale-if-error must still win over a blank tile: an old basemap beats grey.
is_ok(preg_match("/breaker\\['open'\\].*?is_file\\(\\\$cachePath\\).*?'STALE'/s", $api) === 1,
      'with the breaker open a cached tile is still served — stale beats blank');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 7. A failing basemap must not break the map --\n";

// The dispatch-critical property. Incident markers, unit positions,
// facilities, drawings and geofences all come from the local database and
// render on Leaflet panes that have nothing to do with the tile layer. What
// could break them is the TILE response: a 4xx/5xx paints broken-image icons
// and, in some browsers, wedges the layer. So the proxy answers 200 with a
// real image, and that is what is checked here.
if (preg_match("/TILE_BLANK_PNG_B64\s*=\s*\n?\s*'([A-Za-z0-9+\/=]+)'/", $api, $m) !== 1) {
    bad('could not find the blank-tile constant in api/tile-proxy.php');
} else {
    $png = base64_decode($m[1], true);
    is_ok($png !== false && strlen($png) > 20, 'the blank tile decodes to real bytes');
    is_ok($png !== false && substr($png, 0, 8) === "\x89PNG\r\n\x1a\n",
          'and it is a valid PNG (an invalid image would fire tileerror and wedge the layer)');
    $info = $png !== false ? @getimagesizefromstring($png) : false;
    is_ok(is_array($info) && $info[0] === 1 && $info[1] === 1,
          'a 1x1 transparent PNG — the basemap gets a gap, nothing is painted over the markers');
}
is_ok($soft !== '' && strpos($soft, 'json_error') === false
      && strpos($soft, 'http_response_code') === false,
      'a failed tile never answers with an error status');
is_ok($soft !== '' && strpos($soft, "header('Content-Type: image/png')") !== false,
      'it answers with an image content type, which is what keeps the layer alive');
is_ok($soft !== '' && strpos($soft, "X-Tile-Proxy: error") !== false,
      'the failure is still announced in a header rather than being silent');

// The privacy property the proxy exists for must survive the fix: a failure
// must never turn into "let the browser fetch it directly", which would
// disclose the viewport — i.e. the incident location — to the provider at
// exactly the moment the operator chose not to.
is_ok(strpos($api, 'tile.openstreetmap.org') === false
      && stripos($api, 'redirect') === false,
      'a failure never falls back to a direct browser fetch (that would leak the viewport)');

echo "\n=== $pass passed, $fail failed, $skipped skipped ===\n";
exit($fail > 0 ? 1 : 0);
