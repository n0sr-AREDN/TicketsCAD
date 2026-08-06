<?php
/**
 * Every personnel-config.php and vehicles.php write must carry a CSRF token.
 *
 * Issue #20 (public repo, security — split from #15): neither endpoint called
 * csrf_verify() anywhere, despite both performing state-changing writes
 * (personnel-config.php: 4 INSERTs, 3 DELETEs; vehicles.php: 2 INSERTs,
 * 2 DELETEs). Both are authenticated (personnel-config.php additionally
 * requires admin), so this was never an unauthenticated write surface — it
 * was specifically the CSRF layer that was absent, with only a browser
 * default (SameSite=Lax cookies) standing in the gap. The reporter counted
 * 11 client call sites across assets/js/config.js and assets/js/vehicles.js,
 * all calling fetchJSON()/fetch() directly rather than through a wrapper
 * that attaches a token — the same shape of bug as #15 (test_config_admin_
 * csrf.php), which is the template this file follows.
 *
 * The reporter's own suggested sequencing ("client first, server second...
 * reversing that order means any missed call site breaks immediately for
 * users") is why the fix touched both sides together in one change rather
 * than landing the server gate first: config.js's 10 sites now go through
 * the existing apiPostDirect() wrapper (already used elsewhere in the file
 * for other standalone endpoints — no new wrapper needed), and vehicles.js's
 * single local apiPost() helper — the one chokepoint every vehicle write in
 * that file already funnels through — now attaches csrf_token itself.
 *
 * This file asserts the same invariant test_config_admin_csrf.php does, in
 * the same shape, for both endpoints: the endpoint genuinely requires a
 * token (the premise, checked rather than assumed), no write in the JS
 * reaches either endpoint without one, and the server-side handlers are
 * otherwise untouched (no exemption, no branch was removed to make this
 * pass).
 */

$root = dirname(__DIR__);

$pass = 0;
$fail = 0;

function test(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "[PASS] $name\n";
    } else {
        $fail++;
        echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

$configJsPath   = $root . '/assets/js/config.js';
$vehiclesJsPath = $root . '/assets/js/vehicles.js';
$personnelApi   = $root . '/api/personnel-config.php';
$vehiclesApi    = $root . '/api/vehicles.php';

foreach ([$configJsPath, $vehiclesJsPath, $personnelApi, $vehiclesApi] as $p) {
    if (!is_file($p)) {
        echo "SKIP: " . basename($p) . " not present\n";
        echo "\n=== 0 passed, 0 failed ===\n";
        exit(0);
    }
}

$configJs   = file_get_contents($configJsPath);
$vehiclesJs = file_get_contents($vehiclesJsPath);
$personnel  = file_get_contents($personnelApi);
$vehicles   = file_get_contents($vehiclesApi);

// ── 1. The premise: both endpoints really do require a token ────────────────
echo "-- Both endpoints enforce CSRF --\n";

foreach (['api/personnel-config.php' => $personnel, 'api/vehicles.php' => $vehicles] as $name => $src) {
    test("$name calls csrf_verify()", strpos($src, 'csrf_verify') !== false);
    test("$name reads the token from the body or the query string",
        preg_match("/csrf_token'\]\s*\?\?\s*\\\$_GET\['csrf_token'\]/", $src) === 1);
    test("$name rejects with 403", strpos($src, "'Invalid CSRF token', 403") !== false);
}

// ── 2. config.js's 10 sites go through apiPostDirect() ──────────────────────
//
// Same walk-every-write-and-require-a-reachable-token method as
// test_config_admin_csrf.php uses for config-admin.php, applied to both
// endpoint names. apiPostDirect() itself is proven to attach the token in
// section 4 below, so a call THROUGH it is sufficient — no need for
// csrf_token to appear literally at each of these call sites.
echo "\n-- Every personnel-config.php / vehicles.php write in config.js carries a token --\n";

$lines    = preg_split('/\r?\n/', $configJs);
$offences = [];
$writes   = 0;

foreach ($lines as $i => $line) {
    $trimmed = ltrim($line);
    if (strpos($trimmed, '//') === 0 || strpos($trimmed, '*') === 0) {
        continue;
    }
    $targetsPersonnel = strpos($line, "'personnel-config'") !== false || strpos($line, 'personnel-config.php') !== false;
    $targetsVehicles   = strpos($line, "'vehicles'") !== false || strpos($line, 'vehicles.php') !== false;
    if (!$targetsPersonnel && !$targetsVehicles) {
        continue;
    }

    // A call THROUGH apiPostDirect(...) is a write by construction (it is
    // always POST) and always carries the token — count it and move on.
    if (preg_match("/apiPostDirect\(\s*'(personnel-config|vehicles)'/", $line)) {
        $writes++;
        continue;
    }

    // Otherwise: only a literal fetchJSON/fetch call with method:'POST' is a
    // write we need to check (reads are exempt — GET carries no CSRF risk).
    $callWin = implode("\n", array_slice($lines, $i, 8));
    if (!preg_match('/method\s*:\s*[\'"]POST[\'"]/i', $callWin)) {
        continue;
    }
    $writes++;

    $from = max(0, $i - 30);
    $win  = implode("\n", array_slice($lines, $from, 30 + 12));
    $hasToken = strpos($win, 'csrf_token') !== false || strpos($win, 'csrfToken') !== false;

    if (!$hasToken) {
        $offences[] = 'config.js:' . ($i + 1) . '  ' . trim($line);
    }
}

test('config.js has at least one personnel-config.php/vehicles.php write to check',
    $writes > 0, 'found none — have the call sites moved?');
test('no personnel-config.php/vehicles.php POST in config.js is missing a CSRF token',
    $offences === [], "\n        " . implode("\n        ", $offences));

// ── 3. No RAW fetchJSON/fetch POST to either endpoint remains ───────────────
//
// The fix's whole point was routing every write through a wrapper that
// attaches the token. A raw fetchJSON(...) POST creeping back in (a future
// edit, a merge) would 403 in production even though this file's own
// token-reachability check above might still pass by coincidence (a
// csrf_token could appear nearby without actually being sent) — so also
// assert the literal fetchJSON call shape is gone for both endpoints.
echo "\n-- No raw fetchJSON POST to either endpoint remains in config.js --\n";

test('no raw fetchJSON(\'api/personnel-config.php\', {...POST...}) call remains',
    preg_match("/fetchJSON\(\s*'api\/personnel-config\.php'\s*,/", $configJs) !== 1,
    'a raw fetchJSON POST to personnel-config.php is back — it will 403');
test('no raw fetchJSON(\'api/vehicles.php\', {...POST...}) call remains',
    preg_match("/fetchJSON\(\s*'api\/vehicles\.php'\s*,/", $configJs) !== 1,
    'a raw fetchJSON POST to vehicles.php is back — it will 403');

// ── 4. apiPostDirect() really does attach the token ──────────────────────────
echo "\n-- apiPostDirect() attaches the token (the wrapper section 2 relies on) --\n";

test('config.js defines apiPostDirect()', strpos($configJs, 'function apiPostDirect(') !== false);
test('apiPostDirect() assigns csrf_token onto the body',
    preg_match('/function apiPostDirect\([^)]*\)\s*\{[^}]*csrf_token\s*=/s', $configJs) === 1);

// ── 5. vehicles.js's single local apiPost() wrapper attaches the token ──────
echo "\n-- vehicles.js's apiPost() wrapper attaches the token --\n";

test('vehicles.js defines apiPost()', strpos($vehiclesJs, 'function apiPost(') !== false);
test('apiPost() assigns csrf_token onto the body before the fetch',
    preg_match('/function apiPost\([^)]*\)\s*\{\s*data\.csrf_token\s*=/s', $vehiclesJs) === 1);

// Every caller of vehicles.js's apiPost() is therefore covered by
// construction — confirm there is at least one, and that none of them
// bypasses it with a raw fetch('api/vehicles.php', ...) of their own.
$apiPostCallers = preg_match_all('/\bapiPost\s*\(/', $vehiclesJs);
test('vehicles.js has at least one apiPost() call site', $apiPostCallers >= 1,
    'found none — has the write path moved?');

$rawFetchCount = preg_match_all("/fetch\(\s*'api\/vehicles\.php'/", $vehiclesJs);
test('vehicles.js has exactly ONE raw fetch(\'api/vehicles.php\') — inside apiPost() itself',
    $rawFetchCount === 1,
    "found $rawFetchCount — a second raw fetch would bypass the token");

// ── 6. The server-side handlers are intact — the issue was careful to say
//    both are complete and correct, and the fix is CSRF-only. ─────────────
echo "\n-- The server-side handlers are otherwise intact --\n";

test('personnel-config.php still has all 6 POST actions',
    strpos($personnel, "case 'save_certification'") !== false
    && strpos($personnel, "case 'delete_certification'") !== false
    && strpos($personnel, "case 'save_member_type'") !== false
    && strpos($personnel, "case 'delete_member_type'") !== false
    && strpos($personnel, "case 'save_member_status'") !== false
    && strpos($personnel, "case 'delete_member_status'") !== false);
test('personnel-config.php still requires admin (is_admin())',
    strpos($personnel, 'is_admin()') !== false);

test('vehicles.php still has its delete/save_type/delete_type/save branches',
    strpos($vehicles, "'delete'") !== false
    && strpos($vehicles, "'save_type'") !== false
    && strpos($vehicles, "'delete_type'") !== false
    && strpos($vehicles, 'INSERT INTO') !== false
    && strpos($vehicles, 'UPDATE') !== false);

test('no CSRF exemption was added for either endpoint',
    preg_match('/(skip|bypass|exempt)[^\n]{0,40}csrf/i', $personnel) !== 1
    && preg_match('/(skip|bypass|exempt)[^\n]{0,40}csrf/i', $vehicles) !== 1);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
