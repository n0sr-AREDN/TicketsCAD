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
 * CHECK THAT YOU ACTUALLY HAVE CRON BEFORE YOU RELY ON IT. Dropping a file into
 * /etc/cron.d is silent when no cron daemon is installed, and minimal Debian
 * cloud images frequently ship without one — both TicketsCAD beta servers had
 * /etc/cron.d entries that had never executed once. Verify, don't assume:
 *   systemctl is-active cron   # "inactive"/"not-found" => nothing is scheduled
 *
 * systemd timer (no cron package needed; survives reboots properly). See
 * docs/BACKUP-RECOVERY-RUNBOOK.md "Method C2" for the two unit files; the
 * timer wants Persistent=true so a machine that was switched off at the
 * scheduled hour backs up at next boot rather than skipping silently.
 *
 * Windows Task Scheduler: run C:\xampp\php\php.exe with the argument
 *   C:\xampp\htdocs\ticketscad\tools\backup_run.php
 *
 * OWNERSHIP OF backups/ — it has TWO writers, so it must be shared:
 *   - you, when you run this by hand (`php tools/backup_run.php --force`)
 *   - the web server user, via that cron line and Settings → Backup / Maintenance
 * Hand the directory entirely to www-data and the manual run fails with
 * "could not write archive"; keep it entirely yours and the scheduled/web
 * backup fails instead. The working shape (see docs/UPDATE-CHECKLIST.md §1):
 *   mkdir -p backups
 *   sudo chown -R "$(id -un)":www-data backups/ && sudo chmod 2770 backups/
 *
 * DOCKER: backups/ must be on a volume, or `docker compose up -d --build`
 * destroys it with the container. The shipped docker-compose.yml mounts
 * app_backups at /var/www/html/backups — see docs/DOCKER.md §4.
 *
 * Exit codes: 0 = success or nothing due, 1 = the backup failed.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

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
