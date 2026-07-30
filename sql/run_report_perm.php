<?php
/**
 * Run Report Permission — seed `action.view_reports` and grant it to the
 * administrator roles.
 *
 * Why this exists (2026-07-29):
 *   api/reports.php used to gate its aggregate + personnel reports on the
 *   LEGACY `user.level` column (`level <= 1`) while reports.php (the page)
 *   gates on RBAC (`rbac_require_screen('screen.reports')`). After the
 *   Phase 12 RBAC migration, user.level is noise — a your deployment Org Admin
 *   with user.level=4 / role_id=2 passed the page and was then refused by
 *   the API on every report. The endpoint now asks the role system:
 *       is_admin() || rbac_can('action.view_reports')
 *   so the permission must exist and be granted on EXISTING installs, not
 *   only on fresh ones (sql/rbac.sql + sql/run_00_rbac.php cover those).
 *
 * Grants: role 1 (Super Admin) and role 2 (Org Admin). Deliberately NOT
 * Dispatcher / Operator / Read-Only / Field Unit — the endpoint's documented
 * intent is that org-wide aggregates are administrative, and non-holders can
 * still run reports scoped to a single incident or responder. An install that
 * wants dispatchers running shift reports grants it in the Roles UI.
 *
 * Usage: php sql/run_report_perm.php   (also runs via sql/run_migrations.php)
 * Safety: idempotent — INSERT IGNORE only, safe to run repeatedly.
 */
require_once __DIR__ . '/../config.php';

echo "Report permission (action.view_reports)\n";
echo "=======================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// Guard: RBAC tables must exist. On a pre-RBAC install there's nothing to do.
try {
    db_fetch_value("SELECT 1 FROM `{$prefix}permissions` LIMIT 1");
    db_fetch_value("SELECT 1 FROM `{$prefix}roles` LIMIT 1");
} catch (Exception $e) {
    echo "[skip] RBAC tables not present — nothing to seed.\n";
    return;
}

$code = 'action.view_reports';
$name = 'Run Aggregate Reports';
$desc = 'Run cross-incident / cross-responder and personnel reports '
      . '(screen.reports alone only allows single-resource reports)';

try {
    db_query(
        "INSERT IGNORE INTO `{$prefix}permissions`
            (`code`, `name`, `category`, `resource`, `verb`, `description`)
         VALUES (?, ?, 'action', 'reports', 'view', ?)",
        [$code, $name, $desc]
    );
} catch (Exception $e) {
    // Older schema without resource/verb columns — fall back to the minimal set.
    try {
        db_query(
            "INSERT IGNORE INTO `{$prefix}permissions` (`code`, `name`, `category`, `description`)
             VALUES (?, ?, 'action', ?)",
            [$code, $name, $desc]
        );
    } catch (Exception $e2) {
        echo "[FAIL] could not seed {$code}: " . $e2->getMessage() . "\n";
        exit(1);
    }
}

$permId = (int) db_fetch_value(
    "SELECT `id` FROM `{$prefix}permissions` WHERE `code` = ?",
    [$code]
);
if ($permId <= 0) {
    echo "[FAIL] {$code} was not seeded\n";
    exit(1);
}
echo "[ok] permission {$code} (id {$permId})\n";

$granted = 0;
foreach ([1, 2] as $roleId) {
    try {
        db_query(
            "INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`)
             VALUES (?, ?)",
            [$roleId, $permId]
        );
        $granted++;
    } catch (Exception $e) {
        // Role may not exist on this install (custom role set) — not fatal.
        echo "[warn] role {$roleId}: " . $e->getMessage() . "\n";
    }
}
echo "[ok] {$code} granted to {$granted} role(s) (1 Super Admin, 2 Org Admin)\n";

echo "\nDone.\n";
