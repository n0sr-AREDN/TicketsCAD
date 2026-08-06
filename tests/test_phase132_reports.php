<?php
/**
 * Phase 132 (2026-08-04) — Structured incident disposition, Step 5:
 * reports, export, feed. See
 * specs/phase-132-incident-disposition/{spec.md,plan.md,tasks.md}.
 * Steps 1-4 (migration/seeds, writer/API enforcement, Settings panel,
 * incident-detail dropdowns) are untouched and separately tested.
 *
 * What Step 5 actually added, and what this file proves:
 *   - api/reports.php's 'incident_summary' case gained a SEPARATE
 *     disposition breakdown (a new `disposition_breakdown` response key,
 *     alongside — not replacing — the existing incident-type
 *     columns/rows/summary), grouped with COALESCE(status_val,
 *     'No Disposition') so the NULL bucket (every historical/
 *     undispositioned incident) is counted, not dropped.
 *   - inc/import-export.php's `incident` export target gained
 *     `disposition_code`, resolving `ticket_disposition`.`code` via a new
 *     `joins` mechanism (export_csv() now understands a per-column `sql`
 *     override + a config-level `joins` list) — the existing `legacy`
 *     alias only re-points to another column of the SAME table, which
 *     can't reach a JOINed value.
 *   - api/feed.php's open-incidents SELECT gained `disposition_code` via
 *     a LEFT JOIN to `ticket_disposition`.
 *
 * WHY THIS FILE DUPLICATES SQL TEXT INSTEAD OF CALLING A SHARED FUNCTION:
 * Unlike inc/incident-write.php / inc/disposition-admin.php (Steps 2-4),
 * api/reports.php and api/feed.php have never had their report/feed logic
 * factored into a testable function — every existing test that touches
 * either file (tests/test_security_idor_sweep.php,
 * tests/test_security_f002_feed.php) asserts against the file's SOURCE
 * TEXT, not live execution, because both files are request-handling
 * endpoints that run auth/parameter/output side effects unconditionally
 * from the moment they're require()'d. Introducing a live-HTTP or
 * full-file-require harness here would be a new testing pattern this
 * codebase doesn't otherwise use for these two files. Per the task
 * brief's own sanctioned fallback, the disposition-breakdown query and
 * the feed's disposition_code JOIN+column are reproduced byte-for-byte
 * below (kept in sync by eye with api/reports.php / api/feed.php — if
 * either query changes, update the matching copy here) and run directly
 * against the live dev database, so what's under test is the actual SQL
 * shape, not a hand-waved re-derivation of it.
 *
 * The export half is different: export_csv() (inc/import-export.php) IS
 * a real, already-tested, side-effect-free function, so that portion of
 * this file drives it directly — no duplication needed there.
 *
 * Usage: php tests/test_phase132_reports.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/../inc/import-export.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  PASS: $m\n"; }
function bad(string $m, string $hint = ''): void {
    global $fail; $fail++; echo "  FAIL: $m" . ($hint !== '' ? " — $hint" : '') . "\n";
}
function chk($cond, string $m, string $hint = ''): void { $cond ? ok($m) : bad($m, $hint); }

echo "\n=== Phase 132 — Reports, export, feed (Step 5) ===\n";

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
// Setup: sweep any leftovers from an aborted prior run, then build fixtures.
// ─────────────────────────────────────────────────────────────────────────
$SCOPE_MARK = '__P132T5_ Test Incident';
$DISP_MARK  = '__p132t5_';

function p132t5_cleanup(string $prefix, string $scopeMark, string $dispMark): void {
    foreach (db_fetch_all("SELECT `id` FROM `{$prefix}ticket` WHERE `scope` = ?", [$scopeMark]) as $old) {
        db_query("DELETE FROM `{$prefix}assigns` WHERE `ticket_id` = ?", [$old['id']]);
        db_query("DELETE FROM `{$prefix}action` WHERE `ticket_id` = ?", [$old['id']]);
        db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE `target_type` = 'ticket' AND `target_id` = ?",
            [(string) $old['id']]);
        db_query("DELETE FROM `{$prefix}ticket` WHERE `id` = ?", [$old['id']]);
    }
    db_query("DELETE FROM `{$prefix}ticket_disposition` WHERE `code` LIKE ?", [$dispMark . '%']);
}
p132t5_cleanup($prefix, $SCOPE_MARK, $DISP_MARK);

$typeId = (int) db_fetch_value("SELECT `id` FROM `{$prefix}in_types` ORDER BY `id` LIMIT 1");
chk($typeId > 0, 'fixture: an in_types row exists to build test tickets against');
if ($typeId <= 0) {
    echo "\nFATAL: no in_types row on this install, cannot continue.\n";
    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit(1);
}

/** Direct-INSERT ticket fixture (mirrors tests/test_phase132_writer.php / _incident_detail.php). */
function p132t5_mk_ticket(int $typeId): int {
    global $prefix, $SCOPE_MARK;
    $now = date('Y-m-d H:i:s');
    db_query(
        "INSERT INTO `{$prefix}ticket` (`in_types_id`, `status`, `severity`, `scope`, `description`,
         `date`, `problemstart`, `_by`)
         VALUES (?, 2, 0, ?, 'phase132 step5 test fixture', ?, ?, 1)",
        [$typeId, $SCOPE_MARK, $now, $now]
    );
    return (int) db_insert_id();
}

/** Direct-INSERT disposition fixture, code prefixed for cleanup. */
function p132t5_mk_disposition(string $suffix, string $label): int {
    global $prefix, $DISP_MARK;
    db_query(
        "INSERT INTO `{$prefix}ticket_disposition`
            (`status_val`, `description`, `code`, `discipline`, `org_id`, `sort_order`, `requires_comment`, `active`)
         VALUES (?, '', ?, '', NULL, 99, 0, 1)",
        [$label, $DISP_MARK . $suffix]
    );
    return (int) db_insert_id();
}

/**
 * Mirrors api/reports.php's 'incident_summary' case's disposition-
 * breakdown query, byte-for-byte — see file docblock "WHY THIS FILE
 * DUPLICATES SQL TEXT". Returns [disposition_label => total].
 */
function p132t5_disposition_breakdown(string $startSql, string $endSql): array {
    global $prefix;
    $rows = db_fetch_all(
        "SELECT
            COALESCE(`td`.`status_val`, 'No Disposition') AS `disposition_label`,
            COUNT(*) AS `total`
        FROM `{$prefix}ticket` `t`
        LEFT JOIN `{$prefix}ticket_disposition` `td` ON `t`.`disposition_id` = `td`.`id`
        WHERE `t`.`date` BETWEEN ? AND ?
          AND (`t`.`deleted_at` IS NULL OR `t`.`deleted_at` = '0000-00-00 00:00:00')
        GROUP BY `td`.`status_val`
        ORDER BY `total` DESC",
        [$startSql, $endSql]
    );
    $out = [];
    foreach ($rows as $r) {
        $out[$r['disposition_label']] = (int) $r['total'];
    }
    return $out;
}

/**
 * Mirrors the JOIN + column api/feed.php's open-incidents SELECT gained
 * (the part under test here) — scoped to specific ticket ids rather than
 * replicating the endpoint's status=2/ORDER BY/LIMIT 200 in full, so the
 * assertion is deterministic on a shared dev DB regardless of how many
 * other open incidents currently exist or how recent they are. The
 * status/soft-delete filtering and LIMIT/ORDER are unchanged by Step 5
 * and are not what this exercises.
 */
function p132t5_feed_rows(array $ticketIds): array {
    global $prefix;
    if (empty($ticketIds)) return [];
    $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));
    $rows = db_fetch_all(
        "SELECT `t`.`id`, `td`.`code` AS `disposition_code`
           FROM `{$prefix}ticket` `t`
           LEFT JOIN `{$prefix}ticket_disposition` `td` ON `t`.`disposition_id` = `td`.`id`
          WHERE `t`.`id` IN ({$placeholders})",
        $ticketIds
    );
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['id']] = $r['disposition_code'] ?? null;
    }
    return $out;
}

// A wide, cheap-to-reason-about window that certainly contains "now" —
// the report's own period-to-date-range logic (api/reports.php) is
// untouched by Step 5 and not what's under test here.
$startSql = date('Y-m-d 00:00:00', strtotime('-1 day'));
$endSql   = date('Y-m-d 23:59:59', strtotime('+1 day'));

// Baseline BEFORE fixtures exist — the "No Disposition" bucket is shared
// with every other undispositioned incident already in this window on a
// live dev DB, so the assertion below is a DELTA, not an absolute count
// (same discipline as tests/test_phase132_incident_detail.php's
// $fullActiveIds comparison).
$before = p132t5_disposition_breakdown($startSql, $endSql);
$beforeNoDisposition = $before['No Disposition'] ?? 0;

$DISP_LABEL = 'P132T5 Test Disposition';
$dispId = p132t5_mk_disposition('test_disp', $DISP_LABEL);
chk($dispId > 0, 'fixture: a non-seeded test disposition was created', "id={$dispId}");
$dispCode = $DISP_MARK . 'test_disp';

$ticketDisp1 = p132t5_mk_ticket($typeId);
$ticketDisp2 = p132t5_mk_ticket($typeId);
$ticketNull1 = p132t5_mk_ticket($typeId);
$ticketNull2 = p132t5_mk_ticket($typeId);
chk($ticketDisp1 > 0 && $ticketDisp2 > 0 && $ticketNull1 > 0 && $ticketNull2 > 0,
    'fixture: four test tickets created',
    "disp1={$ticketDisp1} disp2={$ticketDisp2} null1={$ticketNull1} null2={$ticketNull2}");

// Set the disposition on two, through the REAL writer — CLAUDE.md
// "reproduce bugs through the REAL creation path". The other two are left
// exactly as p132t5_mk_ticket() creates them: disposition_id NULL, the
// realistic default state for every incident nobody has dispositioned yet.
$set1 = incident_set_disposition_internal($ticketDisp1, $dispId, $userId);
$set2 = incident_set_disposition_internal($ticketDisp2, $dispId, $userId);
chk(!empty($set1['updated']) && !empty($set2['updated']),
    'fixture: the writer successfully set the disposition on both "disp" tickets',
    var_export([$set1, $set2], true));

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 1. api/reports.php incident_summary — disposition breakdown --\n";
// ─────────────────────────────────────────────────────────────────────────

$after = p132t5_disposition_breakdown($startSql, $endSql);

chk(($after[$DISP_LABEL] ?? 0) === 2,
    'the new disposition label appears in the breakdown with count 2',
    'got ' . var_export($after[$DISP_LABEL] ?? null, true));

$afterNoDisposition = $after['No Disposition'] ?? 0;
chk(($afterNoDisposition - $beforeNoDisposition) === 2,
    'the "No Disposition" (NULL) bucket increased by exactly 2 — the two undispositioned '
    . 'tickets are COUNTED, not silently dropped from the aggregation',
    "before={$beforeNoDisposition} after={$afterNoDisposition}");

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. inc/import-export.php incident target — disposition_code export --\n";
// ─────────────────────────────────────────────────────────────────────────

$config = get_table_config('incident');
chk(isset($config['columns']['disposition_code']), 'incident target declares disposition_code');
chk(($config['columns']['disposition_code']['import'] ?? true) === false,
    'disposition_code is export-only, like every other incident column');

$csv = export_csv($config);
chk($csv !== '', 'export_csv() for the incident target succeeded', 'got empty string (query failed)');

$parsed = parse_csv_string($csv);
chk(in_array('Disposition Code', $parsed['headers'], true),
    'the CSV header row includes "Disposition Code"');

$rowById = [];
foreach ($parsed['rows'] as $r) {
    $rowById[(string) ($r['ID'] ?? '')] = $r;
}

$rowDisp1 = $rowById[(string) $ticketDisp1] ?? null;
chk($rowDisp1 !== null, 'the dispositioned fixture ticket appears in the export', "ticket_id={$ticketDisp1}");
if ($rowDisp1 !== null) {
    chk(($rowDisp1['Disposition Code'] ?? null) === $dispCode,
        'export carries the disposition CODE',
        'got ' . var_export($rowDisp1['Disposition Code'] ?? null, true) . " want {$dispCode}");
    chk(($rowDisp1['Disposition Code'] ?? null) !== $DISP_LABEL,
        'export does NOT carry the renameable LABEL — code and label are deliberately different strings here');
}

$rowNull1 = $rowById[(string) $ticketNull1] ?? null;
chk($rowNull1 !== null, 'the undispositioned fixture ticket appears in the export', "ticket_id={$ticketNull1}");
if ($rowNull1 !== null) {
    chk(($rowNull1['Disposition Code'] ?? '__missing__') === '',
        'a NULL disposition_id exports as an empty field, not an error',
        'got ' . var_export($rowNull1['Disposition Code'] ?? '__missing__', true));
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. api/feed.php — disposition_code on the open-incidents JOIN --\n";
// ─────────────────────────────────────────────────────────────────────────

$feedRows = p132t5_feed_rows([$ticketDisp1, $ticketNull1]);

chk(array_key_exists($ticketDisp1, $feedRows), 'the dispositioned ticket is present in the feed row set');
chk(($feedRows[$ticketDisp1] ?? null) === $dispCode,
    'disposition_code is correct when set',
    'got ' . var_export($feedRows[$ticketDisp1] ?? null, true));

chk(array_key_exists($ticketNull1, $feedRows), 'the undispositioned ticket is present in the feed row set '
    . '(dropping it, rather than returning null, would be the bug)');
// NOTE: deliberately NOT `$feedRows[$ticketNull1] ?? '...'` here — `??`
// treats an array value of null as "not set" (it checks isset(), which is
// false for null), which would make this assertion pass even if the key
// were silently missing. Check key-existence and value separately so a
// dropped row and a present-but-null row can't be confused.
chk(array_key_exists($ticketNull1, $feedRows) && $feedRows[$ticketNull1] === null,
    'disposition_code is null — not erroring, not silently absent — when unset',
    'got ' . var_export($feedRows[$ticketNull1] ?? '__key_missing__', true));

// ─────────────────────────────────────────────────────────────────────────
// Cleanup.
// ─────────────────────────────────────────────────────────────────────────
p132t5_cleanup($prefix, $SCOPE_MARK, $DISP_MARK);

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
