<?php
/**
 * Webhook replay protection — behavioural regression tests.
 *
 * Covers the defect reported privately by Ron Jones (@rjonesbsink) on
 * 2026-08-02: outbound deliveries were signed over the body alone, with
 * no timestamp, nonce or delivery id anywhere in the request, so a
 * captured delivery replayed at any later time verified as authentic
 * forever.
 *
 * WHAT THESE TESTS DELIBERATELY DO NOT DO
 *
 * They do not grep inc/webhooks.php for the string 'X-Webhook-Timestamp'
 * or assert that some function exists. A source-shaped test passes for
 * the entire time the protection is present but ineffective — which is
 * exactly the failure mode that let the guide and the code drift apart
 * for months. Every assertion below runs the REAL production functions
 * (webhook_build_headers(), the same builder _webhook_send() hands to
 * cURL, and webhook_verify_signature()) and asserts on what they DO.
 *
 * The clock is injected rather than slept on, so "31 minutes later" is a
 * parameter and the suite stays fast and deterministic.
 *
 * Run:  php tests/test_webhook_replay_protection.php
 *   Or: php tools/test_all.php (picks this up automatically)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/webhooks.php';

$pass = 0;
$fail = 0;
$failures = [];

function wrt_ok(bool $cond, string $what, string $detail = ''): void {
    global $pass, $fail, $failures;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else {
        $fail++; $failures[] = $what . ($detail ? " — {$detail}" : '');
        echo "  FAIL  {$what}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

/** Pull a header value out of the cURL-style "Name: value" list the real builder returns. */
function wrt_header(array $headers, string $name): ?string {
    foreach ($headers as $h) {
        $pos = strpos($h, ':');
        if ($pos === false) continue;
        if (strcasecmp(trim(substr($h, 0, $pos)), $name) === 0) {
            return trim(substr($h, $pos + 1));
        }
    }
    return null;
}

echo "=== Webhook Replay Protection ===\n\n";

$SECRET = 'test-secret-do-not-use-in-production';
$BODY   = '{"event_type":"incident.created","timestamp":"2026-08-02T09:45:29Z","data":{"ticket_id":4242}}';
$EVENT  = 'incident.created';
$TOL    = 300;

// ─────────────────────────────────────────────────────────────────────
echo "-- 1. The wire carries what a receiver needs to reject a replay --\n";
// ─────────────────────────────────────────────────────────────────────
$sentAt  = 1785664309;
$uid     = webhook_new_delivery_uid();
$headers = webhook_build_headers($BODY, $SECRET, $EVENT, $uid, $sentAt);

$hTs  = wrt_header($headers, 'X-Webhook-Timestamp');
$hSig = wrt_header($headers, 'X-Webhook-Signature-V2');
$hUid = wrt_header($headers, 'X-Webhook-Delivery');
$hEvt = wrt_header($headers, 'X-Webhook-Event');

wrt_ok($hTs === (string) $sentAt, 'X-Webhook-Timestamp carries the transmission time',
       'got ' . var_export($hTs, true));
wrt_ok($hSig !== null && $hSig !== '', 'X-Webhook-Signature-V2 is sent');
wrt_ok($hUid === $uid, 'X-Webhook-Delivery carries the delivery uid');
wrt_ok($hEvt === $EVENT, 'X-Webhook-Event carries the event type');

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 2. A fresh delivery is accepted --\n";
// ─────────────────────────────────────────────────────────────────────
$r = webhook_verify_signature($BODY, (string) $hSig, (string) $hTs, $SECRET, $TOL, $sentAt);
wrt_ok($r['valid'] === true, 'genuine delivery verifies at the moment it was sent',
       'reason=' . $r['reason']);

// Received a few seconds later, as a real one would be.
$r = webhook_verify_signature($BODY, (string) $hSig, (string) $hTs, $SECRET, $TOL, $sentAt + 5);
wrt_ok($r['valid'] === true, 'genuine delivery verifies 5 s later', 'reason=' . $r['reason']);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 3. THE REPORTED BUG: a captured delivery, replayed later --\n";
// ─────────────────────────────────────────────────────────────────────
// Byte-for-byte the same request Ron captured: same body, same signature,
// same timestamp header. Only the wall clock has moved.
$r = webhook_verify_signature($BODY, (string) $hSig, (string) $hTs, $SECRET, $TOL, $sentAt + 3600);
wrt_ok($r['valid'] === false && $r['reason'] === 'stale',
       'replay one hour later is REJECTED as stale', 'reason=' . $r['reason']);

$r = webhook_verify_signature($BODY, (string) $hSig, (string) $hTs, $SECRET, $TOL,
                              $sentAt + (30 * 86400));
wrt_ok($r['valid'] === false && $r['reason'] === 'stale',
       'replay thirty days later is REJECTED as stale', 'reason=' . $r['reason']);

// A replay from BEFORE the stamp (skewed or forged far-future timestamp)
// must not buy an unbounded window either.
$r = webhook_verify_signature($BODY, (string) $hSig, (string) $hTs, $SECRET, $TOL, $sentAt - 3600);
wrt_ok($r['valid'] === false && $r['reason'] === 'stale',
       'delivery an hour in the future is REJECTED as stale', 'reason=' . $r['reason']);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 4. The window boundary is where it claims to be --\n";
// ─────────────────────────────────────────────────────────────────────
$r = webhook_verify_signature($BODY, (string) $hSig, (string) $hTs, $SECRET, $TOL, $sentAt + $TOL - 1);
wrt_ok($r['valid'] === true, 'just INSIDE the window (T-1 s) is accepted', 'reason=' . $r['reason']);

$r = webhook_verify_signature($BODY, (string) $hSig, (string) $hTs, $SECRET, $TOL, $sentAt + $TOL);
wrt_ok($r['valid'] === true, 'exactly ON the window edge (T s) is accepted', 'reason=' . $r['reason']);

$r = webhook_verify_signature($BODY, (string) $hSig, (string) $hTs, $SECRET, $TOL, $sentAt + $TOL + 1);
wrt_ok($r['valid'] === false && $r['reason'] === 'stale',
       'just OUTSIDE the window (T+1 s) is rejected', 'reason=' . $r['reason']);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 5. NEGATIVE CONTROLS: prove the protection is what rejects --\n";
// ─────────────────────────────────────────────────────────────────────
//
// A staleness test can pass for the wrong reason — a verifier that
// rejected everything would satisfy section 3 too. These assertions fail
// if the protection is weakened or removed, and are the reason this file
// is worth having.

// 5a. The signature must actually DEPEND on the timestamp. If someone
//     reverts webhook_sign() to the old body-only digest, this is the
//     assertion that goes red first: the two signatures become equal.
$sigA = webhook_sign((string) $sentAt,       $BODY, $SECRET);
$sigB = webhook_sign((string) ($sentAt + 1), $BODY, $SECRET);
wrt_ok($sigA !== $sigB,
       'NEGATIVE CONTROL: signature changes when only the timestamp changes',
       'body-only signing would make these identical');

// 5b. The old scheme is demonstrably replayable — this is the defect,
//     asserted directly. If webhook_sign_legacy() ever grew a time
//     input this would fail and the compatibility story would need
//     revisiting.
$legacyThen = webhook_sign_legacy($BODY, $SECRET);
$legacyNow  = webhook_sign_legacy($BODY, $SECRET);
wrt_ok($legacyThen === $legacyNow,
       'NEGATIVE CONTROL: the legacy body-only digest is time-invariant (the bug)');

// 5c. Staleness — not some unrelated failure — is what rejects the
//     replay. Same replayed request, tolerance widened to cover it:
//     it verifies. So section 3's rejections are caused by the freshness
//     window and nothing else.
$r = webhook_verify_signature($BODY, (string) $hSig, (string) $hTs, $SECRET, 86400, $sentAt + 3600);
wrt_ok($r['valid'] === true,
       'NEGATIVE CONTROL: same replay passes when the window is widened',
       'proves the freshness check is doing the rejecting — reason=' . $r['reason']);

// 5d. Signature verification still works: a tampered body is refused
//     even inside the window. Without this, a verifier that only checked
//     the clock would pass every test above.
$tampered = str_replace('4242', '9999', $BODY);
$r = webhook_verify_signature($tampered, (string) $hSig, (string) $hTs, $SECRET, $TOL, $sentAt);
wrt_ok($r['valid'] === false && $r['reason'] === 'bad_signature',
       'NEGATIVE CONTROL: tampered body is rejected inside the window',
       'reason=' . $r['reason']);

// 5e. A wrong secret is refused.
$r = webhook_verify_signature($BODY, (string) $hSig, (string) $hTs, 'wrong-secret', $TOL, $sentAt);
wrt_ok($r['valid'] === false && $r['reason'] === 'bad_signature',
       'NEGATIVE CONTROL: wrong secret is rejected', 'reason=' . $r['reason']);

// 5f. An attacker cannot re-stamp a captured delivery. Moving the
//     timestamp forward to defeat the window invalidates the signature,
//     because the timestamp is INSIDE the signed material. This is the
//     whole point of signing "<ts>.<body>" rather than sending the
//     timestamp alongside an unbound signature.
$freshTs = (string) ($sentAt + 3600);
$r = webhook_verify_signature($BODY, (string) $hSig, $freshTs, $SECRET, $TOL, $sentAt + 3600);
wrt_ok($r['valid'] === false && $r['reason'] === 'bad_signature',
       'NEGATIVE CONTROL: re-stamping a captured delivery breaks the signature',
       'reason=' . $r['reason']);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 6. Malformed input is refused, not crashed on --\n";
// ─────────────────────────────────────────────────────────────────────
$r = webhook_verify_signature($BODY, '', (string) $hTs, $SECRET, $TOL, $sentAt);
wrt_ok($r['valid'] === false && $r['reason'] === 'missing_signature', 'missing signature refused');

$r = webhook_verify_signature($BODY, (string) $hSig, '', $SECRET, $TOL, $sentAt);
wrt_ok($r['valid'] === false && $r['reason'] === 'missing_timestamp', 'missing timestamp refused');

$r = webhook_verify_signature($BODY, (string) $hSig, 'not-a-number', $SECRET, $TOL, $sentAt);
wrt_ok($r['valid'] === false && $r['reason'] === 'bad_timestamp', 'non-numeric timestamp refused');

// The `sha256=` prefix was undocumented, so receivers handle it
// inconsistently. Both spellings must verify — this is the mismatch that
// made the published guide impossible to implement.
$bare = str_replace('sha256=', '', (string) $hSig);
$r = webhook_verify_signature($BODY, $bare, (string) $hTs, $SECRET, $TOL, $sentAt);
wrt_ok($r['valid'] === true, 'signature without the sha256= prefix verifies', 'reason=' . $r['reason']);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 7. The delivery uid is a usable idempotency key --\n";
// ─────────────────────────────────────────────────────────────────────
$u1 = webhook_new_delivery_uid();
$u2 = webhook_new_delivery_uid();
wrt_ok($u1 !== $u2, 'each delivery mints a distinct uid');
wrt_ok((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $u1),
       'delivery uid is a well-formed UUIDv4', $u1);

// A retry is the SAME logical delivery, so it must re-present the SAME
// uid — with a FRESH timestamp and therefore a fresh signature. A
// receiver has to be able to recognise the retry as a duplicate while
// still seeing it as fresh enough to accept.
$retryAt      = $sentAt + 1800;
$retryHeaders = webhook_build_headers($BODY, $SECRET, $EVENT, $uid, $retryAt);
wrt_ok(wrt_header($retryHeaders, 'X-Webhook-Delivery') === $uid,
       'a retry re-presents the ORIGINAL delivery uid');
wrt_ok(wrt_header($retryHeaders, 'X-Webhook-Timestamp') === (string) $retryAt,
       'a retry is stamped with the RETRY time, not the original');

$rSig = (string) wrt_header($retryHeaders, 'X-Webhook-Signature-V2');
wrt_ok($rSig !== $hSig, 'a retry is re-signed (signature differs from the original)');

// The retry must verify at retry time — this is what would break if the
// freshness window were keyed off the timestamp embedded in the stored
// body (which is the time of the FIRST attempt) instead of the header.
$r = webhook_verify_signature($BODY, $rSig, (string) $retryAt, $SECRET, $TOL, $retryAt + 2);
wrt_ok($r['valid'] === true,
       'a retry sent 30 min after the original still verifies', 'reason=' . $r['reason']);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 8. Tunables are configurable, with safe bounds --\n";
// ─────────────────────────────────────────────────────────────────────
$tol = webhook_replay_tolerance();
wrt_ok($tol >= 30 && $tol <= 86400, 'tolerance is within its clamp', 'got ' . $tol);
wrt_ok(is_bool(webhook_legacy_signature_enabled()), 'legacy-signature toggle resolves to a boolean');

// The legacy header must still be emitted while the toggle is on, so an
// upgrade does not break receivers that reverse-engineered the old
// scheme. (Default is on; if this install has switched it off, the
// assertion adapts rather than failing spuriously.)
$legacyHeader = wrt_header($headers, 'X-Webhook-Signature');
if (webhook_legacy_signature_enabled()) {
    wrt_ok($legacyHeader === 'sha256=' . webhook_sign_legacy($BODY, $SECRET),
           'legacy X-Webhook-Signature still emitted for back-compat');
} else {
    wrt_ok($legacyHeader === null, 'legacy X-Webhook-Signature suppressed when disabled');
}

// ─────────────────────────────────────────────────────────────────────
echo "\n";
if ($failures) {
    echo "Failures:\n";
    foreach ($failures as $f) echo "  - {$f}\n";
    echo "\n";
}
echo "=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
