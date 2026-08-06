<?php
/**
 * Phase 133 — Audit Log Retention & Purge
 *
 * Creates the purge manifest table, seeds the RBAC permission that gates
 * changing the retention setting or triggering a manual purge, and seeds the
 * setting itself — disabled (0 = keep forever) by default, so upgrading
 * TicketsCAD never starts deleting an operator's audit history they never
 * configured it to delete.
 *
 * Idempotent — safe to re-run. VERIFIES ITS OWN OUTCOME (CLAUDE.md, Phase 128
 * A9: a migration step that catches its own exception and exits 0 is a step
 * that never ran) — the last thing this does is re-ask the database whether
 * the table and the permission actually exist, and exits non-zero if not.
 *
 * Usage: php sql/run_phase133_audit_retention.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$table  = $prefix . 'audit_log_purges';
$fail   = [];

echo "Phase 133 — Audit Log Retention & Purge\n";
echo "========================================\n\n";

// ─────────────────────────────────────────────────────────────────────────
// 1. The manifest table
// ─────────────────────────────────────────────────────────────────────────
//
// No UNIQUE key anywhere (Phase 129 lesson: a unique key ending in a
// NULLable column constrains nothing for exactly the rows where that column
// is NULL, and triggered_by_user_id is NULL for every scheduled run). Two
// purges really are two rows; nothing here needs uniqueness.
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$table}` (
        `id`                   INT AUTO_INCREMENT PRIMARY KEY,
        `ran_at`               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `cutoff_date`          DATETIME     NOT NULL,
        `rows_purged`          INT          NOT NULL DEFAULT 0,
        `archive_filename`     VARCHAR(255) NOT NULL DEFAULT '',
        `archive_sha256`       CHAR(64)     NOT NULL DEFAULT '',
        `triggered_by`         ENUM('scheduled','manual') NOT NULL DEFAULT 'scheduled',
        `triggered_by_user_id` INT          NULL DEFAULT NULL,
        `status`               VARCHAR(16)  NOT NULL DEFAULT 'ok',
        `detail`               VARCHAR(512) NOT NULL DEFAULT '',
        KEY `idx_ran_at` (`ran_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] table `{$table}` present\n";
} catch (Exception $e) {
    $fail[] = 'create table: ' . $e->getMessage();
    echo "[FAIL] create table: " . $e->getMessage() . "\n";
}

// ─────────────────────────────────────────────────────────────────────────
// 2. RBAC — action.manage_audit_retention
// ─────────────────────────────────────────────────────────────────────────
//
// Scoped identically to action.manage_config: Super Admin only. Audit-log
// retention decides how long compliance records survive, which is the same
// tier of decision as system configuration itself — not something a
// per-org administrator should be able to shorten unilaterally. Granted to
// role 1 (Super Admin) here so an install that upgrades via run_migrations.php
// without re-importing sql/rbac.sql still gets the permission AND the
// correct grant; sql/rbac.sql and sql/run_00_rbac.php are also updated (see
// their Org Admin / Dispatcher exclusion lists) so a fresh install and a
// re-import agree with this migration.
$permCode = 'action.manage_audit_retention';
$permId   = 0;
try {
    $permId = (int) db_fetch_value(
        "SELECT `id` FROM `{$prefix}permissions` WHERE `code` = ? LIMIT 1", [$permCode]);
    if ($permId === 0) {
        db_query("INSERT INTO `{$prefix}permissions` (`code`, `name`, `description`, `category`)
                  VALUES (?, ?, ?, 'action')",
            [$permCode,
             'Manage Audit Log Retention',
             'Change the audit-log retention/purge setting and trigger a manual purge. '
             . 'Scoped like action.manage_config: Super Admin only.']);
        $permId = (int) db_insert_id();
        echo "[OK] permission inserted: {$permCode} (id={$permId})\n";
    } else {
        echo "[OK] permission exists: {$permCode} (id={$permId})\n";
    }
} catch (Exception $e) {
    $fail[] = 'permission: ' . $e->getMessage();
    echo "[FAIL] permission: " . $e->getMessage() . "\n";
}

if ($permId > 0) {
    try {
        $has = db_fetch_value(
            "SELECT 1 FROM `{$prefix}role_permissions`
              WHERE `role_id` = 1 AND `permission_id` = ? LIMIT 1", [$permId]);
        if (!$has) {
            db_query("INSERT INTO `{$prefix}role_permissions` (`role_id`, `permission_id`)
                      VALUES (1, ?)", [$permId]);
            echo "  [+] grant: role 1 (Super Admin) -> {$permCode}\n";
        } else {
            echo "  [OK] grant already present: role 1 -> {$permCode}\n";
        }
    } catch (Exception $e) {
        echo "  [warn] grant role 1: " . $e->getMessage() . "\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────
// 3. Default setting
// ─────────────────────────────────────────────────────────────────────────
//
// Written to the `settings` table — the store the Settings UI writes and
// get_variable() reads (NOT the `config` table read by get_setting() — see
// CLAUDE.md, "TWO settings stores"). 0 = disabled/keep forever, so an
// upgrade never starts deleting an operator's history on its own.
$defaults = [
    'audit_log_retention_days' => ['0', '0 = disabled (keep forever); N = purge rows older than N days'],
];
foreach ($defaults as $name => [$value, $note]) {
    try {
        $exists = db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}settings` WHERE `name` = ?", [$name]);
        if ((int) $exists === 0) {
            db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)", [$name, $value]);
            echo "  [+] setting seeded: {$name} = {$value}   ({$note})\n";
        } else {
            echo "  [skip] setting exists: {$name}\n";
        }
    } catch (Exception $e) {
        echo "  [warn] setting {$name}: " . $e->getMessage() . "\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────
// 4. Verify the OUTCOME — not that the script ran, but that it worked
// ─────────────────────────────────────────────────────────────────────────
try {
    $tableThere = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$table]);
    if ($tableThere === 0) $fail[] = "verify: table `{$table}` does not exist";

    $permThere = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}permissions` WHERE `code` = ?", [$permCode]);
    if ($permThere === 0) $fail[] = "verify: permission {$permCode} does not exist";

    if ($permId > 0) {
        $grantThere = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}role_permissions`
              WHERE `role_id` = 1 AND `permission_id` = ?", [$permId]);
        if ($grantThere === 0) $fail[] = "verify: role 1 does not hold {$permCode}";
    }
} catch (Exception $e) {
    $fail[] = 'verify: ' . $e->getMessage();
}

if ($fail) {
    echo "\nFAILED:\n  - " . implode("\n  - ", $fail) . "\n";
    exit(1);   // non-zero, so sql/run_migrations.php records a real failure
}

echo "\nDone. Audit log retention is installed (disabled by default).\n";
exit(0);
