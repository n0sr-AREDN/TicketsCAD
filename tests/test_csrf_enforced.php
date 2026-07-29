<?php
/**
 * CSRF enforcement gate.
 *
 * On 2026-07-28 five endpoints were found running with NO CSRF protection at
 * all. Each one read:
 *
 *     if (function_exists('csrf_check')) {  ... csrf_check($token) ...  }
 *
 * `csrf_check()` is defined NOWHERE in the codebase — the real helper is
 * `csrf_verify()`. So the guard was permanently false and the whole check was
 * skipped on every request. Nothing failed, nothing logged, no test noticed:
 * a `function_exists()` wrapper turns "this function is missing" from a loud
 * crash into a silently absent security control.
 *
 * The affected endpoints could send messages as the logged-in user, change DMR
 * talkgroup config, alter the APRS watchlist, and run push-admin actions.
 *
 * This test locks in three properties:
 *   1. Every endpoint that is supposed to check CSRF calls csrf_verify().
 *   2. No endpoint references the phantom csrf_check() as a call.
 *   3. No security control anywhere in api/ hides behind function_exists().
 *
 * Property 3 is the general lesson. Defensive `function_exists()` around an
 * OPTIONAL feature is fine; around a security control it is a silent disable.
 */

declare(strict_types=1);
chdir(dirname(__DIR__));

$pass = 0; $fail = 0;
function ok(string $what, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; }
    else { $fail++; echo "  FAIL: {$what}\n"; }
}

/* The endpoints whose CSRF gate was dead. Each must now call csrf_verify(). */
$guarded = [
    'api/aprs-license-accept.php',
    'api/aprs-watchlist.php',
    'api/messaging-send.php',
    'api/push-admin.php',
    'api/talkgroups.php',
];

foreach ($guarded as $f) {
    $src = file_get_contents($f);
    ok("{$f} exists",                     $src !== false);
    ok("{$f} calls csrf_verify()",        (bool) preg_match('/\bcsrf_verify\s*\(/', $src));
    // A comment may name csrf_check historically; a CALL to it must not exist.
    $callSites = preg_match_all('/(?<![\w>])csrf_check\s*\(/', preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $src));
    ok("{$f} has no csrf_check() call",   $callSites === 0);
}

/* EVERY mutating method branch must be gated, not just one of them.
 *
 * A file-level "does it mention csrf_verify" check is not enough. api/talkgroups.php
 * called the CSRF gate on its POST branch and NOT on its DELETE branch, which
 * called only the permission gate. A token-less DELETE therefore removed a row —
 * proven live on training before release. The file passed a file-level check the
 * whole time because the POST branch satisfied it.
 *
 * So: for each `if ($method === 'POST'|'PUT'|'PATCH'|'DELETE')` block, require a
 * CSRF call inside that block (directly, or via the file's own _*_csrf_gate()
 * wrapper).
 */
foreach ($guarded as $f) {
    $src = preg_replace('~//[^\n]*|/\*.*?\*/~s', '', file_get_contents($f));
    if (!preg_match_all('/if\s*\(\s*\$method\s*===\s*[\'"](POST|PUT|PATCH|DELETE)[\'"]\s*\)\s*\{/', $src, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        continue;   // endpoint does not branch on method; the file-level check covers it
    }
    foreach ($m as $hit) {
        $method = $hit[1][0];
        $start  = $hit[0][1] + strlen($hit[0][0]);
        // Walk to the matching close brace.
        $depth = 1; $i = $start; $n = strlen($src);
        while ($i < $n && $depth > 0) {
            if ($src[$i] === '{') { $depth++; }
            elseif ($src[$i] === '}') { $depth--; }
            $i++;
        }
        $block = substr($src, $start, $i - $start);
        $gated = preg_match('/csrf_verify\s*\(/', $block)
              || preg_match('/_\w*csrf\w*_gate\s*\(/', $block);
        ok(basename($f) . " {$method} branch is CSRF-gated", (bool) $gated);
    }
}

/* csrf_verify must actually exist, or every gate above fails closed at runtime. */
$fn = file_get_contents('inc/functions.php');
ok('csrf_verify() is defined in inc/functions.php',
   (bool) preg_match('/function\s+csrf_verify\s*\(/', $fn));
ok('csrf_verify() uses a timing-safe comparison',
   (bool) preg_match('/csrf_verify.*?hash_equals/s', $fn));

/* Property 3 — a security control may never sit behind a FAIL-OPEN
 * function_exists() wrapper.
 *
 * The distinction matters, and a blunt check cries wolf. Two shapes exist:
 *
 *   FAIL-OPEN  (the bug):   if (function_exists('csrf_check')) { ...check... }
 *                           function missing -> block skipped -> control absent
 *
 *   FAIL-CLOSED (correct):  function_exists('is_admin') && is_admin()
 *                           !function_exists('is_admin') || !is_admin()
 *                           function missing -> evaluates to "not permitted"
 *
 * Six endpoints legitimately use the fail-closed form for `is_admin()` (an
 * RBAC fallback for pre-RBAC installs). Those are fine and must not be
 * flagged, or the gate gets ignored. Only the positive block-wrapping form —
 * where absence silently skips a check — is a defect.
 */
$security = ['csrf_verify', 'csrf_check', 'admin_auth', 'require_login',
             'is_admin', 'rbac_require', 'has_permission'];
$failOpen = [];
foreach (glob('api/*.php') as $f) {
    $src = preg_replace('~//[^\n]*|/\*.*?\*/~s', '', file_get_contents($f));
    // Positive wrapper opening a block, with no `&& <fn>(` guard on the same line.
    if (!preg_match_all('/if\s*\(\s*function_exists\s*\(\s*[\'"](\w+)[\'"]\s*\)\s*\)\s*\{/', $src, $m, PREG_SET_ORDER)) {
        continue;
    }
    foreach ($m as $hit) {
        if (in_array($hit[1], $security, true)) {
            $failOpen[] = basename($f) . " -> if (function_exists('{$hit[1]}')) { … }";
        }
    }
}
ok('no security control behind a FAIL-OPEN function_exists() wrapper in api/: '
   . ($failOpen ? implode(', ', $failOpen) : 'none'), $failOpen === []);

printf("\n  test_csrf_enforced: %d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
