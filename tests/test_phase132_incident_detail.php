<?php
/**
 * Phase 132 (2026-08-04) — Structured incident disposition, Step 4:
 * incident-detail + close-action dropdowns. See
 * specs/phase-132-incident-disposition/{spec.md,plan.md,tasks.md}.
 * Steps 1-3 (migration/seeds, writer/API enforcement, Settings panel) are
 * untouched and separately tested (test_phase132_migration.php,
 * test_phase132_writer.php, test_phase132_settings_panel.php).
 *
 * What Step 4 actually added, and what this file proves:
 *   - disposition_options_for_ticket_internal() (inc/disposition-admin.php)
 *     — the OFFERED-list builder behind api/dispositions-picker.php.
 *     Filters ACTIVE dispositions by the incident's type discipline
 *     (in_types.group vs. ticket_disposition.discipline) and org, with a
 *     HARD INVARIANT: never truncated to empty (plan.md §1). Also always
 *     surfaces the incident's own current value, even retired.
 *   - Confirms discipline filtering is presentation-only: a
 *     discipline-mismatched disposition is still WRITABLE via
 *     incident_set_disposition_internal() (Step 2, unchanged), even
 *     though the picker would not have offered it.
 *
 * Driven through the REAL function, never hand-seeded ticket.disposition_id
 * — CLAUDE.md "reproduce bugs through the REAL creation path". Ticket /
 * in_types / ticket_disposition ROWS are still created with direct
 * INSERTs (fixture setup, mirroring tests/test_phase132_writer.php's
 * p132_mk_ticket()/p132_mk_disposition() style) — that is not the thing
 * under test. What IS under test is exercised exclusively through
 * disposition_options_for_ticket_internal() and
 * incident_set_disposition_internal().
 *
 * Usage: php tests/test_phase132_incident_detail.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/../inc/disposition-admin.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  PASS: $m\n"; }
function bad(string $m, string $hint = ''): void {
    global $fail; $fail++; echo "  FAIL: $m" . ($hint !== '' ? " — $hint" : '') . "\n";
}
function chk($cond, string $m, string $hint = ''): void { $cond ? ok($m) : bad($m, $hint); }

echo "\n=== Phase 132 — Incident-detail disposition picker (Step 4) ===\n";

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
$SCOPE_MARK = '__P132T4_ Test Incident';
$DISP_MARK  = '__p132t4_';
$TYPE_MARK  = '__P132T4';

foreach (db_fetch_all("SELECT `id` FROM `{$prefix}ticket` WHERE `scope` = ?", [$SCOPE_MARK]) as $old) {
    db_query("DELETE FROM `{$prefix}assigns` WHERE `ticket_id` = ?", [$old['id']]);
    db_query("DELETE FROM `{$prefix}action` WHERE `ticket_id` = ?", [$old['id']]);
    db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE `target_type` = 'ticket' AND `target_id` = ?",
        [(string) $old['id']]);
    db_query("DELETE FROM `{$prefix}ticket` WHERE `id` = ?", [$old['id']]);
}
db_query("DELETE FROM `{$prefix}ticket_disposition` WHERE `code` LIKE ?", [$DISP_MARK . '%']);
db_query("DELETE FROM `{$prefix}in_types` WHERE `type` LIKE ?", [$TYPE_MARK . '%']);

$DISC_FIRE    = $DISP_MARK . 'fire';
$DISC_EMS     = $DISP_MARK . 'ems';
$DISC_NOMATCH = $DISP_MARK . 'nomatch'; // deliberately matches no disposition's discipline

/** Direct-INSERT in_types fixture (MyISAM, mirrors the seed script's shape). */
function p132t4_mk_type(string $typeSuffix, string $group): int {
    global $prefix, $TYPE_MARK;
    db_query(
        "INSERT INTO `{$prefix}in_types` (`type`, `description`, `group`) VALUES (?, ?, ?)",
        [substr($TYPE_MARK . $typeSuffix, 0, 20), 'phase132 step4 test type', $group]
    );
    return (int) db_insert_id();
}

/** Direct-INSERT ticket fixture (mirrors tests/test_phase132_writer.php). */
function p132t4_mk_ticket(int $typeId): int {
    global $prefix, $SCOPE_MARK;
    $now = date('Y-m-d H:i:s');
    db_query(
        "INSERT INTO `{$prefix}ticket` (`in_types_id`, `status`, `severity`, `scope`, `description`,
         `date`, `problemstart`, `_by`)
         VALUES (?, 2, 0, ?, 'phase132 step4 test fixture', ?, ?, 1)",
        [$typeId, $SCOPE_MARK, $now, $now]
    );
    return (int) db_insert_id();
}

/** Direct-INSERT disposition fixture, code prefixed for cleanup. */
function p132t4_mk_disposition(string $suffix, string $discipline, int $active = 1): int {
    global $prefix, $DISP_MARK;
    db_query(
        "INSERT INTO `{$prefix}ticket_disposition`
            (`status_val`, `description`, `code`, `discipline`, `org_id`, `sort_order`, `requires_comment`, `active`)
         VALUES (?, '', ?, ?, NULL, 99, 0, ?)",
        ['P132T4 Test ' . $suffix, $DISP_MARK . $suffix, $discipline, $active]
    );
    return (int) db_insert_id();
}

function p132t4_ticket_disposition(int $ticketId) {
    global $prefix;
    $v = db_fetch_value("SELECT `disposition_id` FROM `{$prefix}ticket` WHERE `id` = ?", [$ticketId]);
    return ($v === null || $v === false) ? null : (int) $v;
}

/** Extract just the ids from a disposition_options_for_ticket_internal() result. */
function p132t4_ids(array $result): array {
    return array_map(function ($r) { return (int) $r['id']; }, $result['dispositions']);
}

// Fixture types: one whose discipline tag matches a real disposition
// ('fire'), one whose tag matches NOTHING ('nomatch' — the hard-invariant
// case), and one with no tag at all (empty group — the other hard-
// invariant case).
$typeFireId     = p132t4_mk_type('F', $DISC_FIRE);
$typeNoMatchId  = p132t4_mk_type('N', $DISC_NOMATCH);
$typeEmptyId    = p132t4_mk_type('E', '');
chk($typeFireId > 0 && $typeNoMatchId > 0 && $typeEmptyId > 0,
    'fixture: three test in_types rows created (fire-discipline, no-match-discipline, empty-discipline)',
    "fire={$typeFireId} nomatch={$typeNoMatchId} empty={$typeEmptyId}");

// Fixture dispositions: one per discipline used above, plus an
// always-offered one (discipline='').
$dispFireId    = p132t4_mk_disposition('fire_disp', $DISC_FIRE);
$dispEmsId     = p132t4_mk_disposition('ems_disp', $DISC_EMS);
$dispAlwaysId  = p132t4_mk_disposition('always_disp', '');
chk($dispFireId > 0 && $dispEmsId > 0 && $dispAlwaysId > 0,
    'fixture: three test dispositions created (fire, ems, always-offered)',
    "fire={$dispFireId} ems={$dispEmsId} always={$dispAlwaysId}");

if ($typeFireId <= 0 || $typeNoMatchId <= 0 || $typeEmptyId <= 0
    || $dispFireId <= 0 || $dispEmsId <= 0 || $dispAlwaysId <= 0) {
    echo "\nFATAL: prerequisite fixtures missing, cannot continue.\n";
    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit(1);
}

$ticketFire     = p132t4_mk_ticket($typeFireId);
$ticketNoMatch  = p132t4_mk_ticket($typeNoMatchId);
$ticketEmpty    = p132t4_mk_ticket($typeEmptyId);

// Ground truth: the FULL active list this install currently has (includes
// Step 1's seeded 6 core dispositions plus our 3 fixtures, and possibly
// leftovers from other admin activity on this shared dev DB — that is
// exactly why the fallback tests compare against this live query rather
// than a hardcoded id list).
$fullActiveIds = array_map(function ($r) { return (int) $r['id']; },
    db_fetch_all("SELECT `id` FROM `{$prefix}ticket_disposition` WHERE `active` = 1"));
sort($fullActiveIds);

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 1. Default (no disposition set yet) — current_* are null --\n";
// ─────────────────────────────────────────────────────────────────────────

$r1 = disposition_options_for_ticket_internal($ticketFire);
chk(array_key_exists('disposition_required_on_close', $r1),
    'response carries disposition_required_on_close passthrough');
chk($r1['current_id'] === null, 'current_id is null before anything is set', var_export($r1['current_id'], true));
chk($r1['current_label'] === null, 'current_label is null before anything is set');
chk($r1['current_retired'] === false, 'current_retired is false before anything is set');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. Discipline filtering: fire-type incident offers fire + always, excludes ems --\n";
// ─────────────────────────────────────────────────────────────────────────

$fireIds = p132t4_ids($r1);
chk(in_array($dispFireId, $fireIds, true), 'the FIRE-discipline disposition is offered on a fire-type incident');
chk(in_array($dispAlwaysId, $fireIds, true), 'the always-offered (discipline=\'\') disposition is offered too');
chk(!in_array($dispEmsId, $fireIds, true), 'the EMS-discipline disposition is EXCLUDED from a fire-type incident');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. HARD INVARIANT: a discipline tag matching NOTHING falls back to the FULL active list --\n";
// ─────────────────────────────────────────────────────────────────────────

$rNoMatch = disposition_options_for_ticket_internal($ticketNoMatch);
$noMatchIds = p132t4_ids($rNoMatch);
sort($noMatchIds);
chk(in_array($dispFireId, $noMatchIds, true) && in_array($dispEmsId, $noMatchIds, true)
    && in_array($dispAlwaysId, $noMatchIds, true),
    'a non-matching discipline tag still offers fire + ems + always — never truncated');
chk($noMatchIds === $fullActiveIds,
    'the offered set is EXACTLY the full active list (no truncation at all) when the tag matches nothing',
    'got ' . implode(',', $noMatchIds) . ' vs full ' . implode(',', $fullActiveIds));

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. HARD INVARIANT: an incident type with NO discipline tag also falls back to the FULL list --\n";
// ─────────────────────────────────────────────────────────────────────────

$rEmpty = disposition_options_for_ticket_internal($ticketEmpty);
$emptyIds = p132t4_ids($rEmpty);
sort($emptyIds);
chk(in_array($dispFireId, $emptyIds, true) && in_array($dispEmsId, $emptyIds, true)
    && in_array($dispAlwaysId, $emptyIds, true),
    'an untagged incident type still offers fire + ems + always — never truncated');
chk($emptyIds === $fullActiveIds,
    'the offered set is EXACTLY the full active list for an untagged incident type',
    'got ' . implode(',', $emptyIds) . ' vs full ' . implode(',', $fullActiveIds));

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 5. A retired current value stays visible (label + retired flag), even though excluded from the offered list --\n";
// ─────────────────────────────────────────────────────────────────────────

$dispRetireId = p132t4_mk_disposition('retiree', ''); // always-offered while active
$ticketRetired = p132t4_mk_ticket($typeFireId);

$setResult = incident_set_disposition_internal($ticketRetired, $dispRetireId, $userId);
chk(!empty($setResult['updated']), 'fixture: an ACTIVE disposition can be assigned', var_export($setResult, true));
chk(p132t4_ticket_disposition($ticketRetired) === $dispRetireId, 'fixture: ticket now carries it');

// Retire it.
db_query("UPDATE `{$prefix}ticket_disposition` SET `active` = 0 WHERE `id` = ?", [$dispRetireId]);

$rRetired = disposition_options_for_ticket_internal($ticketRetired);
chk($rRetired['current_id'] === $dispRetireId,
    'current_id still names the (now-retired) disposition', var_export($rRetired['current_id'], true));
chk($rRetired['current_label'] === 'P132T4 Test retiree',
    'current_label still resolves even though the disposition is retired', var_export($rRetired['current_label'], true));
chk($rRetired['current_retired'] === true,
    'current_retired is true — the UI can badge it, unlike an ordinary offered entry');

$retiredOfferedIds = p132t4_ids($rRetired);
chk(in_array($dispRetireId, $retiredOfferedIds, true),
    'the retired current value is ALSO present in dispositions[] so the <select> can render + pre-select its label');
$retireeCount = 0;
foreach ($rRetired['dispositions'] as $d) { if ((int) $d['id'] === $dispRetireId) $retireeCount++; }
chk($retireeCount === 1, 'the retired current value appears exactly ONCE, not duplicated', "count={$retireeCount}");

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 6. Discipline filtering is PRESENTATION ONLY — a discipline-mismatched disposition is still WRITABLE via the API --\n";
// ─────────────────────────────────────────────────────────────────────────
// Test 2 proved the picker EXCLUDES the ems-discipline disposition from a
// fire-type incident's offered list. This proves that exclusion is purely
// a display filter: incident_set_disposition_internal() — the exact
// function api/incident-update.php's set_disposition action calls — must
// still accept it (spec.md "Filtering is presentation, not validation").

$setMismatch = incident_set_disposition_internal($ticketFire, $dispEmsId, $userId);
chk(!empty($setMismatch['updated']) && empty($setMismatch['errors']),
    'an EMS-discipline disposition is successfully written onto a FIRE-type incident via the writer',
    var_export($setMismatch, true));
chk(p132t4_ticket_disposition($ticketFire) === $dispEmsId,
    'ticket.disposition_id reflects the discipline-mismatched write — the server never validated discipline');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 7. Regression spot-check: set_disposition (Step 2) is otherwise unaffected --\n";
// ─────────────────────────────────────────────────────────────────────────
// Not a full re-test (tests/test_phase132_writer.php already covers Step 2
// exhaustively) — just confirms Step 4's additions didn't disturb it.

$resolvedId = (int) db_fetch_value(
    "SELECT `id` FROM `{$prefix}ticket_disposition` WHERE `code` = 'resolved' AND `org_id` IS NULL LIMIT 1");
chk($resolvedId > 0, 'fixture: Step 1\'s seeded "resolved" disposition exists');
if ($resolvedId > 0) {
    $ticketRegression = p132t4_mk_ticket($typeFireId);
    $regressionResult = incident_set_disposition_internal($ticketRegression, $resolvedId, $userId);
    chk(!empty($regressionResult['updated']) && empty($regressionResult['errors']),
        'incident_set_disposition_internal() still works exactly as Step 2 left it',
        var_export($regressionResult, true));
}

// ─────────────────────────────────────────────────────────────────────────
// Cleanup.
// ─────────────────────────────────────────────────────────────────────────
foreach (db_fetch_all("SELECT `id` FROM `{$prefix}ticket` WHERE `scope` = ?", [$SCOPE_MARK]) as $t) {
    db_query("DELETE FROM `{$prefix}assigns` WHERE `ticket_id` = ?", [$t['id']]);
    db_query("DELETE FROM `{$prefix}action` WHERE `ticket_id` = ?", [$t['id']]);
    db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE `target_type` = 'ticket' AND `target_id` = ?",
        [(string) $t['id']]);
    db_query("DELETE FROM `{$prefix}ticket` WHERE `id` = ?", [$t['id']]);
}
db_query("DELETE FROM `{$prefix}ticket_disposition` WHERE `code` LIKE ?", [$DISP_MARK . '%']);
db_query("DELETE FROM `{$prefix}in_types` WHERE `type` LIKE ?", [$TYPE_MARK . '%']);

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
