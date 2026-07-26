<?php
/**
 * Phase 122 — scheduled backup runner (CLI).
 *
 * Run me from cron (Linux) or Task Scheduler (Windows) for exact timing. If you
 * never set up a scheduler, TicketsCAD still takes opportunistic backups on
 * ordinary page requests — this just makes the timing predictable.
 *
 *   php tools/backup_run.php            # back up only if one is due
 *   php tools/backup_run.php --force    # back up now regardless
 *   php tools/backup_run.php --status   # report, change nothing
 *
 * Linux cron, hourly (the schedule itself is the interval setting):
 *   0 * * * * www-data php /var/www/newui/tools/backup_run.php >/dev/null 2>&1
 *
 * Windows Task Scheduler: run C:\xampp\php\php.exe with the argument
 *   C:\xampp\htdocs\ticketscad\tools\backup_run.php
 *
 * Exit codes: 0 = success or nothing due, 1 = the backup failed.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/backup_schedule.php';

$force  = in_array('--force',  $argv, true);
$status = in_array('--status', $argv, true);

function say(string $s): void { echo '[' . date('H:i:s') . "] $s\n"; }

if ($status) {
    $s = backup_status();
    say('Automatic backups: ' . ($s['enabled'] ? 'ON' : 'OFF'));
    say('Every: ' . $s['interval_hours'] . 'h, keeping ' . $s['retention_count'] . ' copies');
    say('Directory: ' . $s['directory']);
    say('Last verified success: ' . ($s['last_ok_at'] ? date('Y-m-d H:i', $s['last_ok_at'])
        . ' (' . $s['last_ok_age_hours'] . 'h ago)' : 'never'));
    say('Last status: ' . $s['last_status']);
    if ($s['stale']) say('WARNING: ' . $s['warning']);
    exit(0);
}

if (!$force && !backup_is_due()) {
    say('No backup due yet (last run within the configured interval). Use --force to override.');
    exit(0);
}

if (!$force && !backup_auto_enabled()) {
    say('Automatic backups are disabled in Settings. Use --force for a one-off.');
    exit(0);
}

say('Starting backup…');
$r = backup_run_now();
if ($r['ok']) {
    say('OK — ' . $r['path']);
    say($r['detail']);
    exit(0);
}
say('FAILED — ' . $r['detail']);
say('Nothing was changed in your database; this only affects the backup copy.');
exit(1);
