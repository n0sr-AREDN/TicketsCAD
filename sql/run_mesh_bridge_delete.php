<?php
/**
 * Soft-delete columns for mesh_bridges, so test bridges can be removed from
 * the Mesh Bridges console instead of sitting in the list forever.
 *
 * REPORTED BY: Chris Byrd, 2026-07-28 — "Is there a way to delete a Mint
 * Bridge Token? I have been working trying to set this up but need to delete
 * test tokens."
 *
 * The answer was no, for two separate reasons:
 *
 *  1. api/mesh.php has a working `revoke` action, but assets/js/mesh-console.js
 *     never calls it. The bridge card RENDERS `revoked_at` ("revoked" in red)
 *     while offering no control that could ever produce that state — so the
 *     one thing the UI could do about a token was invisible.
 *
 *  2. api/mesh.php's docblock advertises `POST ?action=delete_bridge — soft-
 *     delete a bridge`. That action was never implemented. Anyone who read the
 *     header and called it got an unknown-action error.
 *
 * Revoking and deleting are genuinely different and both are wanted: revoke
 * kills the credential but keeps the bridge visible with its packet history
 * (right for a compromised token on a real bridge); delete takes a bridge you
 * created by mistake out of the list entirely (right for Chris's test tokens).
 *
 * Soft delete rather than DROP, matching member/responder/ticket/facilities:
 * mesh_packet_log rows carry bridge_id, and hard-deleting the parent would
 * orphan real received traffic.
 *
 * Idempotent: checks information_schema before each ALTER, adds only what is
 * absent, deletes nothing.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/db.php';

$prefix  = $GLOBALS['db_prefix'] ?? '';
$table   = $prefix . 'mesh_bridges';
$changes = 0;

echo "Mesh bridge soft-delete columns\n";
echo "===============================\n";

try {
    $exists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$table]
    );
    if ($exists === 0) {
        echo "  skip `{$table}` — table not present on this install\n";
        echo "\nDone — 0 change(s). Re-running is safe.\n";
        exit(0);
    }

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

    // The console lists bridges with `deleted_at IS NULL` on every poll.
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
} catch (Throwable $e) {
    echo "  [WARN] {$table}: " . $e->getMessage() . "\n";
}

echo "\nDone — {$changes} change(s). Re-running is safe.\n";
