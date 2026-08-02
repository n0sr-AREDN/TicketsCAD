<?php
/**
 * Zello WSS close-code policy + reconnect-backoff regression tests.
 *
 * Locks in the fixes for GH openises/TicketsCAD#19, reported by @rjonesbsink
 * from a live consumer-network proxy:
 *
 *   BUG 1 — `reconnectAttempts` was reset in the connect-SUCCESS callback,
 *           i.e. the moment the TCP/TLS + WebSocket upgrade completed. But
 *           sendLogon() runs after that, and an auth rejection arrives after
 *           that. So the counter could never climb: every retry computed
 *           min(30, pow(2, 0)) = 1 second, maxReconnectAttempts was
 *           unreachable, and the loop was unbounded. Observed as 33 rejected
 *           logons in 6.5 minutes, every one logged "attempt 1", throttled
 *           only by the separate 3-connects-per-60s self-limiter — a safety
 *           net doing backoff's job. Affected ANY post-handshake failure,
 *           including mid-session 1006 drops.
 *           Fix: the reset moved to the `authenticated` transition in
 *           handleUpstreamMessage(), i.e. application-layer success.
 *
 *   BUG 2 — close code 3002 was documented as "server closing (maintenance)"
 *           and unhandled, so it fell through to exponential backoff. Zello
 *           actually sends it with the reason string "not authorized". It is
 *           an auth rejection. It cleared on its own after a 3003 kick (a
 *           standalone logon with the same key succeeded while the proxy was
 *           stopped), so it is transient — a fixed cool-off, NOT the fatalAuth
 *           latch 3001 gets, which would require a daemon restart.
 *
 *   Also 3004 "banned", which was falling through to exponential retry.
 *
 * These drive the REAL production methods — ZelloUpstream::closeCodePolicy()
 * and ::backoffDelay() are pure and public precisely so this file does not
 * have to re-implement the logic it is meant to be guarding. The two
 * structural checks at the end are the exception: the placement of the
 * counter reset cannot be observed without an event loop and a live upstream,
 * so it is asserted against the source region instead.
 *
 * Run: /c/xampp/8.2.4/php/php.exe tools/test_all.php   (or this file directly)
 */

require_once __DIR__ . '/../proxy/ZelloUpstream.php';

use NewUI\Proxy\ZelloUpstream;

echo "=== Zello close codes + reconnect backoff (GH #19) ===\n\n";
$pass = 0;
$fail = 0;

function zc_ok(string $label, bool $cond, string $detail = '') {
    global $pass, $fail;
    if ($cond) { echo "[PASS] {$label}\n"; $pass++; }
    else       { echo "[FAIL] {$label}" . ($detail ? " — {$detail}" : '') . "\n"; $fail++; }
}

// ──────────────────────────────────────────────────────────────────────
// BUG 2 — 3002 "not authorized"
// ──────────────────────────────────────────────────────────────────────
echo "-- 3002 not authorized --\n";
$p = ZelloUpstream::closeCodePolicy(3002);

zc_ok('3002 is handled, not left to the generic fallthrough',
    $p['action'] !== 'backoff', "action={$p['action']}");
zc_ok('3002 uses a FIXED cool-off, not exponential backoff',
    $p['action'] === 'fixed', "action={$p['action']}");
zc_ok('3002 does NOT latch fatalAuth (it is transient — must not need a restart)',
    $p['fatal'] === false);
zc_ok('3002 cool-off is long enough to let a busy account settle (>= 30s)',
    $p['delay'] >= 30, "delay={$p['delay']}");
zc_ok('3002 cool-off is not so long a dispatcher loses Zello for a shift (<= 300s)',
    $p['delay'] <= 300, "delay={$p['delay']}");
zc_ok('3002 tells the operator what happened, naming the code',
    strpos($p['detail'], '3002') !== false, "detail={$p['detail']}");
zc_ok('3002 status is one the widget knows how to render',
    in_array($p['status'], ['connecting','connected','authenticated','disconnected',
        'reconnecting','kicked','auth_failed','rate_limited','error','disabled','failed'], true),
    "status={$p['status']}");

// The cool-off is a parameter, so an install that wants a different one can
// pass it — assert it is actually honoured rather than ignored.
$pCustom = ZelloUpstream::closeCodePolicy(3002, 90);
zc_ok('3002 cool-off is configurable, not hardcoded',
    $pCustom['delay'] === 90, "delay={$pCustom['delay']}");
zc_ok('3002 detail reflects the configured cool-off',
    strpos($pCustom['detail'], '90') !== false, "detail={$pCustom['detail']}");

// ──────────────────────────────────────────────────────────────────────
// The other close codes still behave as they did
// ──────────────────────────────────────────────────────────────────────
echo "\n-- other close codes --\n";

$p3003 = ZelloUpstream::closeCodePolicy(3003);
zc_ok('3003 kicked still waits a fixed 30s', $p3003['action'] === 'fixed' && $p3003['delay'] === 30,
    "action={$p3003['action']} delay={$p3003['delay']}");
zc_ok('3003 kicked still resets the attempt counter (kicks are not our fault)',
    $p3003['reset_attempts'] === true);
zc_ok('3003 kicked is not fatal', $p3003['fatal'] === false);

$p3001 = ZelloUpstream::closeCodePolicy(3001);
zc_ok('3001 unable-to-verify is still fatal', $p3001['fatal'] === true && $p3001['action'] === 'fatal');
zc_ok('3001 does not schedule any reconnect', $p3001['delay'] === 0);
zc_ok('3001 tells the operator to check Settings',
    stripos($p3001['detail'], 'settings') !== false, "detail={$p3001['detail']}");

$p3004 = ZelloUpstream::closeCodePolicy(3004);
zc_ok('3004 banned is fatal, not exponential retry (retrying cannot help)',
    $p3004['fatal'] === true && $p3004['action'] === 'fatal', "action={$p3004['action']}");
zc_ok('3004 banned says "banned" rather than reusing the credentials wording',
    stripos($p3004['detail'], 'ban') !== false, "detail={$p3004['detail']}");
zc_ok('3004 and 3001 are distinguishable to an operator',
    $p3004['detail'] !== $p3001['detail']);

foreach ([1000, 1006, 3005, 0] as $transient) {
    $pt = ZelloUpstream::closeCodePolicy($transient);
    zc_ok("close {$transient} still uses exponential backoff",
        $pt['action'] === 'backoff' && $pt['fatal'] === false, "action={$pt['action']}");
    zc_ok("close {$transient} fires no extra status (the generic 'disconnected' covers it)",
        $pt['status'] === '', "status={$pt['status']}");
    zc_ok("close {$transient} does not reset the attempt counter",
        $pt['reset_attempts'] === false);
}

// Every policy must return the full contract — a missing key would be a
// notice at best and a wrong branch at worst.
echo "\n-- policy contract --\n";
$keys = ['action','delay','fatal','reset_attempts','status','detail','log'];
foreach ([1000, 1006, 3001, 3002, 3003, 3004, 3005, 0, 4999] as $c) {
    $pc = ZelloUpstream::closeCodePolicy($c);
    $missing = array_diff($keys, array_keys($pc));
    zc_ok("policy for {$c} returns the whole contract", empty($missing),
        'missing: ' . implode(',', $missing));
    zc_ok("policy for {$c} declares a known action",
        in_array($pc['action'], ['fatal','fixed','backoff'], true), "action={$pc['action']}");
}

// ──────────────────────────────────────────────────────────────────────
// BUG 1 — the backoff curve, and where the counter is reset
// ──────────────────────────────────────────────────────────────────────
echo "\n-- backoff curve --\n";

zc_ok('attempt 0 backs off 1s',   ZelloUpstream::backoffDelay(0) === 1);
zc_ok('attempt 1 backs off 2s',   ZelloUpstream::backoffDelay(1) === 2);
zc_ok('attempt 2 backs off 4s',   ZelloUpstream::backoffDelay(2) === 4);
zc_ok('attempt 3 backs off 8s',   ZelloUpstream::backoffDelay(3) === 8);
zc_ok('attempt 4 backs off 16s',  ZelloUpstream::backoffDelay(4) === 16);
zc_ok('the curve caps at 30s',    ZelloUpstream::backoffDelay(5) === 30
                               && ZelloUpstream::backoffDelay(99) === 30);
zc_ok('a negative attempt count cannot produce a fractional delay',
    ZelloUpstream::backoffDelay(-3) === 1);

// The whole point of BUG 1: the curve must actually escalate across repeated
// post-handshake failures. Drive the real formula the way scheduleReconnect()
// does — read the delay, then increment — and confirm the counter climbing
// produces a growing delay. Before the fix the counter was pinned at 0, so
// this sequence was 1,1,1,1,1 and the loop never slowed down.
$attempts = 0;
$delays   = [];
for ($i = 0; $i < 6; $i++) {
    $delays[] = ZelloUpstream::backoffDelay($attempts);
    $attempts++;
}
zc_ok('six consecutive failures escalate rather than pinning at 1s',
    $delays === [1, 2, 4, 8, 16, 30], 'got: ' . implode(',', $delays));
zc_ok('total wait over six failures exceeds one minute (the old loop spent 6s)',
    array_sum($delays) > 60, 'total=' . array_sum($delays) . 's');

// ──────────────────────────────────────────────────────────────────────
// Structural: where the counter reset lives.
// This cannot be observed without an event loop and a live upstream, so it is
// asserted against the source. If someone moves the reset back to the
// transport-connect callback, this fails.
// ──────────────────────────────────────────────────────────────────────
echo "\n-- reset placement --\n";

$src = file_get_contents(__DIR__ . '/../proxy/ZelloUpstream.php');
zc_ok('ZelloUpstream.php is readable', $src !== false);
$src = (string) $src;

// Region 1: the part of the connect-success callback that runs SYNCHRONOUSLY
// when the transport connects — from the callback opening to the first
// $conn->on() registration. Everything after that point is a deferred handler
// (message, close) that fires later, and the close handler legitimately
// resets the counter on a 3003 kick, so the region must stop short of it.
// The reset must NOT be in this synchronous block. That is the bug.
$cbStart = strpos($src, 'function (WebSocket $conn) {');
$cbEnd   = $cbStart === false ? false : strpos($src, "\$conn->on('message'", $cbStart);
zc_ok('found the transport-connect region', $cbStart !== false && $cbEnd !== false && $cbEnd > $cbStart);

if ($cbStart !== false && $cbEnd !== false && $cbEnd > $cbStart) {
    $connectRegion = substr($src, $cbStart, $cbEnd - $cbStart);
    // Strip comments so the explanatory note about the bug isn't a false hit.
    $connectCode = (string) preg_replace('~//[^\n]*~', '', $connectRegion);
    zc_ok('reconnectAttempts is NOT reset on transport connect (this is the bug)',
        !preg_match('~\$this->reconnectAttempts\s*=\s*0~', $connectCode));
    // Guard the guard: if the region ever collapses to nothing the assertion
    // above would pass vacuously.
    zc_ok('the transport-connect region is non-trivial (assertion is not vacuous)',
        strlen($connectCode) > 80 && strpos($connectCode, '$this->upstream = $conn') !== false,
        'len=' . strlen($connectCode));
}

// Region 2: the authenticated transition. The reset MUST be in here.
$authStart = strpos($src, 'if (!$this->authenticated) {');
zc_ok('found the authenticated-transition block', $authStart !== false);
if ($authStart !== false) {
    $authRegion = substr($src, $authStart, 1400);
    zc_ok('reconnectAttempts IS reset at the authenticated transition',
        preg_match('~\$this->reconnectAttempts\s*=\s*0~', $authRegion) === 1);
    zc_ok('the reset sits after authenticated is latched',
        strpos($authRegion, '$this->authenticated = true') !== false
        && strpos($authRegion, '$this->authenticated = true') < strpos($authRegion, '$this->reconnectAttempts = 0'));
}

// ──────────────────────────────────────────────────────────────────────
// The docs must no longer call 3002 "maintenance".
// ──────────────────────────────────────────────────────────────────────
echo "\n-- documentation --\n";
$docPath = __DIR__ . '/../docs/ZELLO-PROXY-LESSONS.md';
$doc = file_get_contents($docPath);
zc_ok('ZELLO-PROXY-LESSONS.md exists', $doc !== false);
$doc = (string) $doc;

zc_ok('3002 is no longer listed as "server closing (maintenance)"',
    !preg_match('~3002\s*[—-]\s*server closing~i', $doc));
zc_ok('3002 is documented with its real reason string',
    stripos($doc, 'not authorized') !== false);
zc_ok('the doc records that the backoff reset belongs at logon',
    stripos($doc, 'reset by LOGON') !== false || stripos($doc, 'reset the counter on the success') !== false);

echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
