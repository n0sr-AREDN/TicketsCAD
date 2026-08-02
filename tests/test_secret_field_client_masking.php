<?php
/**
 * Gate: the browser must never blank a stored credential, and must never be
 * handed one.
 *
 * openises/TicketsCAD#7 (@rjonesbsink) reported that saving Settings → API
 * Keys silently wiped feed_api_key. PR #9 fixes the DISPLAY half of that bug
 * (the field now shows "stored" instead of looking unconfigured) and PR #10
 * fixes the DATA-LOSS half for telegram_bot_token. This gate makes sure both
 * halves stay fixed — and catches the three panels where the data-loss half is
 * still live.
 *
 * ── THE MECHANISM, AND WHY IT KEEPS RE-ARMING ────────────────────────
 *
 * The server masks secrets AUTOMATICALLY and BY SUFFIX
 * (inc/settings-secrets.php): GET settings returns `<name>_set: bool` instead
 * of the value, so a credential never reaches the browser. That half is sound.
 *
 * The protection against WRITING a blank back is OPT-IN and lives entirely on
 * the client, in collectSettingsFromForm() (assets/js/config.js):
 *
 *     if (input.getAttribute('data-secret') === '1' && input.value === '') continue;
 *
 * There is NO server-side equivalent. api/config-admin.php's POST settings
 * loop upserts whatever it is handed:
 *
 *     INSERT INTO settings (name, value) VALUES (?, ?)
 *       ON DUPLICATE KEY UPDATE value = VALUES(value)
 *
 * So any save handler that BYPASSES collectSettingsFromForm() and hand-builds
 * its `pairs` object posts the empty box straight over the stored secret. The
 * field looked empty because the server correctly refused to send the value.
 *
 * tests/test_settings_secret_fields.php already gates the markup side (every
 * masked field carries data-secret="1"). It cannot see this failure mode,
 * because these panels address their inputs by element id from hand-written
 * JS and never appear in a data-key scan. That blind spot is why three more
 * instances survived the #7 fix.
 *
 * ── WHAT BREAKS WHEN THIS FIRES ──────────────────────────────────────
 *
 * No attacker is required; an operator does it to themselves. Open Settings →
 * OwnTracks Auth, toggle "Allow anonymous", click Save: owntracks_secret is
 * overwritten with ''. api/location.php then fails CLOSED (403,
 * X-OwnTracks-Reason: no-auth) — which is the right call security-wise and
 * means every responder's device silently stops reporting position. Units
 * freeze on the dispatch map at their last known location, with no error
 * anywhere in the UI. Same shape for location_ingest_secret (401) and
 * aprs_fi_api_key (APRS polling stops).
 *
 * That is an availability failure, not an authentication bypass — both ingest
 * paths reject an empty secret rather than falling open. On a system that
 * dispatches emergency responders it is quite bad enough.
 *
 * ── THE FIX THIS TEST IS ASKING FOR ──────────────────────────────────
 *
 *   1. Convert the hand-built save handlers to collectSettingsFromForm(form),
 *      which is exactly what PR #10 does for Telegram.
 *   2. Add the server-side backstop the client has been carrying alone, in
 *      api/config-admin.php's POST settings loop:
 *
 *          if ($value === '' && is_secret_setting_key($key)) continue;
 *
 *      This is the important half: it makes the invariant true regardless of
 *      which of the ~40 JS save handlers is doing the posting.
 */

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
require_once $root . '/inc/settings-secrets.php';

$tests = 0;
$fails = 0;

function sec(bool $cond, string $label): void
{
    global $tests, $fails;
    $tests++;
    if (!$cond) {
        $fails++;
        echo "FAIL: $label\n";
    }
}

$jsPath = $root . '/assets/js/config.js';
if (!is_file($jsPath)) {
    echo "SKIP: assets/js/config.js not present on this tree (0/0)\n";
    echo "Secret-field client masking gate: 0 passed, 0 failed\n";
    exit(0);
}
$js = (string) file_get_contents($jsPath);

/**
 * Blank out // line comments and /* … *​/ blocks, replacing every character
 * with a space but KEEPING newlines. A comment mentioning a settings key must
 * not trip an assertion — and byte offsets must stay identical to the real
 * file, or every line number this test reports would be a lie.
 */
function js_blank_comments(string $src): string
{
    return (string) preg_replace_callback(
        '#/\*.*?\*/|//[^\n]*#s',
        static fn(array $m): string => preg_replace('/[^\n]/', ' ', $m[0]),
        $src
    );
}
$jsCode = js_blank_comments($js);

/**
 * Variable names this file uses for the settings map returned by
 * `GET api/config-admin.php?section=settings`. Restricting rule 1 to these
 * keeps it from flagging one-time API responses that legitimately carry a
 * secret — a freshly minted ingest token (`data.raw_token`) or a TOTP secret
 * during enrolment must be shown to the admin exactly once, and neither is a
 * settings key.
 */
$settingsMapVars = ['s', 'settings', 'cfg', 'conf'];

// ── 1. No masked settings VALUE is read into a form field ────────────
// Harmless today (the server sends undefined, so the assignment is a no-op),
// but it is the tell that a save handler nearby believes it has the value —
// and it would become a real leak the instant the masking layer regressed.
$valueReads = [];
if (preg_match_all(
        '/\.value\s*=\s*([A-Za-z_$][A-Za-z0-9_$]*)\.([a-z0-9_]+)\b/',
        $jsCode, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
    foreach ($m as $hit) {
        [$obj, $key] = [$hit[1][0], $hit[2][0]];
        if (!in_array($obj, $settingsMapVars, true)) continue;
        if (!is_secret_setting_key($key)) continue;
        $line = substr_count(substr($jsCode, 0, $hit[0][1]), "\n") + 1;
        $valueReads[$key] = "assets/js/config.js:{$line}";
    }
}
$readDetail = '';
foreach ($valueReads as $k => $where) {
    $readDetail .= "\n        {$k} — {$where}";
}
sec($valueReads === [],
    'no server-masked settings value is assigned into a form field' . $readDetail);

// ── 2. No hand-built settings POST includes a masked key ─────────────
// The real defect. Two shapes are used in this file:
//     var pairs = { some_key: el.value, ... };
//     pairs.some_key = el.value;
// Both bypass collectSettingsFromForm()'s "blank means keep" guard.
$postedSecrets = [];

// Shape A — object literal assigned to a `pairs` variable.
$offset = 0;
while (($p = strpos($jsCode, 'pairs = {', $offset)) !== false) {
    $open = strpos($jsCode, '{', $p);
    $depth = 0;
    $end = $open;
    for ($i = $open, $len = strlen($jsCode); $i < $len; $i++) {
        if ($jsCode[$i] === '{') $depth++;
        elseif ($jsCode[$i] === '}') { $depth--; if ($depth === 0) { $end = $i; break; } }
    }
    $block = substr($jsCode, $open, $end - $open + 1);
    if (preg_match_all('/(?:^|[{,\s])([a-z][a-z0-9_]*)\s*:/mi', $block, $km)) {
        foreach ($km[1] as $key) {
            if (is_secret_setting_key($key)) {
                $line = substr_count(substr($jsCode, 0, $open), "\n") + 1;
                $postedSecrets[$key] = "assets/js/config.js:~{$line} (object literal)";
            }
        }
    }
    $offset = $end + 1;
}

// Shape B — pairs.<key> = …
if (preg_match_all('/\bpairs\.([a-z0-9_]+)\s*=/i', $jsCode, $am, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
    foreach ($am as $hit) {
        $key = $hit[1][0];
        if (is_secret_setting_key($key)) {
            $line = substr_count(substr($jsCode, 0, $hit[0][1]), "\n") + 1;
            $postedSecrets[$key] = "assets/js/config.js:{$line} (property assignment)";
        }
    }
}

$detail = '';
foreach ($postedSecrets as $k => $where) {
    $detail .= "\n        {$k} — {$where}";
}
sec($postedSecrets === [],
    'no settings save handler posts a server-masked key from a hand-built object '
    . '(use collectSettingsFromForm(form), which omits a blank secret so the stored '
    . 'value survives)' . $detail);

// ── 3. The server-side backstop exists ───────────────────────────────
// The single change that makes this class of bug impossible regardless of
// which JS handler is posting. Its absence is why the bug recurs.
$adminPath = $root . '/api/config-admin.php';
if (!is_file($adminPath)) {
    sec(false, 'api/config-admin.php exists');
} else {
    $admin = (string) file_get_contents($adminPath);
    $admin = (string) preg_replace('#/\*.*?\*/#s', '', $admin);
    $admin = (string) preg_replace('#^\s*//.*$#m', '', $admin);
    sec(preg_match('/is_secret_setting_key\s*\(\s*\$key\s*\)/', $admin) === 1
        && preg_match('/\$value\s*===\s*\x27\x27/', $admin) === 1,
        'api/config-admin.php POST settings refuses to overwrite a stored secret with an '
        . 'empty string (add `if ($value === \'\' && is_secret_setting_key($key)) continue;` '
        . 'to the upsert loop — the client-side guard is opt-in and has been missed four times)');
}

// ── 4. The `_set` sentinel is actually consumed by the UI ────────────
// PR #9's contribution: a masked field must render "stored" rather than
// looking unconfigured, or an admin re-types a credential they already have —
// or worse, concludes the feature is broken and disables it.
sec(strpos($jsCode, 'feed_api_key_set') !== false,
    'config.js reads the feed_api_key_set sentinel so a configured key renders as '
    . '"stored" instead of an empty box (openises/TicketsCAD PR #9)');

// Telegram's equivalent arrives with PR #10; only assert it once that panel
// has been converted to the data-key pattern.
if (strpos($jsCode, "data-key=\"telegram_bot_token\"") !== false
    || strpos($js, 'telegram_bot_token_set') !== false) {
    sec(strpos($jsCode, 'telegram_bot_token_set') !== false,
        'config.js reads the telegram_bot_token_set sentinel (openises/TicketsCAD PR #10)');
}

// ── 5. The client-side guard itself is still in place ────────────────
// If collectSettingsFromForm() stops skipping blank data-secret fields, every
// data-secret marker in settings.php silently stops protecting anything.
sec(preg_match('/data-secret.{0,60}===\s*.1.[\s\S]{0,160}continue;/', $jsCode) === 1,
    'collectSettingsFromForm() still skips blank data-secret fields');

echo "Secret-field client masking gate: " . ($tests - $fails) . " passed, $fails failed\n";
exit($fails ? 1 : 0);
