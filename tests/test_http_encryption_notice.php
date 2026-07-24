<?php
/**
 * Phase 118 — "operating without HTTPS" per-admin acknowledge-weekly banner.
 *
 * Covers the pure staleness logic (no DB), a guarded per-user DB round-trip,
 * and the wiring that ties the module to the navbar / endpoint / diagnostics /
 * security banner / docs. Runs on the CI fresh-install (no Apache needed).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/http-encryption-notice.php';

$base = realpath(__DIR__ . '/..');
echo "=== Phase 118 — HTTP-encryption acknowledge-weekly ===\n\n";
$pass = 0; $fail = 0;
function ok($n)  { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad($n, $why = '') { global $fail; echo "[FAIL] $n" . ($why ? " — $why" : '') . "\n"; $fail++; }

// ── 1. Pure staleness logic (no DB) ─────────────────────────────
http_enc_is_stale(0) === true            ? ok('never-acked (0) is stale')            : bad('never-acked is stale');
http_enc_is_stale(time()) === false      ? ok('acked just now is fresh')             : bad('acked now is fresh');
http_enc_is_stale(time() - 6 * 86400) === false ? ok('acked 6 days ago still fresh') : bad('6 days ago fresh');
http_enc_is_stale(time() - 8 * 86400) === true  ? ok('acked 8 days ago is stale')    : bad('8 days ago stale');
http_enc_ttl_days() === 7                ? ok('TTL is 7 days')                       : bad('TTL is 7 days');

// ── 2. Per-user DB round-trip (guarded — skips on a virgin DB) ──
$uid = 990118;
try { prefs_reset($uid, HTTP_ENC_ACK_SCREEN); } catch (Throwable $e) {}
if (http_enc_ack_at($uid) === 0) {
    ok('fresh user has no acknowledgment (ack_at = 0)');
} else {
    bad('fresh user has no acknowledgment', 'got ' . http_enc_ack_at($uid));
}
$recorded = http_enc_record_ack($uid, '203.0.113.9');
if (!$recorded) {
    echo "SKIP: user_screen_prefs not writable on this DB — DB round-trip skipped (0/0)\n";
} else {
    $at = http_enc_ack_at($uid);
    (abs(time() - $at) < 120) ? ok('record_ack persists a ~now timestamp') : bad('record_ack persists timestamp', "got $at");
    http_enc_is_stale($at) === false ? ok('freshly-acked user is not stale') : bad('freshly-acked not stale');

    // Simulate an 8-day-old ack → should become stale again (per-admin weekly return)
    prefs_set($uid, HTTP_ENC_ACK_SCREEN, ['options' => ['acked_at' => time() - 8 * 86400, 'acked_ip' => 'x']]);
    http_enc_is_stale(http_enc_ack_at($uid)) === true ? ok('8-day-old ack returns to stale') : bad('8-day-old ack stale');

    prefs_reset($uid, HTTP_ENC_ACK_SCREEN); // cleanup
    http_enc_ack_at($uid) === 0 ? ok('cleanup removed the test ack') : bad('cleanup removed test ack');
}

// ── 3. Wiring assertions (source-level) ─────────────────────────
$navbar = @file_get_contents("$base/inc/navbar.php") ?: '';
(strpos($navbar, 'http_enc_should_prompt_admin') !== false
 && strpos($navbar, 'http_enc_ack_banner_html') !== false
 && strpos($navbar, "inc/http-encryption-notice.php") !== false)
    ? ok('navbar renders the ack banner via the module')
    : bad('navbar renders the ack banner', 'missing require/call');

$api = @file_get_contents("$base/api/http-encryption-ack.php") ?: '';
(strpos($api, 'csrf_verify') !== false
 && strpos($api, 'is_admin') !== false
 && strpos($api, 'http_enc_record_ack') !== false
 && strpos($api, "audit_log(") !== false
 && strpos($api, "'http_encryption'") !== false)
    ? ok('ack endpoint: CSRF + admin gate + record + audit')
    : bad('ack endpoint hardening', 'missing a guard');

$sec = @file_get_contents("$base/inc/security.php") ?: '';
(strpos($sec, "function_exists('is_admin') && is_admin()") !== false)
    ? ok('https_warning_banner self-suppresses for admins')
    : bad('https_warning_banner admin self-suppress', 'admin check missing');

$diag = @file_get_contents("$base/assets/js/diagnostics.js") ?: '';
(strpos($diag, 'Connection encrypted (HTTPS)') !== false)
    ? ok('diagnostics shows the connection-encryption row')
    : bad('diagnostics encryption row', 'not found');

$doc = @file_get_contents("$base/docs/HTTPS-SETUP.md") ?: '';
($doc !== '' && stripos($doc, 'X-Forwarded-Proto') !== false)
    ? ok('HTTPS-SETUP.md exists and covers the proxy header')
    : bad('HTTPS-SETUP.md', 'missing or lacks X-Forwarded-Proto note');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
