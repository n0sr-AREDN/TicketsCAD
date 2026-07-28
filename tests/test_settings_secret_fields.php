<?php
/**
 * Gate: every settings field the SERVER masks must be protected in the FORM.
 *
 * Reported as openises/TicketsCAD#7 by @rjonesbsink: saving Settings → API
 * Keys for any reason silently wiped `feed_api_key`, breaking the external
 * feed with no error.
 *
 * The mechanism is a mismatch between two halves that were each individually
 * correct:
 *
 *   - The SERVER masks secrets automatically and by SUFFIX
 *     (inc/settings-secrets.php). GET settings returns `<name>_set: bool`
 *     instead of the value, so the browser never receives a credential.
 *   - The FORM protects against blanking only where someone remembered to
 *     write data-secret="1". collectSettingsFromForm() skips a blank
 *     data-secret field ("blank means keep"); without the marker it submits
 *     the empty box and the upsert overwrites the real value.
 *
 * Automatic on one side, opt-in on the other, is a trap that re-arms itself
 * every time a `*_api_key` setting is added. Nine fields were already caught
 * by it. This test makes the two halves agree or fails the suite.
 *
 * It also guards the opposite error. The suffix backstop matched
 * `location_ingest_require_token` and `owntracks_require_token`, which are
 * BOOLEANS. Masking those is worse than blanking a key: the checkbox renders
 * unchecked whatever the stored state, and saving turns a "require token"
 * switch OFF — quietly removing authentication from an ingest endpoint.
 */

declare(strict_types=1);

$root = __DIR__ . '/..';
require_once $root . '/inc/settings-secrets.php';

$tests = 0; $fails = 0;
function tcheck(bool $cond, string $label): void {
    global $tests, $fails;
    $tests++;
    if (!$cond) { $fails++; echo "FAIL: $label\n"; }
}

$html = file_get_contents($root . '/settings.php');

// ── 1. No server-masked field may be left unprotected in the form ──
preg_match_all(
    '/<(?:input|textarea|select)\b[^>]*\bdata-key="([^"]+)"[^>]*>/i',
    $html, $matches, PREG_SET_ORDER
);

$unprotected = [];
$protected   = 0;
foreach ($matches as $tag) {
    if (!is_secret_setting_key($tag[1])) continue;
    if (stripos($tag[0], 'data-secret') !== false) { $protected++; continue; }
    $unprotected[] = $tag[1];
}

tcheck($unprotected === [],
    'every server-masked settings field carries data-secret="1"'
    . ($unprotected ? ' — missing on: ' . implode(', ', array_unique($unprotected)) : ''));
tcheck($protected > 0, 'the scan actually found protected secret fields (guards a broken regex)');

// ── 2. Boolean toggles must NOT be classified as secrets ───────────
// A masked boolean cannot round-trip: the UI never learns its real state.
foreach (['location_ingest_require_token', 'owntracks_require_token'] as $flag) {
    tcheck(!is_secret_setting_key($flag),
        "{$flag} is a boolean toggle, not a credential — masking it would let a save turn it off");
}

// ── 3. Real credentials must still be classified as secrets ────────
// Guards against someone "fixing" the above by gutting the backstop.
foreach (['feed_api_key', 'slack_token', 'smtp_pass', 'sms_twilio_token',
          'owm_api_key', 'zello_password'] as $secret) {
    tcheck(is_secret_setting_key($secret), "{$secret} is still treated as a secret");
}

// ── 4. Obvious non-secrets must stay unmasked ──────────────────────
foreach (['sms_twilio_sid', 'smtp_host', 'push_vapid_public'] as $plain) {
    tcheck(!is_secret_setting_key($plain), "{$plain} is not masked");
}

// ── 5. The client-side "blank means keep" guard must still exist ───
// If this is ever removed, data-secret markers stop protecting anything and
// test 1 would still pass while every credential blanks on save again.
$js = file_get_contents($root . '/assets/js/config.js');
tcheck(preg_match('/data-secret.{0,40}===\s*.1.[\s\S]{0,120}continue;/', $js) === 1,
    'collectSettingsFromForm() still skips blank data-secret fields');

echo "Settings secret-field gate: " . ($tests - $fails) . " passed, $fails failed\n";
exit($fails ? 1 : 0);
