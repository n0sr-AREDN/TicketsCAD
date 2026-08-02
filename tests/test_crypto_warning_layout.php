<?php
/**
 * The "encryption unavailable" banner must not depend on its parent's layout.
 *
 * GH TicketsCAD#12 (a beta tester): served over plain HTTP, the login card was pushed
 * to one side instead of centred. assets/js/field-encrypt.js injects its
 * warning banner as the first child of <body>, which is right in normal block
 * flow — the dashboard renders it exactly as intended. But login.php centres
 * its card by making <body> itself a flex row, and in a flex row "first child"
 * means the leftmost COLUMN, not the top. The banner became a flex sibling of
 * the card and consumed horizontal space in the same row.
 *
 * Measured in Chrome at a 1280px viewport, before and after, against the real
 * FieldEncrypt.init() path with crypto.subtle removed the way an insecure
 * context removes it:
 *
 *   before : banner left 0 width 860 · card left 860 width 420 (sum = 1280,
 *            so justify-content:center had no slack) · card centre 430px off
 *   after  : banner fixed, full width, out of flow · card centre 0px off
 *
 * Reach matters here: crypto.subtle is withheld outside a secure context, so
 * this fired on every install served over HTTP — which is essentially every
 * install during first-run setup, before a certificate is in place. It is also
 * the page where the warning matters most, since a password is about to be
 * posted.
 *
 * The fix is conditional on purpose. Taking the banner out of flow
 * unconditionally would make it overlay the navbar on the many pages where the
 * current in-flow insertion is correct. It asks the body what layout mode it is
 * in and only escapes the flow when it would otherwise be trapped in one — so
 * every ordinary page is byte-for-byte unchanged.
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

$js = $root . '/assets/js/field-encrypt.js';
if (!is_file($js)) {
    echo "SKIP: assets/js/field-encrypt.js not present\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}
$src = file_get_contents($js);

// ── 1. The banner still exists and still warns ──────────────────────────────
echo "-- The banner --\n";

test('showCryptoWarning() is still defined',
    strpos($src, 'function showCryptoWarning(') !== false);
test('the banner still carries its id', strpos($src, 'fe-crypto-warning') !== false);
test('the banner still says fields will go unencrypted',
    stripos($src, 'without encryption') !== false);
test('the banner is still announced to assistive tech',
    strpos($src, "setAttribute('role', 'alert')") !== false);

// ── 2. It asks the body what layout it is in ────────────────────────────────
echo "\n-- It accounts for the parent's layout mode --\n";

// Bound the search to the function so a getComputedStyle elsewhere in the file
// cannot stand in for the one that matters.
$fnStart = strpos($src, 'function showCryptoWarning(');
$fn = $fnStart === false ? '' : substr($src, $fnStart, 4000);

test('showCryptoWarning() consults the computed layout of <body>',
    strpos($fn, 'getComputedStyle') !== false
    && preg_match('/getComputedStyle\(\s*body\s*\)/', $fn) === 1);
test('it recognises a flex container', strpos($fn, "'flex'") !== false || strpos($fn, '"flex"') !== false);
test('it recognises a grid container too (same trap, same fix)',
    strpos($fn, "'grid'") !== false || strpos($fn, '"grid"') !== false);
test('in that case it takes itself out of flow',
    preg_match("/position\s*=\s*'fixed'/", $fn) === 1);
test('and spans the full width rather than sharing the row',
    preg_match("/left\s*=\s*'0'/", $fn) === 1 && preg_match("/right\s*=\s*'0'/", $fn) === 1);
test('it sits above page chrome',
    preg_match('/zIndex\s*=\s*.\d+/', $fn) === 1);

// ── 3. The other branch is untouched ────────────────────────────────────────
//
// Verified in Chrome as well as here: on a block-flow body the element gets NO
// inline style attribute at all, so nothing that renders correctly today
// changes. This assertion is what stops a later "simplification" from applying
// the fixed positioning unconditionally.
echo "\n-- Ordinary pages are unchanged --\n";

test('the positioning is conditional, not unconditional',
    preg_match('/if\s*\([^)]*indexOf\(\s*[\'"]flex[\'"]\s*\)/', $fn) === 1);
test('the in-flow insertion is still the default path',
    strpos($fn, 'insertBefore') !== false && strpos($fn, 'body.firstChild') !== false);

// A blunt but useful guard: the style writes must be INSIDE a conditional
// block, i.e. more deeply indented than the insertBefore that follows them.
$stylesAreGuarded = preg_match(
    '/indexOf\([\'"]flex[\'"]\).*?\{(?:(?!\}).)*?position\s*=\s*[\'"]fixed[\'"]/s',
    $fn
) === 1;
test('the fixed-position styles are inside the flex/grid branch', $stylesAreGuarded);

// ── 4. getComputedStyle is called defensively ───────────────────────────────
//
// This runs on the login page before anything else has had a chance to work.
// A throw here would take the whole encryption bootstrap with it, and the
// banner exists precisely because that bootstrap has already failed once.
echo "\n-- It cannot make things worse --\n";

test('the layout probe is wrapped so it cannot throw',
    preg_match('/try\s*\{(?:(?!\}).)*getComputedStyle/s', $fn) === 1);

// ── 5. login.php is still the shape the bug needed ──────────────────────────
//
// If login.php ever stops centring via a flex body, the branch above becomes
// dead code and someone should notice rather than delete the wrong half.
echo "\n-- The page that exposed it --\n";

$login = $root . '/login.php';
if (!is_file($login)) {
    echo "[SKIP] login.php not present\n";
} else {
    $lsrc = file_get_contents($login);
    test('login.php still centres its card with a flex body (the case fixed above)',
        preg_match('/body\s*\{[^}]*display\s*:\s*flex/s', $lsrc) === 1);
    test('login.php still has the card the banner was displacing',
        strpos($lsrc, 'login-card') !== false);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
