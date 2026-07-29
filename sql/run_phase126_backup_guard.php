<?php
/**
 * Phase 126 (2026-07-29) — make automatic backups real, and make them safe.
 *
 * Two things happen on this upgrade, and this script exists because of the
 * second one.
 *
 * 1. Automatic backups start ACTUALLY RUNNING. backup_maybe_run_opportunistic()
 *    had been defined and called from nowhere since Phase 122, so on any
 *    install without cron / Task Scheduler — the common case for this audience
 *    — automatic backups reported ON and produced nothing. inc/navbar.php now
 *    ticks the scheduler.
 *
 * 2. Which means, without this script, the FIRST page load after upgrading
 *    would find backup_last_run_at unset, decide a backup is due, and dump the
 *    whole database while the operator is still upgrading. On a Raspberry Pi
 *    with a large database that is a genuinely unpleasant surprise, and it is
 *    exactly the "don't silently start writing backups because they upgraded"
 *    failure. So we seed backup_last_run_at to place the first automatic
 *    backup roughly an hour out: not during the upgrade, but still today.
 *
 * Nothing here turns a feature on or off. backup_auto_enabled is deliberately
 * NOT written: an operator who has explicitly set it keeps their value, and
 * everyone else keeps the Phase 122 code default (ON). Writing it here would
 * only freeze today's default into their database.
 *
 * No schema change — these are rows in the existing `settings` table, written
 * with the same upsert the Settings UI uses and read back with get_variable().
 * Idempotent: safe to re-run, and it will not move a clock that is already set.
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/backup_schedule.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 126 — automatic backup guard\n";
echo "==================================\n\n";

$exit = 0;

try {
    // Is there already a run clock? get_variable() returns false when absent.
    $existing = get_variable('backup_last_run_at');
    $hasClock = ($existing !== false && $existing !== null && $existing !== '' && (int) $existing > 0);

    if ($hasClock) {
        echo "  backup_last_run_at already set (" . date('Y-m-d H:i', (int) $existing) . ") — left alone.\n";
    } else {
        $interval = backup_interval_hours();
        // Place the first automatic backup ~1 hour from now. Clamp so a short
        // interval cannot produce a clock in the future (which would postpone
        // the first backup by a whole interval instead of an hour).
        $delay  = min(3600, max(900, $interval * 3600));   // 15 min … 1 hour
        $seed   = time() - ($interval * 3600) + $delay;
        $seed   = min($seed, time());                       // never in the future

        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            ['backup_last_run_at', (string) $seed]
        );
        echo "  Seeded backup_last_run_at — first automatic backup due about "
           . date('H:i', $seed + $interval * 3600) . " (in ~" . round($delay / 60) . " min).\n";
    }

    // Report the policy the install will run under, so the upgrade log shows
    // what was decided rather than leaving it to be discovered later.
    echo "\n  Effective policy:\n";
    echo "    Automatic backups : " . (backup_auto_enabled() ? 'ON' : 'OFF') . "\n";
    echo "    Without scheduler : " . (backup_opportunistic_enabled() ? 'ON' : 'OFF') . "\n";
    echo "    Interval          : " . backup_interval_hours() . "h\n";
    echo "    Keep              : " . backup_retention_count() . " copies"
       . (backup_retention_days() > 0 ? ', max ' . backup_retention_days() . ' days old' : '') . "\n";
    echo "    Reserve free disk : " . backup_format_size(backup_min_free_bytes()) . "\n";
    echo "    Folder limit      : " . (backup_max_dir_bytes() > 0
                                        ? backup_format_size(backup_max_dir_bytes()) : 'none') . "\n";
    echo "    Folder            : " . backup_dir() . "\n";
    echo "\n  Change any of this in Settings -> Backup / Maintenance -> Automatic Backups.\n";

} catch (Throwable $e) {
    // Must exit non-zero on failure: sql/run_migrations.php decides success by
    // the child's EXIT CODE. Printing an error and exiting 0 makes a broken
    // migration look applied.
    echo "  FAILED: " . $e->getMessage() . "\n";
    $exit = 1;
}

echo "\nDone.\n";
exit($exit);
