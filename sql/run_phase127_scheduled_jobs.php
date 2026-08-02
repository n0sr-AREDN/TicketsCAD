<?php
/**
 * Phase 127 (2026-07-29) — scheduled-job heartbeat + stale-work cutoff.
 *
 * Creates:
 *   scheduled_job_runs        one row per background job; the heartbeat that
 *                             health_check_scheduled_jobs() reads.
 *
 * Widens three ENUMs with an 'expired' state so that work a sweep declines
 * to perform retroactively is recorded rather than silently dropped or
 * deleted:
 *   pending_routed_messages.status  += 'expired'
 *   par_cycles.status               += 'expired'
 *   par_unit_acks.state             += 'expired'
 *
 * Seeds:
 *   sched_stale_cutoff_min = 60   (minutes; 0 disables the cutoff)
 *
 * Idempotent: safe to re-run. run_migrations.php decides success from this
 * script's EXIT CODE, so every failure path must exit non-zero.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$exit    = 0;
$changes = 0;

function p127_say(string $m): void { echo "  {$m}\n"; }

function p127_table_exists(string $t): bool {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        return (bool) db_fetch_one(
            "SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1",
            [$prefix . $t]);
    } catch (Exception $e) { return false; }
}

/** Current column TYPE string, or '' when the table/column is absent. */
function p127_column_type(string $t, string $c): string {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $v = db_fetch_value(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1",
            [$prefix . $t, $c]);
        return (string) ($v ?: '');
    } catch (Exception $e) { return ''; }
}

echo "Phase 127 — scheduled job heartbeat + stale-work cutoff\n";

// ── 1. scheduled_job_runs ────────────────────────────────────────────────
try {
    if (!p127_table_exists('scheduled_job_runs')) {
        db_query("
            CREATE TABLE `{$prefix}scheduled_job_runs` (
              `job_key`          VARCHAR(64)      NOT NULL,
              `last_run_at`      DATETIME         DEFAULT NULL,
              `last_ok_at`       DATETIME         DEFAULT NULL,
              `last_status`      ENUM('ok','error') NOT NULL DEFAULT 'ok',
              `last_detail`      VARCHAR(255)     DEFAULT NULL,
              `last_duration_ms` INT UNSIGNED     DEFAULT NULL,
              `run_count`        BIGINT UNSIGNED  NOT NULL DEFAULT 0,
              `error_count`      BIGINT UNSIGNED  NOT NULL DEFAULT 0,
              PRIMARY KEY (`job_key`),
              KEY `idx_last_ok` (`last_ok_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        p127_say('[new] created scheduled_job_runs');
        $changes++;
    } else {
        p127_say('[ok]  scheduled_job_runs already exists');
    }
} catch (Throwable $e) {
    p127_say('[ERR] scheduled_job_runs: ' . $e->getMessage());
    $exit = 1;
}

// ── 2. Widen the three ENUMs with 'expired' ──────────────────────────────
// Each guarded on the CURRENT type, so a re-run is a no-op and an install
// that already has the value is left alone.
$enumWork = [
    ['pending_routed_messages', 'status',
     "ENUM('pending','sent','killed','failed','expired') NOT NULL DEFAULT 'pending'"],
    ['par_cycles', 'status',
     "ENUM('pending','complete','aborted','expired') NOT NULL DEFAULT 'pending'"],
    ['par_unit_acks', 'state',
     "ENUM('pending','acked','missed','aborted','expired') NOT NULL DEFAULT 'pending'"],
];

foreach ($enumWork as $w) {
    list($tbl, $col, $newType) = $w;
    try {
        if (!p127_table_exists($tbl)) {
            p127_say("[skip] {$tbl} not present on this install");
            continue;
        }
        $cur = p127_column_type($tbl, $col);
        if ($cur === '') {
            p127_say("[skip] {$tbl}.{$col} not present");
            continue;
        }
        if (strpos($cur, "'expired'") !== false) {
            p127_say("[ok]  {$tbl}.{$col} already accepts 'expired'");
            continue;
        }
        db_query("ALTER TABLE `{$prefix}{$tbl}` MODIFY COLUMN `{$col}` {$newType}");
        p127_say("[new] {$tbl}.{$col} widened with 'expired'");
        $changes++;
    } catch (Throwable $e) {
        p127_say("[ERR] {$tbl}.{$col}: " . $e->getMessage());
        $exit = 1;
    }
}

// ── 3. Seed the cutoff setting ───────────────────────────────────────────
// INSERT IGNORE so an operator's chosen value survives a re-run.
try {
    if (p127_table_exists('settings')) {
        db_query("INSERT IGNORE INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)",
                 ['sched_stale_cutoff_min', '60']);
        if (db_insert_id() > 0) {
            p127_say('[new] seeded sched_stale_cutoff_min=60');
            $changes++;
        } else {
            p127_say('[ok]  sched_stale_cutoff_min already set');
        }
    }
} catch (Throwable $e) {
    p127_say('[ERR] settings seed: ' . $e->getMessage());
    $exit = 1;
}

echo ($exit === 0)
    ? "Phase 127 complete ({$changes} change(s)).\n"
    : "Phase 127 FAILED — see [ERR] lines above.\n";

exit($exit);
