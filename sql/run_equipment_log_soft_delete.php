<?php
/**
 * Soft-delete columns (deleted_at / deleted_by) on `newui_equipment_log`,
 * plus the `action.delete_equipment_log` RBAC permission and its default
 * grants.
 *
 * REQUESTED BY: Chris Byrd, GitHub #38, 2026-08-07:
 *
 *     "Would like to be able to delete the Activity Log entries for
 *      equipment checked in and out... Be able to delete like the delete
 *      function on the ICS Forms."
 *
 * Same policy as ICS forms (sql/run_ics_forms_soft_delete.php), Eric's
 * explicit call this time: "admin-only is the right default for something
 * that removes an audit trail entry." Unlike ICS forms there is no
 * creator-may-delete-their-own-draft exception — this is admin-only, full
 * stop, no ownership carve-out. Unlike ICS forms, this type IS purgeable
 * from the wastebasket (api/wastebasket.php) — a mis-logged checkout/checkin
 * line is lower-stakes than a finalized incident record, so permanent
 * removal after review is reasonable rather than forever-recoverable.
 *
 * WHY A run_*.php WRAPPER AND NOT ONLY sql/equipment.sql. sql/run_migrations.php
 * discovers work with glob('sql/run_*.php'); a change folded only into the
 * original CREATE-TABLE script reaches fresh installs and never reaches
 * upgrades. See sql/run_ics_forms_soft_delete.php's own header for the three
 * prior times this exact gap shipped.
 *
 * WHY THE PERMISSION IS GRANTED EXPLICITLY HERE, NOT JUST IN sql/rbac.sql.
 * The "Super Admin gets EVERYTHING" seed there is an INSERT ... SELECT that
 * ran once at install; a permission added later is not granted retroactively
 * by it. Roles 1 and 2 are granted here by id, re-asserted every run.
 *
 * Idempotent: checks information_schema before each ALTER, INSERT IGNORE on
 * the permission and the grants, adds only what is absent, deletes nothing.
 * Safe to re-run.
 *
 * Usage: php sql/run_equipment_log_soft_delete.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/db.php';

$prefix  = $GLOBALS['db_prefix'] ?? '';
$changes = 0;

echo "Equipment log soft delete (deleted_at / deleted_by + action.delete_equipment_log)\n";
echo "==================================================================================\n";

// ── 1. Schema ────────────────────────────────────────────────────────────
$table = $prefix . 'newui_equipment_log';

try {
    $exists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$table]
    );

    if ($exists === 0) {
        echo "  skip `{$table}` — table not present yet (run sql/run_equipment.php first)\n";
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
            'action.delete_equipment_log',
            'Delete Equipment Log Entries',
            'action',
            'Delete a checked-out/checked-in activity log entry for a piece of equipment. '
                . 'Admin-only, no ownership exception.',
        ]
    );

    $permId = (int) db_fetch_value(
        "SELECT id FROM `{$prefix}permissions` WHERE `code` = ?",
        ['action.delete_equipment_log']
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
        echo "  permission action.delete_equipment_log ready"
            . ($granted > 0 ? " (+{$granted} default grant(s))" : ' (grants already present)') . "\n";
        $changes += $granted;
    } else {
        echo "  [WARN] action.delete_equipment_log: could not resolve permission id\n";
    }
} catch (Throwable $e) {
    echo "  [WARN] action.delete_equipment_log: " . $e->getMessage() . "\n";
}

echo "\nDone — {$changes} change(s). Re-running is safe.\n";
