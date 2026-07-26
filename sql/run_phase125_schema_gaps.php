<?php
/**
 * Phase 125b (2026-07-26) — close the two schema gaps a FRESH INSTALL had.
 *
 * HOW THESE WERE FOUND. Phase 125 added `tools/check-schema.php`, which compares
 * a live database against the columns the code writes to. Its first run on a
 * genuine fresh install of v4.1.1 — clone the public tag, empty database,
 * install_fresh + run_migrations — reported that the install did not satisfy its
 * own manifest. Two real gaps, neither of which any test had caught, because
 * every previous check ran against a long-lived developer database where both
 * objects happened to already exist.
 *
 * 1. `responder_notes` WAS NEVER CREATED BY ANY MIGRATION.
 *    Two endpoints (api/responder-note.php, api/unit-history.php) create it
 *    lazily with CREATE TABLE IF NOT EXISTS immediately before inserting, so
 *    WRITING a note worked. But three endpoints READ it —
 *    api/log.php, api/reports.php, api/responder-detail.php — and on a fresh
 *    install nothing had written yet, so the table did not exist and the Notes
 *    Log report (GH #81) queried a table that was not there.
 *    Lazy creation at the write site is not a schema; it is a table that exists
 *    only if you happen to have used the right feature first.
 *
 * 2. `user_tfa`.`last_used_counter` was only ever added by a self-heal.
 *    inc/totp.php catches the missing column and ALTERs it in on first use
 *    (replay protection). Correct as a safety net, wrong as the only way the
 *    column ever appears: until a user enrolls in 2FA the schema is incomplete,
 *    and the install reports itself as behind.
 *
 * Idempotent: safe to re-run, adds only what is absent, deletes nothing.
 */

declare(strict_types=1);

chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$changes = 0;

function say(string $s): void { echo "  $s\n"; }

echo "Phase 125b — fresh-install schema gaps\n";
echo "======================================\n";

// ── 1. responder_notes ───────────────────────────────────────────────────
// Definition matches the lazy CREATE in api/responder-note.php exactly, so an
// install that already made the table this way is unchanged.
try {
    $exists = db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'responder_notes']
    );
    if ((int) $exists === 0) {
        db_query("CREATE TABLE IF NOT EXISTS `{$prefix}responder_notes` (
            `id`           INT AUTO_INCREMENT PRIMARY KEY,
            `responder_id` INT NOT NULL,
            `category`     VARCHAR(32) NOT NULL DEFAULT 'general',
            `note`         TEXT NOT NULL,
            `by_user`      INT NOT NULL DEFAULT 0,
            `by_username`  VARCHAR(64) NOT NULL DEFAULT '',
            `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted_at`   DATETIME NULL,
            `deleted_by`   INT NULL,
            KEY `idx_responder_time` (`responder_id`, `created_at`),
            KEY `idx_category`       (`category`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        say("created `{$prefix}responder_notes` (unit notes + the Notes Log report read it)");
        $changes++;
    } else {
        say("`{$prefix}responder_notes` already present");
    }
} catch (Throwable $e) {
    say("[WARN] responder_notes: " . $e->getMessage());
}

// ── 1b. permission_review_dismissals ─────────────────────────────────────
// Same pattern: inc/rbac.php's rbac_ensure_dismissal_table() creates it lazily
// on first use, so it is absent on a fresh install (and was one of the four
// tables a beta tester lost to crash recovery — after which no migration
// re-created it, because none ever created it). Definition matches rbac.php.
try {
    $exists = db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'permission_review_dismissals']
    );
    if ((int) $exists === 0) {
        db_query("CREATE TABLE IF NOT EXISTS `{$prefix}permission_review_dismissals` (
            `id`            INT(11) NOT NULL AUTO_INCREMENT,
            `permission_id` INT(11) NOT NULL,
            `dismissed_by`  INT(11) NOT NULL,
            `dismissed_at`  DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_perm` (`permission_id`),
            KEY `idx_dismissed_by` (`dismissed_by`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        say("created `{$prefix}permission_review_dismissals` (RBAC permission review)");
        $changes++;
    } else {
        say("`{$prefix}permission_review_dismissals` already present");
    }
} catch (Throwable $e) {
    say("[WARN] permission_review_dismissals: " . $e->getMessage());
}

// ── 2. user_tfa.last_used_counter ────────────────────────────────────────
try {
    $tfaExists = db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'user_tfa']
    );
    if ((int) $tfaExists === 0) {
        say("`{$prefix}user_tfa` not present — nothing to do (run_01_tfa.php creates it)");
    } else {
        $col = db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'last_used_counter'",
            [$prefix . 'user_tfa']
        );
        if ((int) $col === 0) {
            db_query("ALTER TABLE `{$prefix}user_tfa`
                      ADD COLUMN `last_used_counter` BIGINT NULL DEFAULT NULL");
            say("added `{$prefix}user_tfa`.`last_used_counter` (TOTP replay protection)");
            $changes++;
        } else {
            say("`{$prefix}user_tfa`.`last_used_counter` already present");
        }
    }
} catch (Throwable $e) {
    say("[WARN] user_tfa.last_used_counter: " . $e->getMessage());
}

echo "\nPhase 125b complete — {$changes} change(s). Re-running is safe.\n";
