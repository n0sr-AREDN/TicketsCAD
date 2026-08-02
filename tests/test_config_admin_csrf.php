<?php
/**
 * Every Settings write must carry a CSRF token.
 *
 * GH TicketsCAD#15 (a beta tester): Settings -> Regions was read-only in practice.
 * Saving, adding and deleting a region all failed with "Invalid CSRF token",
 * because those two handlers called fetchJSON() directly instead of apiPost().
 * apiPost() is the wrapper that attaches csrf_token; fetchJSON() deliberately
 * does not. Regions was the ONLY config-admin.php section that bypassed it —
 * six others (facilities, settings, signals, statuses, types, users) go through
 * apiPost, so the section looked like every other one and behaved like none of
 * them.
 *
 * The server side was never at fault: the regions handler has working INSERT,
 * UPDATE and DELETE branches. It was one absent field in the request body, and
 * it failed identically for every operator on every platform.
 *
 * What makes this worth a gate rather than just a fix: the defect is invisible
 * to every other kind of test. The endpoint is correct, the SQL is correct, the
 * UI renders, and the failure only appears when a real browser posts a real
 * body. A static check of the call sites is the cheap thing that would have
 * caught it, so that is what this is.
 *
 * The invariant asserted here is deliberately narrow, and true by construction
 * rather than by taste: api/config-admin.php requires a token on every POST,
 * therefore every JS POST to it must carry one — via apiPost()/apiPostDirect(),
 * or by putting csrf_token in the payload itself (which the RBAC settings
 * handler does, legitimately). Anything else is the bug this issue reported.
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

$jsPath  = $root . '/assets/js/config.js';
$apiPath = $root . '/api/config-admin.php';

if (!is_file($jsPath) || !is_file($apiPath)) {
    echo "SKIP: assets/js/config.js or api/config-admin.php not present\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

$js  = file_get_contents($jsPath);
$api = file_get_contents($apiPath);

// ── 1. The premise: the endpoint really does require a token ────────────────
//
// If this ever stopped being true the rest of the file would be asserting a
// rule nothing enforces, so check it rather than assume it.
echo "-- The endpoint enforces CSRF --\n";

test('api/config-admin.php calls csrf_verify()',
    strpos($api, 'csrf_verify') !== false);
test('api/config-admin.php reads the token from the body or the query string',
    preg_match("/csrf_token'\]\s*\?\?\s*\\\$_GET\['csrf_token'\]/", $api) === 1
    || (strpos($api, "\$input['csrf_token']") !== false && strpos($api, "\$_GET['csrf_token']") !== false));
test('api/config-admin.php rejects with 403',
    preg_match('/csrf[^\n]*\n?[^\n]*403/i', $api) === 1 || strpos($api, "'Invalid CSRF token', 403") !== false);

// ── 2. apiPost() is the wrapper that attaches it ────────────────────────────
echo "\n-- apiPost() attaches the token --\n";

test('config.js defines apiPost()', strpos($js, 'function apiPost(') !== false);
test('apiPost() assigns csrf_token onto the body',
    preg_match('/function apiPost\([^)]*\)\s*\{[^}]*csrf_token\s*=/s', $js) === 1);
test('fetchJSON() does NOT attach a token (that is apiPost\'s job)',
    preg_match('/function fetchJSON\([^)]*\)\s*\{(?:(?!function ).)*?csrf_token/s', $js) !== 1);

// ── 3. No POST to config-admin.php may go out without one ───────────────────
//
// Walk every literal reference to the endpoint, take the surrounding window,
// and require both that it is a POST and that a token is reachable. Reads are
// exempt: GET carries no CSRF risk and the endpoint does not check one.
echo "\n-- Every config-admin.php write carries a token --\n";

$lines    = preg_split('/\r?\n/', $js);
$offences = [];
$writes   = 0;

foreach ($lines as $i => $line) {
    // Skip comment lines: several explain this very rule.
    $trimmed = ltrim($line);
    if (strpos($trimmed, '//') === 0 || strpos($trimmed, '*') === 0) {
        continue;
    }
    if (strpos($line, 'config-admin.php') === false) {
        continue;
    }

    // Whether THIS call is a write is decided by its own options object, which
    // opens on the same line as the URL. Look forward only, and not far — a
    // symmetric window picks up the next handler down the file and reports a
    // GET as a write, which is how a gate earns its reputation for lying.
    $callWin = implode("\n", array_slice($lines, $i, 8));
    if (!preg_match('/method\s*:\s*[\'"]POST[\'"]/i', $callWin)) {
        continue;
    }
    $writes++;

    // The token, by contrast, is legitimately assembled well above the call, so
    // look backward generously as well as forward.
    $from = max(0, $i - 30);
    $win  = implode("\n", array_slice($lines, $from, 30 + 12));

    $hasToken = strpos($win, 'csrf_token') !== false
             || strpos($win, 'csrfToken')  !== false
             || strpos($win, 'X-CSRF-Token') !== false;

    if (!$hasToken) {
        $offences[] = 'config.js:' . ($i + 1) . '  ' . trim($line);
    }
}

test('config.js has at least one config-admin.php write to check',
    $writes > 0, 'found none — has the file moved?');
test('no config-admin.php POST is missing a CSRF token',
    $offences === [],
    "\n        " . implode("\n        ", $offences));

// ── 4. Regions specifically — the section the issue reported ────────────────
echo "\n-- Regions writes go through apiPost() --\n";

// The two handlers are inside loadRegions(); pull that function body out rather
// than searching the whole file, so a token elsewhere cannot mask a miss here.
$regionsBody = '';
$startPos = strpos($js, 'function loadRegions(');
if ($startPos !== false) {
    // Bounded slice — long enough to cover the add/edit/delete handlers, which
    // sit ~7.9k and ~10.2k characters in.
    $regionsBody = substr($js, $startPos, 12000);
}

test('loadRegions() exists', $regionsBody !== '');

if ($regionsBody !== '') {
    test('regions save/add goes through apiPost()',
        preg_match("/apiPost\(\s*'regions'/", $regionsBody) === 1);
    test('regions delete goes through apiPost()',
        preg_match_all("/apiPost\(\s*'regions'/", $regionsBody) >= 2,
        'expected at least 2 apiPost(\'regions\') calls (save and delete)');
    test('regions no longer POSTs to config-admin.php with a raw fetchJSON()',
        preg_match("/fetchJSON\(\s*'api\/config-admin\.php\?section=regions'/", $regionsBody) !== 1,
        'a raw fetchJSON POST to ?section=regions is back — it will 403');
}

// ── 5. The server handler was never the problem — confirm it is still whole ─
//
// The issue was careful to say the regions handler is complete and correct, and
// that the fix is client-side only. Assert that, so a future "fix" that starts
// loosening the server side has something arguing against it.
echo "\n-- The server-side handler is intact and still guarded --\n";

$regionsSection = '';
if (preg_match("/case 'regions'.*?(?=\n\s{4,8}case '|\n\s*\}\s*\n\s*\/\/)/s", $api, $m)) {
    $regionsSection = $m[0];
}
if ($regionsSection === '') {
    // Fall back to a plain window around the first mention.
    $p = strpos($api, "'regions'");
    if ($p !== false) $regionsSection = substr($api, $p, 6000);
}

test('a regions handler is present server-side', $regionsSection !== '');
if ($regionsSection !== '') {
    test('regions handler still has both save branches (INSERT and UPDATE)',
        stripos($regionsSection, 'INSERT INTO') !== false
        && stripos($regionsSection, 'UPDATE ') !== false);
    test('regions handler still has a delete branch',
        stripos($regionsSection, 'DELETE FROM') !== false);
}
test('no CSRF exemption was added for regions',
    preg_match("/regions[^\n]{0,80}(skip|bypass|exempt)[^\n]{0,40}csrf/i", $api) !== 1
    && preg_match("/csrf[^\n]{0,60}(skip|bypass|exempt)[^\n]{0,40}regions/i", $api) !== 1);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
