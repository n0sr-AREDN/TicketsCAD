<?php
/**
 * The notification fan-out must not be able to stall a dispatch. 2026-07-31.
 *
 * WHAT THIS IS DEFENDING AGAINST
 * -----------------------------
 * audit_log() used to deliver Web Push, webhooks, SMS, e-mail and Slack
 * inside the dispatcher's own request, and inc/sse.php fired webhooks a
 * second time from sse_publish(). Measured through the real writers with the
 * endpoints black-holed at 203.0.113.1 (RFC 5737 TEST-NET-3 — unrouted, so a
 * SYN gets no reply at all, which is the shape of an upstream outage rather
 * than a local refusal):
 *
 *     create an incident ......... 21.34 s
 *     change a unit's status ..... 21.33 s
 *
 * Every action, for the whole outage, each one holding a PHP worker. This
 * file asserts the bound that replaced it.
 *
 * HOW IT TESTS
 * ------------
 * Through incident_create_internal() and responder_set_status_internal() —
 * the same functions api/incidents.php and the dashboard call. Not a
 * re-implementation, and not a hand-seeded queue row: this project has
 * shipped several bugs that were masked by a test arranging the one state the
 * real writer never produces (see the bed-automation entries in CLAUDE.md).
 *
 * The endpoints really are black-holed, so the timing assertions mean
 * something. A test that pointed at a closed port on localhost would get an
 * instant refusal and pass no matter how broken the code was.
 *
 * @requires-db
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/notify-fanout.php';
require_once __DIR__ . '/../inc/pending-messages.php';
require_once __DIR__ . '/../inc/scheduled-jobs.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0; $skipped = 0;
function ok(string $m): void   { global $pass; $pass++; echo "  PASS: $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  FAIL: $m\n"; }
function is_ok($cond, string $m): void { $cond ? ok($m) : bad($m); }
function skip(string $m): void { global $skipped; $skipped++; echo "  SKIP: $m\n"; }
function done(): void {
    global $pass, $fail, $skipped;
    echo "\n=== $pass passed, $fail failed, $skipped skipped ===\n";
    exit($fail > 0 ? 1 : 0);
}

/** A dispatch action must never take longer than this, network up or down. */
const DISPATCH_BUDGET_S = 5.0;

echo "\n=== Notification fan-out — a dispatch action never waits on the internet ===\n";

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 1. The breaker decision (pure — no clock, no database) --\n";

$T = 1800000000;
$closed = ['fails' => 0, 'opened_at' => 0, 'last_error' => ''];
$armed  = ['fails' => 2, 'opened_at' => $T, 'last_error' => 'timeout'];

is_ok(notify_breaker_decide($closed, $T, 2, 60)['open'] === false,
      'no failures — closed');
is_ok(notify_breaker_decide(['fails' => 1, 'opened_at' => 0], $T, 2, 60)['open'] === false,
      'below the threshold — still closed');
is_ok(notify_breaker_decide($armed, $T, 2, 60)['open'] === true,
      'at the threshold, inside the cool-off — open');
is_ok(notify_breaker_decide($armed, $T + 59, 2, 60)['open'] === true,
      'one second before the cool-off ends — still open');
$half = notify_breaker_decide($armed, $T + 60, 2, 60);
is_ok($half['open'] === false && $half['half_open'] === true,
      'exactly at the cool-off — half-open, one attempt allowed');
is_ok(notify_breaker_decide($armed, $T + 30, 2, 60)['retry_in'] === 30,
      'retry_in counts down honestly (30s left of a 60s cool-off)');
is_ok(notify_breaker_decide($armed, $T, 0, 60)['open'] === false,
      'threshold 0 disables the breaker entirely');
is_ok(strpos(notify_breaker_decide($armed, $T, 2, 60)['reason'], 'timeout') !== false,
      'the reason names the last error, so an operator can act on it');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. Clamping a timeout to the remaining budget --\n";

is_ok(notify_clamp_timeout(30, null) === 30,
      'no deadline — the configured timeout is used unchanged');
is_ok(notify_clamp_timeout(30, 4.0) === 4,
      'a 4-second budget clamps a 30-second timeout to 4');
is_ok(notify_clamp_timeout(3, 30.0) === 3,
      'a generous budget never RAISES a timeout');
// The inversion that makes this its own function: 0 means "no timeout" to
// both cURL and Guzzle, so a clamp that returned 0 would produce the exact
// unbounded call the budget exists to prevent.
is_ok(notify_clamp_timeout(30, 0.0) === 1,
      'an exhausted budget clamps to 1s, never to 0 (0 means UNLIMITED downstream)');
is_ok(notify_clamp_timeout(30, 0.4) === 1,
      'a sub-second budget still yields at least 1s, never 0');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. Round-tripping an event through the queue payload --\n";

$enc = _notify_encode_payload('incident.created',
        ['summary' => 'Structure fire', 'ticket_id' => 42, 'details' => ['a' => 'b']],
        ['webhook', 'push']);
is_ok(is_string($enc), 'an event encodes');
$dec = $enc !== null ? notify_fanout_decode($enc) : null;
is_ok($dec && $dec['event_type'] === 'incident.created', 'the event type survives');
is_ok($dec && ($dec['payload']['ticket_id'] ?? 0) === 42, 'the payload survives');
is_ok($dec && $dec['channels'] === ['webhook', 'push'], 'the channel set survives');
is_ok(notify_fanout_decode('not json at all') === null,
      'an unreadable row decodes to null rather than a half-built event');

// A payload too big for the TEXT column must shed its optional part rather
// than fail the INSERT — losing the notification entirely is the worse
// outcome than delivering it without its details block.
$big = _notify_encode_payload('incident.created',
        ['summary' => 'x', 'details' => ['blob' => str_repeat('A', 80000)]], ['webhook']);
is_ok(is_string($big) && strlen($big) <= NOTIFY_PAYLOAD_MAX_BYTES,
      'an oversize payload is shed down to fit rather than dropped');
$decBig = $big !== null ? notify_fanout_decode($big) : null;
is_ok($decBig && ($decBig['payload']['details_dropped'] ?? '') === 'oversize',
      'and it says so, so nobody wonders where the details went');

// Only the two real legs are honoured, whatever a row claims.
$odd = notify_fanout_decode(json_encode(
        ['event_type' => 'x', 'channels' => ['push', 'carrier_pigeon'], 'payload' => []]));
is_ok($odd && $odd['channels'] === ['push'],
      'an unknown channel in a stored row is ignored, not attempted');

// ─────────────────────────────────────────────────────────────────────────
// Everything below needs a database.
$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) { skip('No database available — the rest of this file needs one'); done(); }

$prefix = $GLOBALS['db_prefix'] ?? '';
$haveQueue = false;
try {
    $haveQueue = (bool) db_fetch_one(
        "SELECT 1 FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1",
        [$prefix . 'pending_routed_messages']);
} catch (Exception $e) {}
if (!$haveQueue) {
    skip('pending_routed_messages missing — run php sql/run_migrations.php');
    done();
}

$type = null; $unit = null;
try {
    $type = db_fetch_one("SELECT id FROM `{$prefix}in_types` ORDER BY id LIMIT 1");
    $unit = db_fetch_one("SELECT id FROM `{$prefix}responder` ORDER BY id LIMIT 1");
} catch (Exception $e) {}
if (!$type || !$unit) {
    skip('no seed incident type / responder — cannot drive the real dispatch writers');
    done();
}

// ── Fixture: black-holed endpoints, restored in the shutdown handler ─────
$BLACKHOLE_WEBHOOK = 'http://203.0.113.1/hook';
$BLACKHOLE_PUSH    = 'https://203.0.113.2/wpush/';
$savedBreaker = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = ? LIMIT 1",
                               [NOTIFY_BREAKER_SETTING]);
$savedJobRow  = db_fetch_one("SELECT * FROM `{$prefix}scheduled_job_runs`
                               WHERE job_key = 'pending_messages_tick'");

function cleanup_fixture(): void {
    global $prefix, $BLACKHOLE_WEBHOOK, $BLACKHOLE_PUSH, $savedBreaker, $savedJobRow;
    try {
        db_query("DELETE FROM `{$prefix}webhook_subscriptions` WHERE target_url = ?", [$BLACKHOLE_WEBHOOK]);
        db_query("DELETE FROM `{$prefix}push_subscriptions` WHERE endpoint LIKE ?", [$BLACKHOLE_PUSH . '%']);
        db_query("DELETE FROM `{$prefix}pending_routed_messages` WHERE channel = ?", [NOTIFY_FANOUT_CHANNEL]);
        db_query("DELETE FROM `{$prefix}ticket` WHERE scope LIKE 'NOTIFY-FANOUT TEST%'");
        if ($savedBreaker === null || $savedBreaker === false) {
            db_query("DELETE FROM `{$prefix}settings` WHERE name = ?", [NOTIFY_BREAKER_SETTING]);
        } else {
            db_query("UPDATE `{$prefix}settings` SET value = ? WHERE name = ?",
                     [$savedBreaker, NOTIFY_BREAKER_SETTING]);
        }
        if ($savedJobRow) {
            db_query("UPDATE `{$prefix}scheduled_job_runs`
                         SET last_run_at = ?, last_ok_at = ?, last_status = ?
                       WHERE job_key = 'pending_messages_tick'",
                     [$savedJobRow['last_run_at'], $savedJobRow['last_ok_at'], $savedJobRow['last_status']]);
        } else {
            db_query("DELETE FROM `{$prefix}scheduled_job_runs` WHERE job_key = 'pending_messages_tick'");
        }
    } catch (Exception $e) {}
}
register_shutdown_function('cleanup_fixture');

// A black-holed webhook subscriber is the dependency-free way to put a dead
// endpoint on the fan-out path: no VAPID key generation, so it works in CI
// and on a workstation whose OpenSSL cannot mint an EC key.
try {
    db_query("DELETE FROM `{$prefix}webhook_subscriptions` WHERE target_url = ?", [$BLACKHOLE_WEBHOOK]);
    db_query("INSERT INTO `{$prefix}webhook_subscriptions`
                 (name, target_url, hmac_secret, event_filters_json, active, created_at)
              VALUES ('notify-fanout-test', ?, 'test-secret', '[\"*\"]', 1, NOW())",
             [$BLACKHOLE_WEBHOOK]);
} catch (Exception $e) {
    skip('could not create a webhook subscription — ' . $e->getMessage());
    done();
}
notify_fanout_forget_channel_cache();
db_query("DELETE FROM `{$prefix}pending_routed_messages` WHERE channel = ?", [NOTIFY_FANOUT_CHANNEL]);
notify_breaker_reset();

$_SESSION['user_id'] = test_admin_user_id();
$_SESSION['user']    = 'notify-fanout-test';
$actor = (int) $_SESSION['user_id'];

require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/../inc/responder-write.php';

$createdTickets = [];
function drive_dispatch(array $type, array $unit, int $actor, array &$createdTickets): array {
    $t0 = microtime(true);
    $res = incident_create_internal([
        'in_types_id' => (int) $type['id'],
        'scope'       => 'NOTIFY-FANOUT TEST — safe to delete',
        'street'      => '1 Test St', 'city' => 'your deployment', 'state' => 'MN',
        'severity'    => 2,
    ], $actor);
    $tCreate = microtime(true) - $t0;
    $tid = (int) ($res['ticket_id'] ?? $res['id'] ?? 0);
    if ($tid > 0) $createdTickets[] = $tid;

    $st = db_fetch_one("SELECT id FROM `" . ($GLOBALS['db_prefix'] ?? '') . "un_status` ORDER BY id LIMIT 1");
    $tStatus = 0.0;
    if ($st) {
        $t1 = microtime(true);
        responder_set_status_internal((int) $unit['id'], (int) $st['id'], $actor, '');
        $tStatus = microtime(true) - $t1;
    }
    return ['create' => $tCreate, 'status' => $tStatus, 'ticket_id' => $tid];
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. A dispatch action with the endpoints black-holed, sweep running --\n";
echo "   (the supported configuration: a systemd timer drains the queue)\n";

// The heartbeat the real tick writes. Recorded here through the production
// writer, sched_job_record(), not by hand-poking the table.
sched_job_record('pending_messages_tick', 'ok', 'test: pretending the timer just ran');

$before = notify_queue_depth();
$r = drive_dispatch($type, $unit, $actor, $createdTickets);
printf("   create=%.2fs  unit status=%.2fs\n", $r['create'], $r['status']);

is_ok($r['create'] < DISPATCH_BUDGET_S,
      sprintf('creating an incident took %.2fs — under the %.1fs budget', $r['create'], DISPATCH_BUDGET_S));
is_ok($r['status'] < DISPATCH_BUDGET_S,
      sprintf('a unit status change took %.2fs — under the %.1fs budget', $r['status'], DISPATCH_BUDGET_S));

// The number that matters most. Anything above about a second here means the
// dispatch path went to the network, which is the whole defect.
is_ok($r['create'] < 1.5 && $r['status'] < 1.5,
      'neither action approached the 3s connect timeout — i.e. no outbound call was made');

$after = notify_queue_depth();
is_ok($after['pending'] > $before['pending'],
      'the notification was QUEUED, not dropped (' . $before['pending'] . ' -> ' . $after['pending'] . ')');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 5. The operator can see the backlog --\n";

require_once __DIR__ . '/../inc/health-check.php';
$hc = health_check_scheduled_jobs();
$row = null;
foreach (($hc['jobs'] ?? []) as $j) if ($j['job'] === 'pending_messages_tick') $row = $j;

is_ok($row !== null, 'the sweep appears on the Status page');
is_ok($row && $row['required'] === true,
      'with notifications queued, the sweep is reported as REQUIRED');
is_ok($row && stripos($row['required_why'], 'notification') !== false,
      'and the reason says notifications specifically: "' . ($row['required_why'] ?? '') . '"');
is_ok(isset($hc['notifications']['pending']) && $hc['notifications']['pending'] > 0,
      'the notification queue depth is reported as its own fact');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 6. Nothing is lost: the sweep delivers what the request queued --\n";

$depth = notify_queue_depth();
is_ok($depth['pending'] > 0, 'there is something queued to drain');

// Take the dead endpoint away — the network is "back".
db_query("DELETE FROM `{$prefix}webhook_subscriptions` WHERE target_url = ?", [$BLACKHOLE_WEBHOOK]);
notify_fanout_forget_channel_cache();
notify_breaker_reset();

$t0 = microtime(true);
$sweep = pending_sweep(null, null, 200, null, NOTIFY_FANOUT_CHANNEL);
$sweepS = microtime(true) - $t0;
printf("   pending_sweep(): %.2fs considered=%d sent=%d failed=%d\n",
       $sweepS, $sweep['considered'], $sweep['sent'], $sweep['failed']);

is_ok($sweep['considered'] > 0, 'the sweep found the queued notifications');
is_ok($sweep['sent'] > 0, 'and delivered them');
$depthAfter = notify_queue_depth();
is_ok($depthAfter['pending'] < $depth['pending'],
      'the queue drained (' . $depth['pending'] . ' -> ' . $depthAfter['pending'] . ')');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 7. No scheduler at all: bounded attempt, then the breaker --\n";
echo "   (both live TicketsCAD servers had NO cron daemon in July 2026, so\n";
echo "    this is not a hypothetical configuration)\n";

// Put the dead endpoint back and take the heartbeat away.
db_query("INSERT INTO `{$prefix}webhook_subscriptions`
             (name, target_url, hmac_secret, event_filters_json, active, created_at)
          VALUES ('notify-fanout-test', ?, 'test-secret', '[\"*\"]', 1, NOW())",
         [$BLACKHOLE_WEBHOOK]);
notify_fanout_forget_channel_cache();
db_query("UPDATE `{$prefix}scheduled_job_runs`
             SET last_ok_at = NULL, last_run_at = NULL WHERE job_key = 'pending_messages_tick'");
notify_breaker_reset();

is_ok(notify_scheduler_is_live() === false,
      'with no successful run recorded, the scheduler is correctly judged dead');

$worstProbe = 0.0;
for ($i = 0; $i < 3; $i++) {
    $r = drive_dispatch($type, $unit, $actor, $createdTickets);
    $worst = max($r['create'], $r['status']);
    printf("   cycle %d: create=%.2fs status=%.2fs  breaker=%s\n",
           $i + 1, $r['create'], $r['status'],
           notify_breaker_status()['open'] ? 'OPEN' : 'closed');
    if ($i === 0) $worstProbe = $worst;
    $lastWorst = $worst;
}

is_ok($worstProbe < DISPATCH_BUDGET_S,
      sprintf('even the first probe stayed under the %.1fs budget (%.2fs)', DISPATCH_BUDGET_S, $worstProbe));
$bk = notify_breaker_status();
is_ok($bk['open'] === true,
      'after repeated failures the breaker opened — ' . $bk['reason']);
is_ok(isset($lastWorst) && $lastWorst < 1.0,
      sprintf('with the breaker open a dispatch action costs %.2fs — no network at all', $lastWorst ?? -1));

$depthNoSched = notify_queue_depth();
is_ok($depthNoSched['pending'] > 0,
      'the undelivered notifications are still in the queue, not lost ('
      . $depthNoSched['pending'] . ' waiting)');

$hc2 = health_check_scheduled_jobs();
$row2 = null;
foreach (($hc2['jobs'] ?? []) as $j) if ($j['job'] === 'pending_messages_tick') $row2 = $j;
is_ok($row2 && $row2['severity'] === 'critical',
      'the Status page is CRITICAL: notifications are queued and nothing is draining them');
is_ok($row2 && stripos($row2['required_why'], 'paused') !== false,
      'and it reports the paused delivery: "' . substr($row2['required_why'] ?? '', 0, 120) . '"');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 7b. The timer-driven sweep respects the breaker too --\n";

// Without this the sweep would walk the whole backlog paying a full timeout
// for every row, every minute, for the entire outage. The breaker is checked
// once per sweep — not once per row — and the half-open window is what lets a
// later tick discover the link is back.
is_ok(notify_breaker_status()['open'] === true, 'the breaker is open going in');
$depthBefore = notify_queue_depth()['pending'];
$t0 = microtime(true);
$swept = pending_sweep(null, null, 200, null, NOTIFY_FANOUT_CHANNEL);
$sweptS = microtime(true) - $t0;
printf("   sweep with the breaker open: %.2fs considered=%d deferred=%d\n",
       $sweptS, $swept['considered'], $swept['deferred'] ?? -1);

is_ok(($swept['deferred'] ?? 0) > 0,
      'rows were deferred rather than attempted (' . ($swept['deferred'] ?? 0) . ')');
is_ok($swept['considered'] === 0,
      'nothing was attempted — no socket opened for any of them');
is_ok($sweptS < 1.0,
      sprintf('so a whole backlog costs %.2fs instead of one timeout per row', $sweptS));
is_ok(notify_queue_depth()['pending'] === $depthBefore,
      'and the queue is untouched — deferred is not dropped');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 8. Recursion is bounded by construction, not by a counter --\n";

// Delivering a push forwards through the routing engine, which publishes a
// `routing:forwarded` SSE event, which comes back to the fan-out. That
// nested call is webhook-only, and a webhook-only row never re-enters the
// router — so the chain is two deep and cannot grow.
$w = notify_fanout_decode(_notify_encode_payload('routing:forwarded', [], ['webhook']));
is_ok($w && !in_array('push', $w['channels'], true),
      'an SSE-originated row carries no push leg, so it cannot re-enter the router');

$GLOBALS['_notify_fanout_draining'] = true;
$nested = notify_fanout_dispatch('sse.test.nested', ['summary' => 'nested'], ['webhook']);
unset($GLOBALS['_notify_fanout_draining']);
is_ok($nested['action'] === 'queued',
      'an event raised INSIDE a drain is still queued — deferred, never dropped');
is_ok($nested['elapsed'] < 0.5,
      sprintf('and it returns immediately (%.3fs), attempting no delivery', $nested['elapsed']));

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 9. A default install does no work at all --\n";

// No webhook subscriber, push off: there is nothing that could be delivered,
// so nothing should be written to the queue. sse_publish() runs on every chat
// line; a queue row per event on an install with no outbound channel would be
// pure cost.
db_query("DELETE FROM `{$prefix}webhook_subscriptions` WHERE target_url = ?", [$BLACKHOLE_WEBHOOK]);
notify_fanout_forget_channel_cache();
$pushOn = (string) db_fetch_value(
    "SELECT value FROM `{$prefix}settings` WHERE name = 'push_enabled' LIMIT 1") === '1';
$otherHooks = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}webhook_subscriptions` WHERE active = 1");
if ($pushOn || $otherHooks > 0) {
    skip('this install has push or webhooks configured — the no-op path is not assertable here');
} else {
    $noop = notify_fanout_dispatch('incident.created', ['summary' => 'x']);
    is_ok($noop['action'] === 'noop',
          'with nothing configured the fan-out is a no-op, not a queue write');
    is_ok($noop['elapsed'] < 0.2,
          sprintf('and it costs %.3fs', $noop['elapsed']));
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 10. Tidy up the incidents this test created --\n";
$removed = 0;
foreach (array_unique($createdTickets) as $tid) {
    if ($tid <= 0) continue;
    try {
        db_query("DELETE FROM `{$prefix}assigns` WHERE ticket_id = ?", [$tid]);
        db_query("DELETE FROM `{$prefix}action`  WHERE ticket_id = ?", [$tid]);
        db_query("DELETE FROM `{$prefix}ticket`  WHERE id = ?", [$tid]);
        $removed++;
    } catch (Exception $e) {}
}
$left = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}ticket` WHERE scope LIKE 'NOTIFY-FANOUT TEST%'");
is_ok($left === 0, "removed {$removed} test incident(s); none left behind");

done();
