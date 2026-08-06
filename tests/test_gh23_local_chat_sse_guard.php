<?php
/**
 * Issue #23 (public repo, rjonesbsink) — item 4 of that report, fixed on
 * its own merits per the reporter's own framing: "a fatal in a fan-out
 * path that only looks harmless because the feature that reaches it does
 * not exist yet."
 *
 * (The rest of #23 — whether to build an inbound poller for Slack/
 * Telegram at all, and if so which delivery model — is a product-scope
 * decision the reporter explicitly said "I do not think the choice is
 * mine to make." That decision is left to Eric; this fix is the narrow,
 * self-contained defect the reporter separated out as worth taking
 * regardless of the outcome.)
 *
 * _chat_send() in inc/channels/local_chat.php picks one of three ways to
 * publish a real-time SSE nudge after the chat row is already committed.
 * The first branch checked function_exists('sse_publish_for_user') but
 * CALLED sse_publish() — a guard/call mismatch that happened to be
 * harmless only because both functions live in the same inc/sse.php
 * require block. The third (else) branch had NO guard at all. A caller
 * with no recipient uid and no ticket id — exactly what an inbound-
 * channel forward looks like, and also exactly what a bare CLI/background
 * caller that never loaded inc/sse.php produces — took that unguarded
 * branch and threw "Call to undefined function sse_publish()". That is a
 * PHP Error, which does NOT extend Exception, so _chat_send()'s own
 * catch (Exception $e) never sees it: the caller crashes even though the
 * chat_messages row was already durably written moments earlier.
 *
 * This drives the real _chat_send() in a bare CLI context — config.php
 * only, deliberately NOT requiring inc/sse.php, matching every one of
 * inc/broker.php's channel auto-loads (confirmed: broker.php requires
 * every inc/channels/*.php file and inc/router.php, never inc/sse.php) —
 * across all three of the function's branches (org-wide broadcast,
 * direct-message, ticket-scoped), then requires inc/sse.php partway
 * through and re-runs the same three cases to prove real-time delivery
 * still works exactly as before when it IS available. The row being
 * durable independent of SSE delivery is asserted directly against the
 * database, not inferred from the return value.
 *
 * Run: /c/xampp/8.2.4/php/php.exe tools/test_all.php   (or this file directly)
 */

require __DIR__ . '/../config.php';
require __DIR__ . '/../inc/broker.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$pass = 0;
$fail = 0;

function t_ok($label, $cond, $hint = '') {
    global $pass, $fail;
    if ($cond) { echo "  [PASS] $label\n"; $pass++; }
    else       { echo "  [FAIL] $label" . ($hint !== '' ? " — $hint" : '') . "\n"; $fail++; }
}

echo "=== GH #23 item 4: local_chat's SSE nudge must be best-effort, never fatal ===\n\n";

t_ok('control: inc/sse.php was NOT pulled in by requiring inc/broker.php (this is the real bug scenario)',
    !function_exists('sse_publish'),
    'sse_publish() already exists — broker.php auto-loaded it, so this test is not exercising the unguarded path');

$insertedIds = [];
function sendAndTrack(&$insertedIds, array $message) {
    $result = _chat_send($message);
    if (!empty($result['chat_id'])) {
        $insertedIds[] = (int) $result['chat_id'];
    }
    return $result;
}

try {
    echo "\n-- Without inc/sse.php loaded (the exact crash scenario) --\n";

    // Branch 3 (else): no recipient, no ticket — the branch that used to
    // have NO guard at all. This is the one that actually crashed.
    $marker1 = 'ZTEST_gh23_broadcast_' . mt_rand(100000, 999999);
    $threw1 = null;
    try {
        $r1 = sendAndTrack($insertedIds, ['body' => $marker1]);
    } catch (\Throwable $e) {
        $threw1 = $e;
    }
    t_ok('org-wide broadcast (no recipient, no ticket) does not throw with sse.php unloaded',
        $threw1 === null,
        $threw1 ? (get_class($threw1) . ': ' . $threw1->getMessage()) : '');
    t_ok('...and reports success', $threw1 === null && !empty($r1['success']));
    t_ok('...and the row is actually in chat_messages (durable regardless of SSE)',
        $threw1 === null && db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}chat_messages` WHERE body = ?", [$marker1]
        ) == 1);

    // Branch 1: direct message (recipient uid set). Its guard checked the
    // WRONG function name before the fix — still happened not to crash
    // (both functions co-defined), but assert it explicitly so a future
    // split of inc/sse.php into separate files can't silently break it.
    $marker2 = 'ZTEST_gh23_dm_' . mt_rand(100000, 999999);
    $threw2 = null;
    try {
        $r2 = sendAndTrack($insertedIds, ['body' => $marker2, 'to' => '999999']);
    } catch (\Throwable $e) {
        $threw2 = $e;
    }
    t_ok('direct message (recipient uid set) does not throw with sse.php unloaded',
        $threw2 === null,
        $threw2 ? (get_class($threw2) . ': ' . $threw2->getMessage()) : '');
    t_ok('...and reports success', $threw2 === null && !empty($r2['success']));

    // Branch 2: ticket-scoped. This one already guarded on the function it
    // actually calls before the fix — included as a control that the fix
    // didn't disturb the one branch that was already correct.
    $marker3 = 'ZTEST_gh23_ticket_' . mt_rand(100000, 999999);
    $threw3 = null;
    try {
        $r3 = sendAndTrack($insertedIds, ['body' => $marker3, 'ticket_id' => 1]);
    } catch (\Throwable $e) {
        $threw3 = $e;
    }
    t_ok('ticket-scoped chat does not throw with sse.php unloaded',
        $threw3 === null,
        $threw3 ? (get_class($threw3) . ': ' . $threw3->getMessage()) : '');
    t_ok('...and reports success', $threw3 === null && !empty($r3['success']));

    // ── Tokenized source check: every branch's guard names the function
    //    it actually calls on that branch. Catches a future edit that
    //    reintroduces the mismatch, without depending on run-time
    //    behaviour that (as this bug proved) can look fine by accident. ──
    echo "\n-- Source-level: each guard matches its call (tokenized, not grepped) --\n";
    $src = file_get_contents(__DIR__ . '/../inc/channels/local_chat.php');
    // Isolate _chat_send()'s body the same brace-counting way the #22/#114
    // tests in this suite do, so a match elsewhere in the file can't hide
    // a regression here.
    $tokens = token_get_all($src);
    $body = '';
    $inFn = false;
    $depth = 0;
    foreach ($tokens as $tok) {
        if (is_array($tok) && $tok[0] === T_STRING && $tok[1] === '_chat_send') {
            $inFn = true;
            continue;
        }
        if (!$inFn) continue;
        $text = is_array($tok) ? $tok[1] : $tok;
        if ($text === '{') { $depth++; if ($depth === 1) continue; }
        if ($text === '}') { $depth--; if ($depth === 0) break; }
        if ($depth >= 1) { $body .= $text; }
    }
    t_ok('_chat_send() body was located', $body !== '');
    t_ok('the sse_publish($eventType) direct-message call is guarded by function_exists(\'sse_publish\')',
        (bool) preg_match(
            "/function_exists\\('sse_publish'\\)\\s*\\)\\s*\\{[^}]*sse_publish\\(\\s*'chat:message'/s",
            $body
        ));
    t_ok('the final sse_publish($eventType) broadcast call is also guarded by function_exists(\'sse_publish\')',
        substr_count($body, "function_exists('sse_publish')") >= 2,
        'expected the guard to appear before BOTH direct sse_publish() calls, not just one');

    echo "\n-- With inc/sse.php loaded (positive control — must still work as before) --\n";
    require __DIR__ . '/../inc/sse.php';
    t_ok('control: sse_publish() is now actually loaded', function_exists('sse_publish'));

    $marker4 = 'ZTEST_gh23_broadcast_sse_' . mt_rand(100000, 999999);
    $r4 = sendAndTrack($insertedIds, ['body' => $marker4]);
    t_ok('org-wide broadcast still succeeds with sse.php loaded', !empty($r4['success']));
    t_ok('...and an sse_events row was actually published (not just skipped)',
        db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}sse_events` WHERE `event_type` = 'chat:message'
              AND `payload` LIKE ?",
            ['%' . $marker4 . '%']
        ) >= 1);

    $marker5 = 'ZTEST_gh23_dm_sse_' . mt_rand(100000, 999999);
    $r5 = sendAndTrack($insertedIds, ['body' => $marker5, 'to' => '999999']);
    t_ok('direct message still succeeds with sse.php loaded', !empty($r5['success']));

} finally {
    foreach ($insertedIds as $id) {
        try { db_query("DELETE FROM `{$prefix}chat_messages` WHERE id = ?", [$id]); }
        catch (Throwable $e) { /* best-effort */ }
    }
    try {
        db_query("DELETE FROM `{$prefix}sse_events` WHERE payload LIKE '%ZTEST_gh23%'");
    } catch (Throwable $e) { /* best-effort */ }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
