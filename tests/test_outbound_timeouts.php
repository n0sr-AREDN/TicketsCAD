<?php
/**
 * Gate: every outbound network call must carry an explicit timeout.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────
 *
 * This is dispatch software for volunteer fire, EMS, search-and-rescue and
 * emergency-communications teams. The storm that takes the internet out is
 * the same storm that fills the incident board. So the question "what does
 * this do when an upstream link dies mid-incident?" is not academic — it is
 * the design case.
 *
 * A PHP request that makes an outbound call with no timeout does not fail.
 * It WAITS. The dispatcher's browser spins, the Apache worker stays busy,
 * and with enough of them the whole install stops answering — during exactly
 * the event the software exists for. An outage that should have degraded the
 * map into a grey rectangle takes the CAD down with it.
 *
 * Measured on 2026-07-31 against 203.0.113.1 (RFC 5737 TEST-NET-3 — reserved
 * for documentation, guaranteed unrouted, so packets vanish with no RST and
 * no ICMP: the exact shape of an upstream outage rather than a local refusal):
 *
 *   curl, CURLOPT_CONNECTTIMEOUT=5 + CURLOPT_TIMEOUT=12 ...... blocked  5.00s
 *   curl, CURLOPT_TIMEOUT=10, no connect timeout ............. blocked 10.01s
 *   curl, NO timeout of any kind ............................. blocked 21.03s
 *   file_get_contents, stream context 'timeout' => 10 ........ blocked 10.00s
 *   fsockopen(host, port, e, s, 10) .......................... blocked 10.00s
 *
 * Two things that reading the code would have got wrong, and which is why
 * this gate asserts on what it does rather than on a style rule:
 *
 *   1. A stream context 'timeout' DOES bound the connect phase, not only the
 *      read. The 1.5s / 5s / 10s / 15s contexts in this codebase are real
 *      bounds, not decoration.
 *   2. CURLOPT_TIMEOUT alone is sufficient, because it covers the WHOLE
 *      transfer including the connect. A missing CURLOPT_CONNECTTIMEOUT next
 *      to a present CURLOPT_TIMEOUT is untidy, not dangerous.
 *
 * The genuinely unbounded case is the third line: NO timeout at all. 21s is
 * the Windows TCP SYN budget; on Linux, tcp_syn_retries defaults to 6, which
 * is roughly 127s, and libcurl's own default connect timeout is 300s. That
 * is the case this gate is here to keep out.
 *
 * ── WHAT THIS CHECKS ─────────────────────────────────────────────────
 *
 *   1. Every curl_init() reaches its curl_exec() with a CURLOPT_TIMEOUT
 *      (or _MS) set.
 *   2. Every stream_context_create() that configures the http/https wrapper
 *      carries a 'timeout' key.
 *   3. Every fsockopen / pfsockopen / stream_socket_client passes its
 *      timeout argument rather than inheriting default_socket_timeout (60s).
 *   4. No curl/wget command line is built without --max-time.
 *   5. The vendored web-push library still defaults to a bounded timeout —
 *      it is the one outbound call on the dispatch hot path that this
 *      project does not set a timeout for itself, so an upgrade that removed
 *      the default would silently un-bound every status change.
 *
 * ── WHAT THIS DOES NOT CHECK (read this before trusting it) ──────────
 *
 *   - Browser-side fetch(). No JS call site in this codebase uses
 *     AbortController, so a hung request is bounded only by the browser's own
 *     (long) network timeout. That is a real gap; it is not expressible here.
 *   - DNS. PHP offers no timeout control over gethostbynamel()
 *     (inc/webhooks.php runs one before every delivery). If the resolver
 *     itself is unreachable, that call blocks for the resolver's budget no
 *     matter what any curl option says.
 *   - Whether a timeout VALUE is sensible. A 300s timeout passes this gate.
 *   - AGGREGATE cost. The largest outage hazard in this application is not
 *     one slow call, it is many correctly-bounded ones: a map viewport is
 *     ~40 tiles, so a 5s-per-tile bound is ~200 worker-seconds per pan, and
 *     it repeats because failed tiles are served no-store. A per-call-site
 *     rule cannot see that. See docs/OFFLINE-OPERATION.md.
 *   - The Python services under services/, and protocol daemons under proxy/
 *     which are long-lived processes rather than request handlers.
 *
 * Usage: php tests/test_outbound_timeouts.php
 */

$passed = 0;
$failed = 0;

function test($label, $condition, $hint = '') {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $label\n";
        $passed++;
    } else {
        echo "[FAIL] $label" . ($hint !== '' ? " — $hint" : '') . "\n";
        $failed++;
    }
}

$root = dirname(__DIR__);

// ─────────────────────────────────────────────────────────────────────
// Known-unbounded call sites.
//
// These are OPEN DEFECTS, recorded here so this gate can pass at today's
// baseline and still fail on anything NEW. They are not blessed. Each is
// listed in the defect table in docs/OFFLINE-OPERATION.md.
//
// An entry is keyed by path. Removing the defect makes its entry stale, and
// the staleness assertion below then fails — so a fix forces the allowlist
// to shrink, and the list can never quietly rot into a permanent exemption.
// ─────────────────────────────────────────────────────────────────────
$ALLOW = [
    // tools/refresh-lookups.php was here (docs/OFFLINE-OPERATION.md D8). Fixed
    // 2026-07-31: both bulk downloads now carry CURL_BOUNDS
    // (--connect-timeout 30 --max-time 3600 --retry 2). Removing the entry is
    // required, not optional — the staleness assertion below fails while a
    // fixed file is still listed, which is what stops this list rotting into a
    // set of permanent exemptions.
    'tools/test_api_endpoints.php' =>
        'Integration test harness; curl_init without CURLOPT_TIMEOUT against this '
        . 'install\'s own localhost URL. Test-only, never on a request path.',
    'tools/test_map_markup_rename.php' =>
        'Same: localhost-only integration harness with no CURLOPT_TIMEOUT.',
];

// ─────────────────────────────────────────────────────────────────────
// Scanner
// ─────────────────────────────────────────────────────────────────────

/** Every application PHP file. Third-party and other agents' worktrees excluded. */
function ot_php_files(string $root): array {
    $out  = [];
    $skip = ['/vendor/', '/node_modules/', '/.git/', '/.claude/', '/sonar-scanner-temp/', '/backups/'];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        $p = str_replace('\\', '/', $f->getPathname());
        if (substr($p, -4) !== '.php') { continue; }
        foreach ($skip as $s) { if (strpos($p, $s) !== false) { continue 2; } }
        $out[] = $p;
    }
    sort($out);
    return $out;
}

function ot_line_of(string $src, int $off): int {
    return substr_count($src, "\n", 0, $off) + 1;
}

/**
 * Extent of the balanced parentheses of the call whose name ends at $fnOff.
 * String literals are skipped so a bracket inside a string cannot unbalance it.
 *
 * @return array{0:int,1:int} offsets of the opening and closing paren
 */
function ot_call_extent(string $src, int $fnOff): array {
    $open = strpos($src, '(', $fnOff);
    if ($open === false) { return [$fnOff, $fnOff]; }
    $depth = 0;
    $n = strlen($src);
    for ($i = $open; $i < $n; $i++) {
        $c = $src[$i];
        if ($c === '(') {
            $depth++;
        } elseif ($c === ')') {
            $depth--;
            if ($depth === 0) { return [$open, $i]; }
        } elseif ($c === "'" || $c === '"') {
            $q = $c;
            for ($i++; $i < $n; $i++) {
                if ($src[$i] === '\\') { $i++; continue; }
                if ($src[$i] === $q) { break; }
            }
        }
    }
    return [$open, $n - 1];
}

/** Count top-level (comma-separated) arguments in an argument string. */
function ot_count_args(string $args): int {
    if (trim($args) === '') { return 0; }
    $depth = 0;
    $n = 1;
    for ($i = 0, $L = strlen($args); $i < $L; $i++) {
        $c = $args[$i];
        if ($c === '(' || $c === '[') {
            $depth++;
        } elseif ($c === ')' || $c === ']') {
            $depth--;
        } elseif ($c === ',' && $depth === 0) {
            $n++;
        } elseif ($c === "'" || $c === '"') {
            $q = $c;
            for ($i++; $i < $L; $i++) {
                if ($args[$i] === '\\') { $i++; continue; }
                if ($args[$i] === $q) { break; }
            }
        }
    }
    return $n;
}

/** True when the character before an identifier means it is part of a longer name. */
function ot_is_own_token(string $src, int $p): bool {
    if ($p === 0) { return true; }
    $b = $src[$p - 1];
    return !($b === '_' || ctype_alnum($b));
}

$violations = [];   // [relpath, line, kind, detail]
$siteCount  = 0;

foreach (ot_php_files($root) as $file) {
    $rel = ltrim(str_replace(str_replace('\\', '/', $root), '', str_replace('\\', '/', $file)), '/');
    if ($rel === 'tests/test_outbound_timeouts.php') { continue; }  // this file's own examples
    $src = (string) @file_get_contents($file);
    if ($src === '') { continue; }

    // 1. cURL handles
    $off = 0;
    while (($p = strpos($src, 'curl_init', $off)) !== false) {
        $off = $p + 9;
        if (!ot_is_own_token($src, $p)) { continue; }
        // `function_exists('curl_init')` is a capability PROBE, not a call
        // site — it opens no connection and cannot block. Counting it produced
        // a false positive the moment a file asked whether cURL was available
        // before choosing a code path (inc/health-check.php, 2026-07-31), and
        // a gate that cries wolf gets baselined into uselessness.
        $before = substr($src, max(0, $p - 32), min(32, $p));
        if (preg_match('/(function_exists|extension_loaded|is_callable)\s*\(\s*[\'"]$/', $before)) {
            continue;
        }
        $siteCount++;
        $execAt = strpos($src, 'curl_exec', $p);
        $end = ($execAt !== false && $execAt - $p < 8000) ? $execAt : min(strlen($src), $p + 6000);
        if (strpos(substr($src, $p, $end - $p), 'CURLOPT_TIMEOUT') === false) {
            $violations[] = [$rel, ot_line_of($src, $p), 'curl',
                'curl_init() reaches curl_exec() with no CURLOPT_TIMEOUT'];
        }
    }

    // 2. http/https stream contexts
    $off = 0;
    while (($p = strpos($src, 'stream_context_create', $off)) !== false) {
        $off = $p + 21;
        if (!ot_is_own_token($src, $p)) { continue; }
        [$o, $c] = ot_call_extent($src, $p);
        $win = substr($src, $o, $c - $o + 1);
        if (!preg_match('/[\'"]https?[\'"]\s*=>/', $win)) { continue; }  // not the http wrapper
        $siteCount++;
        if (!preg_match('/[\'"]timeout[\'"]\s*=>/', $win)) {
            $violations[] = [$rel, ot_line_of($src, $p), 'stream',
                'http stream context with no \'timeout\' key (inherits default_socket_timeout, 60s)'];
        }
    }

    // 3. Raw socket openers
    foreach ([['fsockopen', 5], ['pfsockopen', 5], ['stream_socket_client', 4]] as $pair) {
        [$fn, $minArgs] = $pair;
        $off = 0;
        while (($p = strpos($src, $fn . '(', $off)) !== false) {
            $off = $p + strlen($fn);
            if (!ot_is_own_token($src, $p)) { continue; }
            $siteCount++;
            [$o, $c] = ot_call_extent($src, $p);
            $n = ot_count_args(substr($src, $o + 1, $c - $o - 1));
            if ($n < $minArgs) {
                $violations[] = [$rel, ot_line_of($src, $p), 'socket',
                    "$fn() called with $n argument(s) — the timeout argument (#$minArgs) is absent"];
            }
        }
    }

    // 4. curl/wget command lines.
    //
    // The window examined runs to the end of the STATEMENT, not to the closing
    // quote of the first string literal. A command line in this codebase is
    // routinely assembled by concatenation —
    //
    //     _run("curl -sSfL " . CURL_BOUNDS . " -o " . escapeshellarg($zip) . …)
    //
    // — so a literal-only match sees `"curl -sSfL "`, finds no --max-time, and
    // reports a bounded call as unbounded. That is the same blind spot that
    // hid all 89 writer INSERTs from tools/schema_audit.php until
    // tools/sql_extract.php learned to stitch concatenation chains (Phase 125).
    // Getting it wrong in this direction is the dangerous one: it also means a
    // real FIX cannot be recognised, so the exemption never goes away.
    if (preg_match_all('/[\'"](?:curl|wget)\s+-[^\'"]*/i', $src, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $hit) {
            $siteCount++;
            // Extend to the end of the statement (or 600 chars, whichever
            // comes first) so concatenated flags and flag constants count.
            $semi = strpos($src, ";\n", $hit[1]);
            $stmtEnd = ($semi !== false && $semi - $hit[1] < 600) ? $semi : ($hit[1] + 600);
            $window = substr($src, $hit[1], $stmtEnd - $hit[1]);
            if (!preg_match('/--max-time|--connect-timeout|\s-m\s+\d|--timeout|\s-T\s*\d|CURL_BOUNDS/', $window)) {
                $violations[] = [$rel, ot_line_of($src, $hit[1]), 'cmdline',
                    'command line "' . trim(substr($hit[0], 1, 36)) . '…" has no --max-time'];
            }
        }
    }
}

echo "-- Scan --\n";
echo "Outbound call sites examined: $siteCount\n";
echo "Sites without an explicit timeout: " . count($violations) . "\n\n";

// ─────────────────────────────────────────────────────────────────────
// Assertions
// ─────────────────────────────────────────────────────────────────────
echo "-- The gate --\n";

test('the scanner actually found call sites (a scanner that finds nothing always passes)',
    $siteCount >= 40,
    "only $siteCount sites seen — the scan is probably broken, not the codebase clean");

$unexpected = [];
$knownHit   = [];
foreach ($violations as $v) {
    if (isset($ALLOW[$v[0]])) {
        $knownHit[$v[0]] = true;
    } else {
        $unexpected[] = $v;
    }
}

if (!empty($unexpected)) {
    echo "\n  New unbounded outbound call site(s):\n";
    foreach ($unexpected as $v) {
        echo "    {$v[0]}:{$v[1]}  [{$v[2]}]  {$v[3]}\n";
    }
    echo "\n  An outbound call with no timeout blocks a PHP worker for the OS SYN\n";
    echo "  budget (~127s on Linux) or libcurl's 300s default. Set CURLOPT_TIMEOUT,\n";
    echo "  a stream-context 'timeout', or pass the socket timeout argument.\n";
    echo "  If it genuinely cannot be bounded, add it to \$ALLOW with a reason AND\n";
    echo "  to the defect table in docs/OFFLINE-OPERATION.md.\n\n";
}
test('no outbound call site lacks a timeout, outside the recorded exceptions',
    empty($unexpected),
    count($unexpected) . ' new site(s) — listed above');

// A fixed defect must not leave a stale exemption behind.
foreach ($ALLOW as $path => $reason) {
    $exists = is_file($root . '/' . $path);
    if (!$exists) {
        test("stale exception: $path no longer exists — remove it from \$ALLOW", false);
        continue;
    }
    test("known-unbounded exception is still real: $path",
        isset($knownHit[$path]),
        'this file now bounds all its outbound calls — delete its $ALLOW entry '
        . 'and its row in docs/OFFLINE-OPERATION.md');
}

// ─────────────────────────────────────────────────────────────────────
// The library timeout this project relies on but does not set.
//
// inc/channels/push.php constructs Minishlink\WebPush\WebPush without a
// timeout argument, so the library default is the ONLY bound on the push
// fan-out — which inc/audit.php runs synchronously inside the request that
// created the incident or changed the unit status. Measured 2026-07-31
// against black-holed endpoints: flush() blocked ~21s for 1, 5 and 20
// subscriptions alike (it is parallel, so the cost does not multiply). If an
// upgrade dropped that default, every dispatch action would hang instead.
// ─────────────────────────────────────────────────────────────────────
echo "\n-- The one timeout we inherit rather than set --\n";
$wp = $root . '/vendor/minishlink/web-push/src/WebPush.php';
if (!is_file($wp)) {
    echo "SKIP: vendor/minishlink/web-push is not installed (composer install has not run);\n";
    echo "      web push is disabled at runtime in that state, so there is nothing to bound.\n";
} else {
    $wpSrc = (string) file_get_contents($wp);
    $hasDefault = preg_match('/__construct\s*\([^)]*\$timeout\s*=\s*(\d+)/s', $wpSrc, $m);
    test('vendored WebPush still defaults $timeout to a bounded value',
        $hasDefault && (int) $m[1] > 0 && (int) $m[1] <= 60,
        $hasDefault
            ? "default is {$m[1]}s — over 60s means a dispatch action can hang that long"
            : 'no default $timeout found in the constructor — the push fan-out is now unbounded');

    test('the push channel still relies on that default rather than setting its own',
        strpos((string) @file_get_contents($root . '/inc/channels/push.php'), 'new Minishlink\\WebPush\\WebPush(') !== false,
        'push channel construction changed — re-check what bounds the fan-out');
}

// ─────────────────────────────────────────────────────────────────────
// The two request-path fetchers whose bounds matter most.
// ─────────────────────────────────────────────────────────────────────
echo "\n-- Request-path fetchers --\n";

$nws = (string) @file_get_contents($root . '/inc/weather_provider_nws.php');
test('the NWS alert fetch sets both a connect and a total timeout',
    $nws !== ''
    && strpos($nws, 'CURLOPT_CONNECTTIMEOUT') !== false
    && strpos($nws, 'CURLOPT_TIMEOUT') !== false,
    'inc/weather_provider_nws.php is polled on a timer and by an API endpoint');

$wx = (string) @file_get_contents($root . '/api/weather-proxy.php');
// Accepts a named constant as well as a literal. The original pattern demanded
// `'timeout' => 10`, so hoisting the value into WX_READ_TIMEOUT — which is what
// made it reviewable and testable — read to this gate as REMOVING the bound.
// A check that only recognises one spelling of a fix punishes the fix; the same
// blind spot is fixed in the command-line scanner above.
test('the OpenWeatherMap proxy bounds its upstream fetch',
    $wx !== ''
    && preg_match('/[\'"]timeout[\'"]\s*=>\s*(\d+|WX_READ_TIMEOUT)/', $wx) === 1
    && preg_match('/const WX_READ_TIMEOUT\s*=\s*\d+/', $wx) === 1,
    'api/weather-proxy.php serves map tiles — an unbounded fetch here is per-tile');

// Bounding ONE call is necessary and not sufficient: the hazard is the
// aggregate, ~40 tiles per viewport, repeated on every pan for the whole
// outage. D6 is only closed if the second pan is free.
test('and stops paying it once OpenWeatherMap is known to be down',
    $wx !== ''
    && strpos($wx, 'wx_breaker_check') !== false
    && strpos($wx, 'WX_FAIL_MAX_AGE') !== false,
    'api/weather-proxy.php needs a circuit breaker AND a negatively-cached failure reply, '
    . 'or a dead upstream is re-paid per tile, per pan (docs/OFFLINE-OPERATION.md D6)');

// The tile proxy is Phase 130 work and may not be present in every tree.
$tp = $root . '/inc/tile-proxy.php';
if (!is_file($tp)) {
    echo "note: inc/tile-proxy.php not present in this tree — basemap tiles are\n";
    echo "      fetched directly by the browser, so there is no server-side bound\n";
    echo "      to assert. See docs/OFFLINE-OPERATION.md.\n";
} else {
    $tpSrc = (string) file_get_contents($tp);
    test('the tile proxy defines an explicit connect timeout',
        preg_match('/TILE_PROXY_CONNECT_TIMEOUT\s*=\s*(\d+)/', $tpSrc, $m) === 1
        && (int) $m[1] > 0 && (int) $m[1] <= 15,
        'a tile fetch is paid ~40x per map viewport, so its bound must be small');
    test('the tile proxy defines an explicit read timeout',
        preg_match('/TILE_PROXY_READ_TIMEOUT\s*=\s*(\d+)/', $tpSrc, $m) === 1
        && (int) $m[1] > 0 && (int) $m[1] <= 30);
}

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
