<?php
/**
 * Phase 16a — PAR foundation tests.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/par.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Phase 16a — PAR foundation ===\n\n";
$pass = 0; $fail = 0;
function ok($n) { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad($n, $w='') { global $fail; echo "[FAIL] $n" . ($w?" — $w":'') . "\n"; $fail++; }

// ── Schema ──────────────────────────────────────────────────────────
foreach (['par_cycles','par_unit_acks','par_config'] as $t) {
    try {
        $r = db_fetch_one(
            "SELECT TABLE_NAME FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$prefix . $t]
        );
        if ($r) ok("table {$t} exists");
        else    bad("table {$t} missing");
    } catch (Exception $e) { bad("table {$t}: " . $e->getMessage()); }
}

foreach (['par_cadence_override_min','par_last_cycle_at'] as $col) {
    try {
        $r = db_fetch_one(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$prefix . 'ticket', $col]
        );
        if ($r) ok("ticket.{$col} present");
        else    bad("ticket.{$col} missing");
    } catch (Exception $e) { bad($col . ': ' . $e->getMessage()); }
}

// ── par_enabled defaults to false ───────────────────────────────────
db_query(
    "INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('par_enabled','0')
     ON DUPLICATE KEY UPDATE value = VALUES(value)"
);
if (par_enabled() === false) ok('par_enabled() returns false when setting=0');
else                         bad('par_enabled() should be false');

db_query(
    "INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('par_enabled','1')
     ON DUPLICATE KEY UPDATE value = VALUES(value)"
);
if (par_enabled() === true) ok('par_enabled() returns true when setting=1');
else                        bad('par_enabled() should be true');

// ── par_resolve_cadence — layered resolution ────────────────────────
// ALWAYS create our own sentinel ticket. Borrowing "the oldest existing
// ticket" (the pre-2026-07-07 behavior) made this test order-dependent in
// the full suite: whatever state an earlier test's leftover ticket carried
// leaked into the cadence/cycle assertions, and our par_cadence_override /
// assigns writes polluted that foreign ticket in return.
db_query(
    "INSERT INTO `{$prefix}ticket` (in_types_id, scope, description, status, `date`)
     VALUES (1, 'phase16a sentinel', '', 0, NOW())"
);
$ticketId = (int) db_insert_id();
$isSentinel = true;

$cad = par_resolve_cadence($ticketId);
if ($cad['cadence_minutes'] > 0 && isset($cad['source'])) {
    ok("resolve_cadence returns positive cadence ({$cad['cadence_minutes']} min, source={$cad['source']})");
} else {
    bad('resolve_cadence', var_export($cad, true));
}

// Per-incident override should win
db_query(
    "UPDATE `{$prefix}ticket` SET par_cadence_override_min = 5 WHERE id = ?",
    [$ticketId]
);
$cad2 = par_resolve_cadence($ticketId);
if ($cad2['cadence_minutes'] === 5 && $cad2['source'] === 'incident_override') {
    ok('resolve_cadence: per-incident override wins');
} else {
    bad('per-incident override', var_export($cad2, true));
}
// Phase 30A (2026-06-12) — par_due_at requires BOTH explicit cadence
// opt-in AND at least one assigned unit. Re-apply the override AND
// assign a unit before testing par_due_at returns a timestamp.
db_query(
    "UPDATE `{$prefix}ticket` SET par_cadence_override_min = 5 WHERE id = ?",
    [$ticketId]
);

// ── ALWAYS create our own sentinel unit ─────────────────────────────
// 2026-08-03: this used to borrow "whatever responder sorts first"
// (ORDER BY id LIMIT 1) — the same shortcut the ticket above was already
// weaned off, and it made this file fail in the full suite while passing
// standalone. The chain:
//
//   1. tests/test_notify_fanout.php borrows the SAME lowest-id responder,
//      and leaves it on the `available` status (the close cascade in
//      incident_clear_stragglers() resets units to Available).
//   2. This file then assigned that unit and asserted par_due_at()
//      returns a timestamp.
//   3. par_due_at() -> par_assigned_units(), which under the default
//      `par_standby_unit_behavior = recommended` deliberately drops units
//      whose status reads as standby/staging/AVAILABLE — a unit sitting
//      Available is not committed to the incident and has nothing to
//      account for. So the PAR roster was empty and par_due_at()
//      correctly returned null.
//   4. tools/test_par_assigned_units.php — the one file that forces that
//      shared unit back onto a non-standby status — cannot help, because
//      the runner finishes every tests/*.php before it starts tools/*.php.
//
// The assertion below was never wrong; the setup never established the
// precondition the function documents. So: our own unit, on a status we
// chose, deleted afterwards. Nothing else in the suite can move it, and
// this file can no longer be the cause of someone else's flake either.
$sentinelStatusId = (int) db_fetch_value(
    "SELECT id FROM `{$prefix}un_status`
      WHERE LOWER(status_val) NOT LIKE '%standby%'
        AND LOWER(status_val) NOT LIKE '%staging%'
        AND LOWER(status_val) NOT LIKE '%avail%'
        AND LOWER(status_val) NOT LIKE '%offduty%'
        AND LOWER(status_val) NOT LIKE '%off duty%'
        AND LOWER(status_val) NOT LIKE '%reserve%'
        AND (hide IS NULL OR hide <> 'y')
      ORDER BY sort, id LIMIT 1"
);
$sentinelStatusCreated = 0;
if ($sentinelStatusId <= 0) {
    // No usable status on this install (or they have all been renamed by
    // an earlier test). Make one; status_val and description are both
    // NOT NULL without a default.
    db_query(
        "INSERT INTO `{$prefix}un_status` (status_val, description)
         VALUES ('p16a_committed', 'PAR test sentinel status')"
    );
    $sentinelStatusId = (int) db_insert_id();
    $sentinelStatusCreated = $sentinelStatusId;
}

// Verified against sql/base_schema.sql: responder has `name` (text NULL)
// and `description` (text NOT NULL, no default — must be included).
// There are NO _by/_from/_on audit columns on responder.
db_query(
    "INSERT INTO `{$prefix}responder` (`name`, `description`, `un_status_id`)
     VALUES ('phase16a sentinel unit', 'PAR test sentinel', ?)",
    [$sentinelStatusId]
);
$sentinelResponderId = (int) db_insert_id();
$respIdForDueAt      = $sentinelResponderId;

db_query(
    "INSERT INTO `{$prefix}assigns` (ticket_id, responder_id, user_id, dispatched)
     VALUES (?, ?, 1, NOW())",
    [$ticketId, $respIdForDueAt]
);

// State the precondition as its own assertion. par_due_at() has four
// independent null gates, and "the roster came back empty" is the one
// that took longest to identify from the outside.
if (count(par_assigned_units($ticketId)) === 1) {
    ok('sentinel unit is on the PAR roster (precondition for par_due_at)');
} else {
    bad('sentinel unit not on PAR roster', 'status_id=' . $sentinelStatusId
        . ' responder=' . $respIdForDueAt);
}

// ── par_due_at returns a sensible timestamp ─────────────────────────
$due = par_due_at($ticketId);
if (is_int($due) && $due > 0) {
    ok("par_due_at returns timestamp {$due}");
} else {
    // par_due_at() has FOUR independent null-return gates. A bare
    // "par_due_at — NULL" names none of them, which is why this
    // assertion went unexplained for as long as it did. Say which gate.
    $why = [];
    $why[] = 'par_enabled=' . var_export(par_enabled(), true);
    $c = par_resolve_cadence($ticketId);
    $why[] = 'cadence=' . (int) $c['cadence_minutes'] . ' source=' . $c['source'];
    $units = par_assigned_units($ticketId);
    $why[] = 'assigned_units=' . count($units);
    $rawAssigns = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}assigns` WHERE ticket_id = ?", [$ticketId]);
    $why[] = 'raw_assigns_rows=' . $rawAssigns;
    $st = db_fetch_one(
        "SELECT r.id, r.un_status_id, s.status_val
           FROM `{$prefix}assigns` a
           JOIN `{$prefix}responder` r ON r.id = a.responder_id
           LEFT JOIN `{$prefix}un_status` s ON s.id = r.un_status_id
          WHERE a.ticket_id = ? LIMIT 1", [$ticketId]);
    $why[] = 'assigned_responder=' . var_export($st, true);
    $why[] = 'standby_behavior=' . var_export(
        db_fetch_value("SELECT value FROM `{$prefix}settings`
                         WHERE name='par_standby_unit_behavior' LIMIT 1"), true);
    bad('par_due_at', var_export($due, true) . ' | ' . implode('; ', $why));
}

// ── par_initiate_cycle + par_ack_unit ────────────────────────────────
$result = par_initiate_cycle($ticketId, 'manual', null, 'test');
if (isset($result['cycle']) && (int) $result['cycle']['ticket_id'] === $ticketId &&
    $result['cycle']['status'] === 'pending') {
    ok("initiate_cycle: created pending cycle for ticket {$ticketId}");
    $cycleId = (int) $result['cycle']['id'];

    // Confirm par_last_cycle_at was stamped
    $last = db_fetch_value(
        "SELECT par_last_cycle_at FROM `{$prefix}ticket` WHERE id = ?", [$ticketId]);
    if ($last !== null && $last !== '0000-00-00 00:00:00') {
        ok('initiate_cycle stamps ticket.par_last_cycle_at');
    } else {
        bad('par_last_cycle_at not stamped');
    }
} else {
    bad('initiate_cycle', var_export($result, true));
    $cycleId = 0;
}

// abort the cycle (we don't have a real assigned unit so ack is trivial)
if ($cycleId > 0) {
    if (par_abort_cycle($cycleId, null, 'test cleanup')) {
        ok('abort_cycle: marked aborted');
        $row = db_fetch_one("SELECT status FROM `{$prefix}par_cycles` WHERE id = ?", [$cycleId]);
        if ($row && $row['status'] === 'aborted') ok('abort_cycle persists status=aborted');
        else                                     bad('abort status', var_export($row, true));
    } else {
        bad('abort_cycle returned false');
    }
}

// ── Cleanup ──────────────────────────────────────────────────────────
// Remove the assigns row created for par_due_at, any PAR cycles/acks for
// the sentinel, then the sentinel ticket itself.
try {
    db_query("DELETE FROM `{$prefix}assigns` WHERE ticket_id = ?", [$ticketId]);
    // Column verified against sql/run_phase16a_par_schema.php: par_cycle_id.
    db_query("DELETE FROM `{$prefix}par_unit_acks` WHERE par_cycle_id IN
              (SELECT id FROM `{$prefix}par_cycles` WHERE ticket_id = ?)", [$ticketId]);
    db_query("DELETE FROM `{$prefix}par_cycles` WHERE ticket_id = ?", [$ticketId]);
    if (!empty($sentinelResponderId)) {
        db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$sentinelResponderId]);
    }
    if (!empty($sentinelStatusCreated)) {
        db_query("DELETE FROM `{$prefix}un_status` WHERE id = ?", [$sentinelStatusCreated]);
    }
} catch (Exception $e) {
    echo "  (cleanup warning: " . $e->getMessage() . ")\n";
}
if ($isSentinel) {
    db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$ticketId]);
}
// Restore par_enabled to off
db_query(
    "INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('par_enabled','0')
     ON DUPLICATE KEY UPDATE value = VALUES(value)"
);
ok('cleanup');

echo "\n===========================================\n";
echo "Phase 16a PAR: {$pass} passed, {$fail} failed\n";
echo "===========================================\n";
if ($fail > 0) exit(1);
