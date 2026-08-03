<?php
/**
 * Soft-delete columns (deleted_at / deleted_by) on `ics_forms`, plus the
 * `action.delete_ics_form` RBAC permission and its default grants.
 *
 * REPORTED BY: Chris Byrd, 2026-07-29 (chased 2026-08-02):
 *
 *     "When you create a new ICS form and save as draft or finalize, there
 *      is no way to delete it. I think there should be a way an admin could
 *      delete it or allow user to delete draft they have created. I can
 *      switch a finalized form back to draft but no way to delete it."
 *
 * He was right: api/ics-forms.php supported save / export_xml / export_pdf
 * and nothing else. Once an ICS form existed it was permanent — for its
 * creator, for an admin, for anyone.
 *
 * WHY SOFT DELETE, AND WHY NO PURGE. A finalized ICS-214 is the operational
 * record of a real incident, so removing one is a records-retention decision
 * rather than a convenience. Everything deleted here lands in the existing
 * wastebasket and is restorable, and NOTHING hard-deletes an ICS form —
 * api/wastebasket.php marks this type `purgeable => false`, so neither the
 * per-row purge button nor "Empty wastebasket" can destroy one.
 *
 * WHY A run_*.php WRAPPER AND NOT A .sql. sql/run_migrations.php discovers
 * work with glob('sql/run_*.php'); a feature .sql referenced only from
 * tools/install_fresh.php reaches fresh installs and never reaches upgrades.
 * That exact gap shipped three times already (facilities.bed_auto_mode,
 * ticket.signal, warnings.radius) and once for these very columns on the
 * other four tables — see sql/run_soft_delete.php, which Chris Byrd also
 * reported. Both paths converge here.
 *
 * WHY THE PERMISSION IS GRANTED EXPLICITLY. The "Super Admin gets EVERYTHING"
 * seed in sql/rbac.sql is an INSERT ... SELECT that ran once at install, so a
 * permission added later is NOT granted retroactively by it. Roles 1 and 2 are
 * therefore granted here by id, re-asserted every run so a
 * permission-exists-but-grant-missing state self-heals. Dispatcher (3) is
 * deliberately NOT granted — deleting an operational record is not a dispatch
 * task. An install that wants a Records Unit Leader to hold it grants it from
 * the Roles UI.
 *
 * Idempotent: checks information_schema before each ALTER, INSERT IGNORE on
 * the permission and the grants, adds only what is absent, deletes nothing.
 * Safe to re-run.
 *
 * Usage: php sql/run_ics_forms_soft_delete.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/db.php';

$prefix  = $GLOBALS['db_prefix'] ?? '';
$changes = 0;

echo "ICS form soft delete (deleted_at / deleted_by + action.delete_ics_form)\n";
echo "======================================================================\n";

// ── 1. Schema ────────────────────────────────────────────────────────────
$table = $prefix . 'ics_forms';

try {
    $exists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$table]
    );

    if ($exists === 0) {
        // Should not happen: sql/run_migrations.php ksorts its glob, and
        // "run_ics_forms.php" sorts before "run_ics_forms_soft_delete.php"
        // ('.' < '_'), so the CREATE always runs first in the same pass. The
        // file name is load-bearing for that reason — don't rename it to
        // anything that sorts earlier. Report rather than fail if it happens
        // anyway; the next pass picks it up.
        echo "  skip `{$table}` — table not present yet (run sql/run_ics_forms.php first)\n";
    } else {
        foreach ([
            'deleted_at' => 'DATETIME DEFAULT NULL',
            'deleted_by' => 'INT DEFAULT NULL',
        ] as $col => $ddl) {
            $has = (int) db_fetch_value(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$table, $col]
            );
            if ($has === 0) {
                db_query("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$ddl}");
                echo "  added `{$table}`.`{$col}`\n";
                $changes++;
            }
        }

        // Every list query now filters on deleted_at; without an index that is
        // a full scan of the forms table on the ICS hub's first paint.
        $idx = (int) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'idx_deleted_at'",
            [$table]
        );
        if ($idx === 0) {
            try {
                db_query("ALTER TABLE `{$table}` ADD INDEX `idx_deleted_at` (`deleted_at`)");
                echo "  indexed `{$table}`.`deleted_at`\n";
                $changes++;
            } catch (Throwable $e) {
                echo "  [note] could not index `{$table}`.`deleted_at`: " . $e->getMessage() . "\n";
            }
        }
    }
} catch (Throwable $e) {
    echo "  [WARN] {$table}: " . $e->getMessage() . "\n";
}

// ── 2. RBAC permission + default grants ──────────────────────────────────
try {
    db_query(
        "INSERT IGNORE INTO `{$prefix}permissions` (`code`, `name`, `category`, `description`)
         VALUES (?, ?, ?, ?)",
        [
            'action.delete_ics_form',
            'Delete ICS Forms',
            'action',
            'Delete any saved ICS form to the wastebasket, including finalized forms. '
                . 'Without it a user may still delete a draft they created themselves.',
        ]
    );

    $permId = (int) db_fetch_value(
        "SELECT id FROM `{$prefix}permissions` WHERE `code` = ?",
        ['action.delete_ics_form']
    );

    if ($permId > 0) {
        $granted = 0;
        // Super Admin (1) and Org Admin (2) only. Re-asserted every run.
        foreach ([1, 2] as $roleId) {
            $roleExists = db_fetch_one("SELECT id FROM `{$prefix}roles` WHERE id = ?", [$roleId]);
            if (!$roleExists) continue;
            $stmt = db_query(
                "INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`)
                 VALUES (?, ?)",
                [$roleId, $permId]
            );
            $granted += $stmt ? $stmt->rowCount() : 0;
        }
        echo "  permission action.delete_ics_form ready"
            . ($granted > 0 ? " (+{$granted} default grant(s))" : ' (grants already present)') . "\n";
        $changes += $granted;
    } else {
        echo "  [WARN] action.delete_ics_form: could not resolve permission id\n";
    }
} catch (Throwable $e) {
    // permissions/role_permissions may not exist yet (RBAC not installed).
    echo "  [WARN] action.delete_ics_form: " . $e->getMessage() . "\n";
}

echo "\nDone — {$changes} change(s). Re-running is safe.\n";
