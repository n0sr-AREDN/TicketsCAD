<?php
/**
 * Phase 132 (2026-08-03) — Structured incident disposition, Step 2:
 * writer + API enforcement gate, NO UI. See
 * specs/phase-132-incident-disposition/{spec.md,plan.md,tasks.md}.
 * Step 1's schema/seed/RBAC test (tests/test_phase132_migration.php) is
 * untouched and separate.
 *
 * Everything here drives the REAL writer functions — never hand-seeds
 * ticket_disposition or ticket state the writers themselves would
 * produce. This project has been bitten repeatedly by tests that pass
 * because they hand-built state the real writer never produces (CLAUDE.md:
 * "reproduce bugs through the REAL creation path", the GH #20 bed
 * automation episodes, the Phase 116 rec_facility_id saga). Ticket/
 * responder/disposition ROWS are still created with a direct INSERT
 * (mirroring tests/test_stranded_assigns.php's fixture style) — that's
 * fixture setup, not the thing under test. What IS under test —
 * disposition validation, the close-enforcement gate, and the auto-close
 * exemption — is exercised exclusively through
 * incident_set_disposition_internal(), incident_update_status_internal(),
 * and auto_close_sweep().
 *
 * WHY A COLD-PROCESS PROBE FOR THE CLOSE TESTS (tests/_p132_probe.php):
 * get_variable() caches every `settings` row in a function-static array on
 * its FIRST call and never re-reads the table for the rest of the
 * process (inc/functions.php). This test needs
 * disposition_required_on_close to be '1' for some assertions and '0' for
 * others, in the same run — a plain UPDATE + in-process re-call would
 * silently read the stale cached value instead of what was just written.
 * tests/_par_setting_probe.php (Phase 129) hit the identical problem for
 * par_include_unavailable_units and solved it the same way: shell out to
 * a fresh `php` interpreter per read. incident_set_disposition_internal()
 * itself never calls get_variable(), so the "set on an open incident"
 * tests below run in-process without needing the probe.
 *
 * Leaves disposition_required_on_close reset to '0' on every exit path
 * (register_shutdown_function — the only mechanism that also fires on a
 * fatal error partway through) so a run of this file never poisons every
 * OTHER test file's close behaviour for the rest of the shared dev DB's
 * lifetime.
 *
 * Usage: php tests/test_phase132_writer.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/../inc/auto_close.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  PASS: $m\n"; }
function bad(string $m, string $hint = ''): void {
    global $fail; $fail++; echo "  FAIL: $m" . ($hint !== '' ? " — $hint" : '') . "\n";
}
function chk($cond, string $m, string $hint = ''): void { $cond ? ok($m) : bad($m, $hint); }

echo "\n=== Phase 132 — Incident disposition writer + enforcement (Step 2) ===\n";

$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) {
    echo "\nSKIP: no database available — this test needs one\n";
    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

$prefix = $GLOBALS['db_prefix'] ?? '';
$userId = test_admin_user_id();

// ─────────────────────────────────────────────────────────────────────────
// Setup: sweep any leftovers from an aborted prior run, load fixtures.
// ─────────────────────────────────────────────────────────────────────────
$SCOPE_MARK = '__P132_ Test Incident';
$DISP_MARK  = '__p132_test_';

foreach (db_fetch_all("SELECT `id` FROM `{$prefix}ticket` WHERE `scope` = ?", [$SCOPE_MARK]) as $old) {
    db_query("DELETE FROM `{$prefix}assigns` WHERE `ticket_id` = ?", [$old['id']]);
    db_query("DELETE FROM `{$prefix}action` WHERE `ticket_id` = ?", [$old['id']]);
    db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE `target_type` = 'ticket' AND `target_id` = ?",
        [(string) $old['id']]);
    db_query("DELETE FROM `{$prefix}ticket` WHERE `id` = ?", [$old['id']]);
}
db_query("DELETE FROM `{$prefix}ticket_disposition` WHERE `code` LIKE ?", [$DISP_MARK . '%']);

$typeId = (int) db_fetch_value("SELECT `id` FROM `{$prefix}in_types` ORDER BY `id` LIMIT 1");
chk($typeId > 0, 'fixture: an in_types row exists to build test tickets against');

$resolvedId = (int) db_fetch_value(
    "SELECT `id` FROM `{$prefix}ticket_disposition` WHERE `code` = 'resolved' AND `org_id` IS NULL LIMIT 1");
$unfoundedId = (int) db_fetch_value(
    "SELECT `id` FROM `{$prefix}ticket_disposition` WHERE `code` = 'unfounded' AND `org_id` IS NULL LIMIT 1");
chk($resolvedId > 0 && $unfoundedId > 0,
    'fixture: Step 1\'s seeded "resolved"/"unfounded" dispositions exist — run sql/run_phase132_disposition.php',
    "resolved={$resolvedId} unfounded={$unfoundedId}");

if ($typeId <= 0 || $resolvedId <= 0 || $unfoundedId <= 0) {
    echo "\nFATAL: prerequisite fixtures missing, cannot continue.\n";
    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit(1);
}

/** Direct-INSERT ticket fixture (mirrors tests/test_stranded_assigns.php). */
function p132_mk_ticket(int $status): int {
    global $prefix, $typeId, $SCOPE_MARK;
    $now = date('Y-m-d H:i:s');
    db_query(
        "INSERT INTO `{$prefix}ticket` (`in_types_id`, `status`, `severity`, `scope`, `description`,
         `date`, `problemstart`, `_by`)
         VALUES (?, ?, 0, ?, 'phase132 writer test fixture', ?, ?, 1)",
        [$typeId, $status, $SCOPE_MARK, $now, $now]
    );
    return (int) db_insert_id();
}

/** Direct-INSERT disposition fixture, code prefixed for cleanup. */
function p132_mk_disposition(string $suffix, int $active): int {
    global $prefix, $DISP_MARK;
    db_query(
        "INSERT INTO `{$prefix}ticket_disposition`
            (`status_val`, `description`, `code`, `discipline`, `org_id`, `sort_order`, `requires_comment`, `active`)
         VALUES (?, '', ?, '', NULL, 99, 0, ?)",
        ['P132 Test ' . $suffix, $DISP_MARK . $suffix, $active]
    );
    return (int) db_insert_id();
}

function p132_ticket_status(int $ticketId): int {
    global $prefix;
    return (int) db_fetch_value("SELECT `status` FROM `{$prefix}ticket` WHERE `id` = ?", [$ticketId]);
}
function p132_ticket_disposition(int $ticketId) {
    global $prefix;
    $v = db_fetch_value("SELECT `disposition_id` FROM `{$prefix}ticket` WHERE `id` = ?", [$ticketId]);
    return ($v === null || $v === false) ? null : (int) $v;
}
function p132_audit_count(int $ticketId): int {
    global $prefix;
    return (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}newui_audit_log`
          WHERE `category` = 'incident' AND `activity` = 'disposition_set'
            AND `target_type` = 'ticket' AND `target_id` = ?",
        [(string) $ticketId]
    );
}

/** Set disposition_required_on_close, bypassing get_variable's cache concerns
 *  by writing straight to the settings table (the probe re-reads it fresh). */
function p132_set_enforcement(string $value): void {
    global $prefix;
    db_query(
        "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('disposition_required_on_close', ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$value]
    );
}

/** Shell out to a fresh PHP process for a close attempt — see file header
 *  docblock for why this must be a cold process. */
function p132_probe_close(int $ticketId, ?int $dispositionId = null, bool $skip = false): ?array {
    $php = PHP_BINARY ?: 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/_p132_probe.php')
         . ' close ' . escapeshellarg((string) $ticketId)
         . ' ' . escapeshellarg($dispositionId !== null ? (string) $dispositionId : '')
         . ' ' . escapeshellarg($skip ? '1' : '')
         . ' 2>&1';
    $out = @shell_exec($cmd);
    if ($out === null) return null;
    $j = json_decode(trim((string) $out), true);
    return is_array($j) ? $j : null;
}

/** Shell out to a fresh PHP process for auto_close_sweep(). */
function p132_probe_autoclose_sweep(): ?array {
    $php = PHP_BINARY ?: 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/_p132_probe.php')
         . ' autoclose_sweep 2>&1';
    $out = @shell_exec($cmd);
    if ($out === null) return null;
    $j = json_decode(trim((string) $out), true);
    return is_array($j) ? $j : null;
}

// Always leave enforcement OFF, no matter how this file exits (fatal error,
// early exit(), or a normal finish). This is the ONLY mechanism that also
// fires on a fatal error partway through the run.
register_shutdown_function(function () {
    global $prefix;
    try {
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('disposition_required_on_close', '0')
             ON DUPLICATE KEY UPDATE `value` = '0'"
        );
    } catch (Throwable $e) { /* best effort — this is the last line of defense */ }
});

// Start from a known state.
p132_set_enforcement('0');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 1. Setting a disposition on an OPEN incident succeeds regardless of enforcement --\n";
// ─────────────────────────────────────────────────────────────────────────

$t1 = p132_mk_ticket(2); // open

p132_set_enforcement('1');
$r1 = incident_set_disposition_internal($t1, $resolvedId, $userId);
chk(!empty($r1['updated']) && empty($r1['errors']),
    'set disposition on an OPEN incident succeeds with enforcement ON', var_export($r1, true));
chk(p132_ticket_disposition($t1) === $resolvedId,
    'ticket.disposition_id reflects the write (enforcement ON case)');
chk(p132_ticket_status($t1) === 2, 'the incident is still OPEN — setting a disposition does not close it');

p132_set_enforcement('0');
$r2 = incident_set_disposition_internal($t1, $unfoundedId, $userId);
chk(!empty($r2['updated']) && empty($r2['errors']),
    'set disposition on an OPEN incident succeeds with enforcement OFF', var_export($r2, true));
chk(p132_ticket_disposition($t1) === $unfoundedId,
    'ticket.disposition_id reflects the second write (enforcement OFF case)');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. Close is REFUSED with no disposition when enforcement is ON --\n";
// ─────────────────────────────────────────────────────────────────────────

$t2 = p132_mk_ticket(2); // open, no disposition
p132_set_enforcement('1');
$closeRes2 = p132_probe_close($t2);
chk($closeRes2 !== null, 'probe returned parseable JSON', 'raw=' . var_export($closeRes2, true));
if ($closeRes2 !== null) {
    chk(empty($closeRes2['updated']), 'close is refused (updated=false)', var_export($closeRes2, true));
    chk(!empty($closeRes2['errors']), 'a validation error is returned');
    if (!empty($closeRes2['errors'])) {
        chk(stripos((string) $closeRes2['errors'][0], 'disposition') !== false,
            'the error names disposition, not a generic failure', (string) $closeRes2['errors'][0]);
    }
}
chk(p132_ticket_status($t2) === 2, 'the incident remains OPEN — the refused close did not touch status');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. Close SUCCEEDS with no disposition when enforcement is OFF --\n";
// ─────────────────────────────────────────────────────────────────────────

$t3 = p132_mk_ticket(2); // open, no disposition
p132_set_enforcement('0');
$closeRes3 = p132_probe_close($t3);
chk($closeRes3 !== null, 'probe returned parseable JSON', var_export($closeRes3, true));
if ($closeRes3 !== null) {
    chk(!empty($closeRes3['updated']) && empty($closeRes3['errors']),
        'close succeeds with enforcement OFF and no disposition', var_export($closeRes3, true));
}
chk(p132_ticket_status($t3) === 1, 'the incident is now CLOSED');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. Close with a valid disposition_id passed inline (enforcement ON) --\n";
// ─────────────────────────────────────────────────────────────────────────

$t4 = p132_mk_ticket(2); // open, no disposition yet
p132_set_enforcement('1');
$closeRes4 = p132_probe_close($t4, $resolvedId);
chk($closeRes4 !== null, 'probe returned parseable JSON', var_export($closeRes4, true));
if ($closeRes4 !== null) {
    chk(!empty($closeRes4['updated']) && empty($closeRes4['errors']),
        'a valid disposition_id passed alongside the close satisfies enforcement', var_export($closeRes4, true));
}
chk(p132_ticket_status($t4) === 1, 'the incident is now CLOSED');
chk(p132_ticket_disposition($t4) === $resolvedId, 'the disposition was written atomically with the close');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 5. auto_close.php's real sweep is EXEMPT from enforcement --\n";
// ─────────────────────────────────────────────────────────────────────────
// Driven through the REAL scheduled-job entry point (auto_close_sweep()),
// not by calling incident_update_status_internal() directly and pretending
// that's the same thing — this is the whole point of the exemption.

$t5 = p132_mk_ticket(2); // open, no disposition, no assigns (all-clear)
db_query(
    "UPDATE `{$prefix}ticket` SET `auto_close_scheduled_at` = DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE `id` = ?",
    [$t5]
);
p132_set_enforcement('1');
$sweepRes = p132_probe_autoclose_sweep();
chk($sweepRes !== null, 'sweep probe returned parseable JSON', var_export($sweepRes, true));
chk(p132_ticket_status($t5) === 1,
    'the real auto_close_sweep() closed the due incident even with disposition_required_on_close=1',
    'status=' . p132_ticket_status($t5) . ' sweep=' . var_export($sweepRes, true));

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 6. A retired disposition cannot be newly assigned, but stays readable --\n";
// ─────────────────────────────────────────────────────────────────────────

$retireId = p132_mk_disposition('retiree', 1); // active
$t6 = p132_mk_ticket(2);
$setBeforeRetire = incident_set_disposition_internal($t6, $retireId, $userId);
chk(!empty($setBeforeRetire['updated']),
    'an ACTIVE disposition can be assigned', var_export($setBeforeRetire, true));
chk(p132_ticket_disposition($t6) === $retireId, 'ticket now carries it');

// Retire it.
db_query("UPDATE `{$prefix}ticket_disposition` SET `active` = 0 WHERE `id` = ?", [$retireId]);

// Readback AFTER retiring — must be unchanged. This is the assertion that
// matters: retirement must never rewrite an incident that already has it.
chk(p132_ticket_disposition($t6) === $retireId,
    'the incident STILL reads back the now-retired disposition, unchanged');

// A DIFFERENT ticket trying to newly assign the now-retired disposition
// must be rejected.
$t7 = p132_mk_ticket(2);
$setAfterRetire = incident_set_disposition_internal($t7, $retireId, $userId);
chk(empty($setAfterRetire['updated']),
    'a RETIRED disposition is rejected for a NEW assignment', var_export($setAfterRetire, true));
chk(!empty($setAfterRetire['errors']) && stripos((string) $setAfterRetire['errors'][0], 'retired') !== false,
    'the rejection names it as retired', var_export($setAfterRetire['errors'] ?? null, true));
chk(p132_ticket_disposition($t7) === null,
    'the other ticket never got the retired disposition written');

// A close attempt that passes a retired disposition_id must also be
// refused — same validation, reached from the close path.
p132_set_enforcement('0'); // isolate: test the invalid-disposition rejection, not the enforcement gate
$t7b = p132_mk_ticket(2);
$closeRetired = p132_probe_close($t7b, $retireId);
chk($closeRetired !== null, 'probe returned parseable JSON', var_export($closeRetired, true));
if ($closeRetired !== null) {
    chk(empty($closeRetired['updated']),
        'closing with a retired disposition_id is refused even with enforcement OFF',
        var_export($closeRetired, true));
}
chk(p132_ticket_status($t7b) === 2, 'the incident was NOT closed');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 7. audit_log gets a row for EVERY disposition change, including a no-op re-set --\n";
// ─────────────────────────────────────────────────────────────────────────

$t8 = p132_mk_ticket(2);
chk(p132_audit_count($t8) === 0, 'no disposition audit rows yet for this fresh ticket');

$set1 = incident_set_disposition_internal($t8, $resolvedId, $userId);
chk(!empty($set1['updated']), 'first set succeeds', var_export($set1, true));
chk(p132_audit_count($t8) === 1, 'exactly one audit_log row after the first set');

// Re-set to the SAME value — must still write a NEW row, not dedupe.
$set2 = incident_set_disposition_internal($t8, $resolvedId, $userId);
chk(!empty($set2['updated']), 're-set to the same value still succeeds', var_export($set2, true));
chk(p132_audit_count($t8) === 2,
    'a SECOND audit_log row exists after re-setting the SAME value — not deduped to one');

// A genuine change adds a third row.
$set3 = incident_set_disposition_internal($t8, $unfoundedId, $userId);
chk(!empty($set3['updated']), 'changing to a different value succeeds', var_export($set3, true));
chk(p132_audit_count($t8) === 3, 'a THIRD audit_log row exists after changing to a different value');

// ─────────────────────────────────────────────────────────────────────────
// Cleanup + final enforcement reset (register_shutdown_function is the
// backstop; do it explicitly here too on the normal-exit path).
// ─────────────────────────────────────────────────────────────────────────
p132_set_enforcement('0');

foreach (db_fetch_all("SELECT `id` FROM `{$prefix}ticket` WHERE `scope` = ?", [$SCOPE_MARK]) as $t) {
    db_query("DELETE FROM `{$prefix}assigns` WHERE `ticket_id` = ?", [$t['id']]);
    db_query("DELETE FROM `{$prefix}action` WHERE `ticket_id` = ?", [$t['id']]);
    db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE `target_type` = 'ticket' AND `target_id` = ?",
        [(string) $t['id']]);
    db_query("DELETE FROM `{$prefix}ticket` WHERE `id` = ?", [$t['id']]);
}
db_query("DELETE FROM `{$prefix}ticket_disposition` WHERE `code` LIKE ?", [$DISP_MARK . '%']);

$stillEnforced = db_fetch_value(
    "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'disposition_required_on_close'");
chk((string) $stillEnforced === '0',
    'disposition_required_on_close was reset to 0 before exit — later tests are not affected',
    var_export($stillEnforced, true));

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
