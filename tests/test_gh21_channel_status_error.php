<?php
/**
 * Issue #21 (public repo, Ron 2026-08-02): a password-protected Zello
 * Consumer channel is refused by the Channels API outright — even for the
 * channel owner — and Zello says so on the very first on_channel_status
 * frame: error="invalid password", error_type="configuration". The proxy's
 * handler read only `channel` and `status` and discarded `error` and
 * `error_type`, so the operator saw "Channel 'X' is offline" — true,
 * unactionable, and indistinguishable from an empty channel, a bad key, or
 * a wrong channel name. Logon still succeeded, PTT still lit up, start_stream
 * went out and Zello silently never answered it.
 *
 * Eric's comment on the issue (2026-08-03): "The code half needs no
 * reproduction and is not waiting on that. Whatever error Zello sends on
 * on_channel_status, showing it beats discarding it, on any tier." — so
 * this test covers only the code half (proxy/ZelloUpstream.php), not the
 * doc corrections, which are a separate non-code follow-up.
 *
 * ZelloUpstream::handleUpstreamMessage() is pure JSON-in, callback-out —
 * it never touches the network for an on_channel_status frame (that branch
 * is reached only after the `success` check, which this frame doesn't
 * carry). So unlike connectUpstream()/connect() (see test_zello_startup_
 * connect.php's docblock for the ~2-minute Windows hang those cause), it's
 * safe to invoke directly via Reflection with a synthetic frame — no live
 * WebSocket, no risk of hanging the test process.
 *
 * Run: /c/xampp/8.2.4/php/php.exe tools/test_all.php   (or this file directly)
 */

require __DIR__ . '/../config.php';

if (!function_exists('plog')) {
    function plog($msg) { /* silent in tests */ }
}

// Ratchet/React live in composer's vendor/ — not present on a fresh
// checkout or CI unless `composer install` ran. Skip cleanly instead of
// fataling, matching test_zello_startup_connect.php's convention.
if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
    echo "SKIP: vendor/autoload.php not found (run 'composer install' to enable Zello proxy tests)\n";
    echo "=== 0 passed, 0 failed ===\n";
    exit(0);
}
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../proxy/ZelloUpstream.php';

use NewUI\Proxy\ZelloUpstream;

echo "=== GH #21: on_channel_status must surface error/error_type, not just channel+status ===\n\n";
$pass = 0;
$fail = 0;

function t_ok($label, $cond, $hint = '') {
    global $pass, $fail;
    if ($cond) { echo "  [PASS] $label\n"; $pass++; }
    else       { echo "  [FAIL] $label" . ($hint !== '' ? " — $hint" : '') . "\n"; $fail++; }
}

$loop = \React\EventLoop\Loop::get();

$statusEvents = [];
$onStatus  = function ($type, $detail) use (&$statusEvents) { $statusEvents[] = [$type, $detail]; };
$onMessage = function ($data) { /* not exercised by this test */ };

$upstream = new ZelloUpstream($loop, ['zello_dispatch_channel' => 'TicketsCad-CleveOps'], $onMessage, $onStatus);

$ref    = new ReflectionMethod(ZelloUpstream::class, 'handleUpstreamMessage');
$ref->setAccessible(true);

function fireFrame(ReflectionMethod $ref, ZelloUpstream $upstream, array $data): void {
    $ref->invoke($upstream, json_encode($data));
}

function lastChannelStatus(array $events): ?array {
    for ($i = count($events) - 1; $i >= 0; $i--) {
        if ($events[$i][0] === 'channel_status') { return $events[$i]; }
    }
    return null;
}

// ── 1. The issue's exact repro frame: password-protected channel ──
$statusEvents = [];
fireFrame($ref, $upstream, [
    'command'             => 'on_channel_status',
    'channel'             => 'TicketsCad-CleveOps',
    'status'              => 'offline',
    'users_online'        => 0,
    'images_supported'    => false,
    'texting_supported'   => false,
    'locations_supported' => false,
    'error'               => 'invalid password',
    'error_type'          => 'configuration',
]);
$evt = lastChannelStatus($statusEvents);
t_ok('a channel_status event was forwarded at all', $evt !== null);
t_ok('the forwarded detail includes the real Zello error ("invalid password")',
    $evt !== null && strpos($evt[1], 'invalid password') !== false,
    'got: ' . ($evt[1] ?? '(none)'));
t_ok('...and the error_type ("configuration")',
    $evt !== null && strpos($evt[1], 'configuration') !== false,
    'got: ' . ($evt[1] ?? '(none)'));
t_ok('...and still names the channel and status (nothing lost, only added)',
    $evt !== null
        && strpos($evt[1], 'TicketsCad-CleveOps') !== false
        && strpos($evt[1], 'offline') !== false,
    'got: ' . ($evt[1] ?? '(none)'));
// This is the exact string the old code produced for this frame — the bug
// being fixed. A pass here on the FIXED code would mean nothing changed.
t_ok('the detail is NOT the old bare "Channel X is offline" (proves something changed)',
    $evt !== null && $evt[1] !== "Channel 'TicketsCad-CleveOps' is offline",
    'got exactly the old unhelpful string — the fix did not take effect');

// ── 2. Negative control: a healthy channel (no password) must NOT grow a
//    spurious " — ..." suffix out of nowhere. Same shape Zello sends after
//    the password is removed, per the issue's own before/after frames. ──
$statusEvents = [];
fireFrame($ref, $upstream, [
    'command'             => 'on_channel_status',
    'channel'             => 'TicketsCad-CleveOps',
    'status'              => 'online',
    'users_online'        => 3,
    'images_supported'    => true,
    'texting_supported'   => true,
    'locations_supported' => true,
    'full_duplex'         => false,
]);
$evt2 = lastChannelStatus($statusEvents);
t_ok('control: a healthy channel with no error field reads exactly "Channel X is online" (no stray suffix)',
    $evt2 !== null && $evt2[1] === "Channel 'TicketsCad-CleveOps' is online",
    'got: ' . ($evt2[1] ?? '(none)'));

// ── 3. images_supported/texting_supported/locations_supported bookkeeping
//    (pre-existing Phase 100 behaviour) must survive untouched by this fix ──
$imgProp = new ReflectionProperty(ZelloUpstream::class, 'channelImagesSupported');
$imgProp->setAccessible(true);
$imgMap = $imgProp->getValue($upstream);
t_ok('images_supported bookkeeping still works after the fix (unrelated Phase 100 behaviour, not regressed)',
    ($imgMap['TicketsCad-CleveOps'] ?? null) === true,
    'got: ' . var_export($imgMap['TicketsCad-CleveOps'] ?? null, true));

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
