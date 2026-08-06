<?php
/**
 * Issue #22 (public repo): push_fire() used to gate its ENTIRE call to
 * router_evaluate('audit_event', 'outbound', ...) on _push_enabled() and
 * _push_vapid_config() — but push_fire is the only production call site of
 * router_evaluate for that source channel, so ANY route an admin configured
 * from audit events (Slack, Telegram, local chat, email, whatever) died
 * silently the moment push itself was unconfigured. The reporter's exact
 * repro: push_vapid_subject left blank (the least obvious of the three VAPID
 * settings — the key-generation button doesn't populate it) silently killed
 * every routing-engine channel, not just push.
 *
 * inc/push.php's push_fire() no longer gates on push's own preconditions
 * before reaching the router; inc/channels/push.php's own send handler
 * (the 'push' destination) already independently re-checks
 * _push_enabled()/_push_vapid_config() and fails cleanly (never throws), so
 * removing the top-level gate only stops push from taking every other
 * channel down with it.
 *
 * This drives the REAL push_fire() with a temporary message_routes row
 * (source_channel='audit_event', dest_channel='local_chat', NULL predicate
 * = channel broadcast per inc/router.php's own comment) and asserts the
 * local_chat leg still fires while push is deliberately left unconfigured —
 * both in the issue's exact shape (push_vapid_subject blank) and the
 * broader one the fix comment calls out (push disabled outright).
 *
 * Run: /c/xampp/8.2.4/php/php.exe tools/test_all.php   (or this file directly)
 */

require __DIR__ . '/../config.php';
// _chat_send() (the local_chat destination our test route targets) calls
// sse_publish() for real-time delivery. On a real request this is already
// loaded by the time push_fire() runs; a bare CLI script must pull it in
// itself or the send throws "Call to undefined function sse_publish" deep
// inside router_forward — same requirement test_routing.php has.
require __DIR__ . '/../inc/sse.php';
require __DIR__ . '/../inc/push.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$pass = 0;
$fail = 0;

function t_ok($label, $cond, $hint = '') {
    global $pass, $fail;
    if ($cond) { echo "  [PASS] $label\n"; $pass++; }
    else       { echo "  [FAIL] $label" . ($hint !== '' ? " — $hint" : '') . "\n"; $fail++; }
}

echo "=== GH #22: push_fire() must not gate the whole routing fan-out on push's own config ===\n\n";

// Defense-in-depth: assert the specific gate that caused this bug is gone
// from the source, the same way the GH #8 test guards push_fire's
// inc/broker.php self-load. This TOKENIZES rather than greps the source —
// the fix's own explanatory comment names _push_enabled()/_push_vapid_config()
// in prose (that's exactly the "comment states the bug" pattern this project's
// own CLAUDE.md warns a plain substring scan can't tell apart from a real
// call), so only actual PHP call tokens inside push_fire()'s body count.
$pushSrc = @file_get_contents(__DIR__ . '/../inc/push.php') ?: '';
$tokens = token_get_all($pushSrc);
$inPushFire = false;
$depth = 0;
$foundGateCall = false;
$n = count($tokens);
for ($i = 0; $i < $n; $i++) {
    $tok = $tokens[$i];
    if (!$inPushFire) {
        if (is_array($tok) && $tok[0] === T_STRING && $tok[1] === 'push_fire') {
            $inPushFire = true;
        }
        continue;
    }
    if ($tok === '{') { $depth++; continue; }
    if ($tok === '}') {
        $depth--;
        if ($depth <= 0) { break; } // end of push_fire()'s body
        continue;
    }
    if ($depth === 0) { continue; } // haven't hit the opening brace yet
    if (is_array($tok) && $tok[0] === T_STRING
        && ($tok[1] === '_push_enabled' || $tok[1] === '_push_vapid_config')) {
        // Confirm it's an actual call, i.e. next non-whitespace token is '('.
        for ($j = $i + 1; $j < $n; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) continue;
            if ($tokens[$j] === '(') { $foundGateCall = true; }
            break;
        }
    }
}
t_ok('push_fire() no longer CALLS _push_enabled()/_push_vapid_config() before/around router_evaluate (tokenized, not grepped — the fix comment names both functions in prose)',
    $inPushFire && !$foundGateCall,
    $inPushFire ? 'a real call to one of push\'s own precondition checks still exists in push_fire()' : 'could not locate function push_fire() by tokenizing — test itself needs updating');

// ── Save every setting we're about to touch, so restore is exact ──
$settingNames = ['push_enabled', 'push_vapid_public_key', 'push_vapid_private_key', 'push_vapid_subject'];
$origSettings = [];
foreach ($settingNames as $name) {
    $origSettings[$name] = db_fetch_value(
        "SELECT value FROM `{$prefix}settings` WHERE name = ?", [$name]
    );
}

function _gh22_set_setting($prefix, $name, $value) {
    db_query(
        "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$name, $value]
    );
}

$routeId = null;
$chatIds = [];

try {
    // ── Temporary broadcast route: audit_event -> local_chat, NULL
    //    predicate. inc/router.php: "dest_channel + NULL predicate ->
    //    channel broadcast (today's behaviour)" — no recipient
    //    resolution needed, _chat_send() just needs a body. ──
    db_query(
        "INSERT INTO `{$prefix}message_routes`
            (`name`, `description`, `enabled`, `priority`, `source_channel`,
             `dest_channel`, `recipient_predicate_json`, `direction`, `created_by`)
         VALUES (?, ?, 1, 1, 'audit_event', 'local_chat', NULL, 'outbound', 0)",
        ['ZTEST_gh22_push_gate', 'Temporary test route for GH #22 regression test — safe to delete']
    );
    $routeId = (int) db_insert_id();

    echo "\n-- Scenario 1: the issue's exact repro — push enabled, keys set, subject BLANK --\n";
    _gh22_set_setting($prefix, 'push_enabled', '1');
    _gh22_set_setting($prefix, 'push_vapid_public_key', 'ZTEST_dummy_pub');
    _gh22_set_setting($prefix, 'push_vapid_private_key', 'ZTEST_dummy_priv');
    _gh22_set_setting($prefix, 'push_vapid_subject', '');

    // Control: _push_vapid_config() really does read this as "unconfigured"
    // (proves the scenario actually reproduces the bug's precondition,
    // not just that we wrote some settings rows).
    t_ok('control: _push_vapid_config() reads blank subject as unconfigured',
        _push_vapid_config() === null,
        'VAPID config resolved anyway — this scenario is not exercising the bug');

    $marker1 = 'ZTEST_gh22_marker_' . mt_rand(100000, 999999);
    $forwarded1 = push_fire('gh22.test_event', ['summary' => $marker1]);

    $row1 = db_fetch_one(
        "SELECT id, channel, body FROM `{$prefix}chat_messages`
          WHERE body = ? ORDER BY id DESC LIMIT 1",
        [$marker1]
    );
    if ($row1) { $chatIds[] = (int) $row1['id']; }

    t_ok('push_fire() reports at least one forwarded leg despite blank push_vapid_subject',
        $forwarded1 >= 1, "forwarded count: {$forwarded1}");
    t_ok('the local_chat route actually fired — a chat_messages row with our marker body exists',
        $row1 !== null && $row1 !== false,
        'no matching chat_messages row — router_evaluate never reached the local_chat leg');

    echo "\n-- Scenario 2: push disabled outright (broader case the fix comment calls out) --\n";
    _gh22_set_setting($prefix, 'push_enabled', '0');
    _gh22_set_setting($prefix, 'push_vapid_subject', 'mailto:admin@example.test');

    t_ok('control: _push_enabled() reads disabled', _push_enabled() === false);

    $marker2 = 'ZTEST_gh22_marker_' . mt_rand(100000, 999999);
    $forwarded2 = push_fire('gh22.test_event', ['summary' => $marker2]);

    $row2 = db_fetch_one(
        "SELECT id, channel, body FROM `{$prefix}chat_messages`
          WHERE body = ? ORDER BY id DESC LIMIT 1",
        [$marker2]
    );
    if ($row2) { $chatIds[] = (int) $row2['id']; }

    t_ok('push_fire() still reaches the router with push_enabled=0',
        $forwarded2 >= 1, "forwarded count: {$forwarded2}");
    t_ok('the local_chat route fired a second time with push disabled outright',
        $row2 !== null && $row2 !== false,
        'no matching chat_messages row — a disabled push setting blocked the whole fan-out');

    // Negative control: disable OUR test route and confirm push_fire no
    // longer produces a matching chat row — proves the two positive
    // assertions above are actually detecting our route firing, not some
    // pre-existing unrelated local_chat traffic.
    echo "\n-- Negative control: with the test route disabled, no new chat row appears --\n";
    db_query("UPDATE `{$prefix}message_routes` SET enabled = 0 WHERE id = ?", [$routeId]);
    $marker3 = 'ZTEST_gh22_marker_' . mt_rand(100000, 999999);
    push_fire('gh22.test_event', ['summary' => $marker3]);
    $row3 = db_fetch_one(
        "SELECT id FROM `{$prefix}chat_messages` WHERE body = ? LIMIT 1", [$marker3]
    );
    t_ok('disabling the test route stops the chat row from appearing (control proves the marker method works)',
        $row3 === null || $row3 === false,
        'a chat row appeared even with the test route disabled — the marker technique is not isolating our route');

} catch (Throwable $e) {
    echo "[FAIL] setup/exec threw: " . $e->getMessage() . "\n";
    $fail++;
} finally {
    foreach ($chatIds as $id) {
        try { db_query("DELETE FROM `{$prefix}chat_messages` WHERE id = ?", [$id]); }
        catch (Throwable $e) { /* best-effort */ }
    }
    if ($routeId) {
        try { db_query("DELETE FROM `{$prefix}message_routes` WHERE id = ?", [$routeId]); }
        catch (Throwable $e) { /* best-effort */ }
    }
    foreach ($origSettings as $name => $value) {
        if ($value === null || $value === false) {
            try { db_query("DELETE FROM `{$prefix}settings` WHERE name = ?", [$name]); }
            catch (Throwable $e) { /* best-effort */ }
        } else {
            _gh22_set_setting($prefix, $name, $value);
        }
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
