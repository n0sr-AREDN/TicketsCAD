<?php
/**
 * Phase 132 (2026-08-03) — Structured incident disposition, Step 1 ONLY:
 * migration + seeds + captions + setting + RBAC permission. See
 * specs/phase-132-incident-disposition/{spec.md,plan.md,tasks.md}. Steps
 * 2-5 (writer/API enforcement, Settings panel, UI dropdowns, reports/
 * export) are separate, later work and have no tests here.
 *
 * Drives the REAL migration script (sql/run_phase132_disposition.php) as a
 * subprocess, twice, and asserts the resulting database state — never
 * hand-seeds ticket_disposition rows directly. This matters for two
 * reasons specific to this table:
 *
 *   1. `ticket_disposition` has NO database-level UNIQUE key (see that
 *      script's docblock): org_id is NULLable and every seeded row is
 *      global (org_id NULL), and MySQL/MariaDB treat every NULL in a
 *      UNIQUE index as a DISTINCT value (Phase 129's lesson — the same
 *      trap that let uk_user_role_org silently multiply Super Admin
 *      grants). So idempotency here is enforced entirely at the
 *      application level inside the migration script, and the ONLY way to
 *      actually prove it holds is to ask the real script to accept a
 *      "duplicate" — i.e. run it twice — not to read the CREATE TABLE and
 *      assume a constraint is doing the work.
 *   2. The RBAC section below mirrors tests/test_audit_log_retention.php's
 *      shape exactly (Eric's own reference for this pattern): assert the
 *      permission is held ONLY by Super Admin, by role id, not merely that
 *      rbac_can() returns true for an admin (Super Admin also short-
 *      circuits via is_super, so that alone wouldn't prove the grant is
 *      correctly scoped).
 *
 * Usage: php tests/test_phase132_migration.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  PASS: $m\n"; }
function bad(string $m, string $hint = ''): void {
    global $fail; $fail++; echo "  FAIL: $m" . ($hint !== '' ? " — $hint" : '') . "\n";
}
function chk($cond, string $m, string $hint = ''): void { $cond ? ok($m) : bad($m, $hint); }

echo "\n=== Phase 132 — Incident disposition migration (Step 1) ===\n";

$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) {
    echo "\nSKIP: no database available — this test needs one\n";
    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

$prefix      = $GLOBALS['db_prefix'] ?? '';
$dispTable   = $prefix . 'ticket_disposition';
$ticketTable = $prefix . 'ticket';

/** Run the real migration script as a subprocess; return [exitCode, output]. */
function p132_run_migration(): array {
    $php    = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $root   = realpath(__DIR__ . '/..');
    $script = $root . '/sql/run_phase132_disposition.php';
    $output = [];
    $rc = 0;
    exec('"' . $php . '" "' . $script . '" 2>&1', $output, $rc);
    return [$rc, implode("\n", $output)];
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 1. The real migration script runs cleanly and IS idempotent --\n";
[$rc1, $out1] = p132_run_migration();
chk($rc1 === 0, 'first run exits 0', substr($out1, 0, 400));

$haveTable = (int) db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?",
    [$dispTable]);
if ($haveTable === 0) {
    bad('ticket_disposition table missing after the migration ran — cannot continue',
        substr($out1, 0, 400));
    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit(1);
}

$countAfter1 = (int) db_fetch_value("SELECT COUNT(*) FROM `{$dispTable}`");

[$rc2, $out2] = p132_run_migration();
chk($rc2 === 0, 'second run (re-run) exits 0', substr($out2, 0, 400));

$countAfter2 = (int) db_fetch_value("SELECT COUNT(*) FROM `{$dispTable}`");
chk($countAfter2 === $countAfter1,
    "re-running the migration adds no new rows (before={$countAfter1}, after={$countAfter2})");

// This IS the "insert a duplicate and check it's rejected/ignored" proof —
// via the real writer, twice, not by reading the CREATE TABLE.
$distinctGlobalCodes = (int) db_fetch_value(
    "SELECT COUNT(DISTINCT `code`) FROM `{$dispTable}` WHERE `org_id` IS NULL");
$totalGlobalRows = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$dispTable}` WHERE `org_id` IS NULL");
chk($distinctGlobalCodes === $totalGlobalRows,
    'no duplicate (org_id IS NULL, code) pairs after running the migration twice',
    "distinct codes={$distinctGlobalCodes} total global rows={$totalGlobalRows}");

$capCountAfter2 = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}captions_i18n` WHERE `category` = 'disposition'");
[$rc3, ] = p132_run_migration();
$capCountAfter3 = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}captions_i18n` WHERE `category` = 'disposition'");
chk($rc3 === 0, 'third run also exits 0');
chk($capCountAfter3 === $capCountAfter2,
    "captions are not duplicated across repeated runs (before={$capCountAfter2}, after={$capCountAfter3})");

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. Table and column exist --\n";
chk($haveTable === 1, 'ticket_disposition table exists');

$colExists = (int) db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='disposition_id'",
    [$ticketTable]);
chk($colExists === 1, 'ticket.disposition_id column exists');

$idxExists = (int) db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME='idx_ticket_disposition'",
    [$ticketTable]);
chk($idxExists >= 1, 'an index on ticket.disposition_id exists');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. The 6 seeded dispositions: code / discipline / org_id / label / sort_order --\n";
$expected = [
    'resolved'               => ['label' => 'Resolved / Handled',       'sort' => 1],
    'unfounded'               => ['label' => 'Unfounded',                'sort' => 2],
    'cancelled'               => ['label' => 'Cancelled',                'sort' => 3],
    'duplicate_call'          => ['label' => 'Duplicate Call',           'sort' => 4],
    'referred_other_agency'   => ['label' => 'Referred to Other Agency', 'sort' => 5],
    'no_action'               => ['label' => 'No Action Necessary',      'sort' => 6],
];

foreach ($expected as $code => $info) {
    $row = db_fetch_one(
        "SELECT * FROM `{$dispTable}` WHERE `code` = ? AND `org_id` IS NULL", [$code]);
    chk($row !== null, "disposition '{$code}' exists (global, org_id NULL)");
    if ($row === null) continue;
    chk($row['status_val'] === $info['label'],
        "'{$code}' status_val is '{$info['label']}'", (string) $row['status_val']);
    chk($row['discipline'] === '',
        "'{$code}' discipline is '' (empty means always-offered, per spec/plan)", var_export($row['discipline'], true));
    chk($row['org_id'] === null, "'{$code}' org_id is NULL (available to every org)");
    chk((int) $row['sort_order'] === $info['sort'],
        "'{$code}' sort_order is {$info['sort']}", (string) $row['sort_order']);
    chk((int) $row['active'] === 1, "'{$code}' is active (not retired)");
    chk((int) $row['requires_comment'] === 0, "'{$code}' requires_comment defaults to 0");
}

$seedCodesList = "'" . implode("','", array_keys($expected)) . "'";
$totalSeeded = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$dispTable}` WHERE `org_id` IS NULL AND `code` IN ({$seedCodesList})");
chk($totalSeeded === count($expected),
    'exactly the 6 seeded dispositions are present (none missing, none duplicated)',
    (string) $totalSeeded);

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. Setting disposition_required_on_close defaults to '0' (off) --\n";
$settingRow = db_fetch_one(
    "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'disposition_required_on_close'");
chk($settingRow !== null, 'setting disposition_required_on_close exists');
if ($settingRow !== null) {
    chk($settingRow['value'] === '0',
        'setting defaults to 0 — an existing install must not change close behaviour on upgrade',
        (string) $settingRow['value']);
}
// Written to `settings` (name/value), read by get_variable() — never
// get_setting()/`config` (GH #79, CLAUDE.md "TWO settings stores").
$viaGetVariable = get_variable('disposition_required_on_close');
chk($viaGetVariable === '0',
    'get_variable() (the settings-table reader) sees the seeded value', var_export($viaGetVariable, true));

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 5. Captions resolve in all 5 shipped languages for all 6 dispositions --\n";
$langs = ['en', 'de', 'nl', 'fr', 'es'];
foreach (array_keys($expected) as $code) {
    $key = "disposition.{$code}";
    foreach ($langs as $lang) {
        $val = db_fetch_value(
            "SELECT `value` FROM `{$prefix}captions_i18n` WHERE `caption_key` = ? AND `lang` = ?",
            [$key, $lang]);
        chk($val !== false && $val !== null && $val !== '',
            "caption {$key} [{$lang}] resolves to non-empty text", var_export($val, true));
    }
}
// The English row must match the seeded status_val so the DB label and the
// caption fallback don't quietly diverge for the default language.
foreach ($expected as $code => $info) {
    $enVal = db_fetch_value(
        "SELECT `value` FROM `{$prefix}captions_i18n` WHERE `caption_key` = ? AND `lang` = 'en'",
        ["disposition.{$code}"]);
    chk($enVal === $info['label'],
        "disposition.{$code} English caption matches the seeded status_val", (string) $enVal);
}
$capCategoryCount = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}captions_i18n` WHERE `category` = 'disposition'");
chk($capCategoryCount === count($expected) * count($langs),
    'exactly 30 disposition captions exist (6 codes x 5 languages, no more, no fewer)',
    (string) $capCategoryCount);

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 6. RBAC: action.manage_dispositions exists and is held ONLY by Super Admin --\n";
$permRow = db_fetch_one(
    "SELECT `id` FROM `{$prefix}permissions` WHERE `code` = 'action.manage_dispositions' LIMIT 1");
chk($permRow !== null,
    'permission action.manage_dispositions exists — run sql/run_phase132_disposition.php');

if ($permRow !== null) {
    $permId = (int) $permRow['id'];
    $roleIds = array_map('intval', array_column(
        db_fetch_all(
            "SELECT `role_id` FROM `{$prefix}role_permissions` WHERE `permission_id` = ?", [$permId]),
        'role_id'
    ));
    sort($roleIds);

    chk(in_array(1, $roleIds, true), 'Super Admin (role 1) holds action.manage_dispositions');
    chk(!in_array(2, $roleIds, true), 'Org Admin (role 2) does NOT hold action.manage_dispositions');
    chk(!in_array(3, $roleIds, true), 'Dispatcher (role 3) does NOT hold action.manage_dispositions');
    chk(!in_array(4, $roleIds, true), 'Operator (role 4) does NOT hold action.manage_dispositions');
    chk(!in_array(5, $roleIds, true), 'Read-Only (role 5) does NOT hold action.manage_dispositions');
    chk(!in_array(6, $roleIds, true), 'Field Unit (role 6) does NOT hold action.manage_dispositions');
    chk($roleIds === [1], 'exactly one role (Super Admin) holds the permission', implode(',', $roleIds));

    // Corroborating runtime check via rbac_can() for the real admin session.
    // (Super Admin also short-circuits rbac_can() via is_super — this does
    // NOT by itself prove the grant exists, which is why the direct
    // role_permissions query above is the primary evidence.)
    $adminId = test_admin_user_id();
    $origSessionUser = $_SESSION['user_id'] ?? null;
    $_SESSION['user_id'] = $adminId;
    rbac_clear_cache();
    chk(rbac_can('action.manage_dispositions') === true,
        'rbac_can() grants the permission to the real admin session');
    if ($origSessionUser === null) unset($_SESSION['user_id']); else $_SESSION['user_id'] = $origSessionUser;
    rbac_clear_cache();
}

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
