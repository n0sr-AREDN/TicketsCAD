<?php
/**
 * Soft-delete columns (deleted_at / deleted_by) on member, responder, ticket
 * and facilities — as a MIGRATION, so upgrades get them too.
 *
 * REPORTED BY: Chris Byrd, 2026-07-26, after converting a ZIP install to git.
 * tools/check-schema.php told him:
 *
 *     missing on `facilities`:  deleted_at, deleted_by
 *     missing on `responder`:   deleted_at, deleted_by
 *     missing on `ticket`:      deleted_at, deleted_by
 *
 * …and then `--repair` could not fix it, which is the path that tells the user
 * "a real gap in TicketsCAD, not something you did wrong". It was exactly that.
 *
 * THE GAP. sql/soft_delete_mileage.sql has always contained the right ALTERs.
 * But it was referenced ONLY from tools/install_fresh.php (line 319), and
 * sql/run_migrations.php discovers work with glob('sql/run_*.php'). So:
 *
 *     fresh install  -> install_fresh.php -> soft_delete_mileage.sql   applied
 *     upgrade        -> run_migrations.php -> sql/run_*.php only        SKIPPED
 *     --repair       -> run_migrations.php --force                     SKIPPED
 *
 * Every install that was UPGRADED rather than built fresh has been missing
 * these columns, silently, since soft delete shipped. It only became visible
 * now because Phase 125 added a check that compares the live schema against
 * what the code writes — the checker was right, the repair had nothing to run.
 *
 * This is the pitfall CLAUDE.md already records ("Feature .sql files must be
 * wired into the install pipeline"), which bit facilities.bed_auto_mode the
 * same way. A feature schema needs a run_*.php wrapper so BOTH paths converge.
 *
 * Idempotent: checks information_schema before each ALTER, adds only what is
 * absent, deletes nothing.
 */

declare(strict_types=1);

chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/db.php';

$prefix  = $GLOBALS['db_prefix'] ?? '';
$changes = 0;

echo "Soft-delete columns (deleted_at / deleted_by)\n";
echo "============================================\n";

/** Tables that carry soft delete, and why each one needs it. */
$targets = [
    'member'     => 'Roster — wastebasket restore for people',
    'responder'  => 'Units — wastebasket restore for units',
    'ticket'     => 'Incidents — wastebasket restore for incidents',
    'facilities' => 'Facilities — wastebasket restore for facilities',
];

foreach ($targets as $table => $why) {
    $full = $prefix . $table;

    try {
        $exists = (int) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$full]
        );
        if ($exists === 0) {
            echo "  skip `{$full}` — table not present on this install\n";
            continue;
        }

        foreach ([
            'deleted_at' => 'DATETIME DEFAULT NULL',
            'deleted_by' => 'INT DEFAULT NULL',
        ] as $col => $ddl) {
            $has = (int) db_fetch_value(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$full, $col]
            );
            if ($has === 0) {
                db_query("ALTER TABLE `{$full}` ADD COLUMN `{$col}` {$ddl}");
                echo "  added `{$full}`.`{$col}`   ({$why})\n";
                $changes++;
            }
        }

        // The wastebasket and every list query filter on deleted_at; without an
        // index that is a full scan on the busiest tables in the app.
        $idx = (int) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'idx_deleted_at'",
            [$full]
        );
        if ($idx === 0) {
            try {
                db_query("ALTER TABLE `{$full}` ADD INDEX `idx_deleted_at` (`deleted_at`)");
                echo "  indexed `{$full}`.`deleted_at`\n";
                $changes++;
            } catch (Throwable $e) {
                echo "  [note] could not index `{$full}`.`deleted_at`: " . $e->getMessage() . "\n";
            }
        }
    } catch (Throwable $e) {
        echo "  [WARN] {$full}: " . $e->getMessage() . "\n";
    }
}

echo "\nDone — {$changes} change(s). Re-running is safe.\n";
