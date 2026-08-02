<?php
/**
 * Phase 16b (2026-06-11) — PAR scheduler tick.
 *
 * Run once per minute. Each invocation:
 *   - Auto-initiates 'scheduled' PAR cycles for active incidents whose
 *     cadence has elapsed
 *   - Marks unit acks as 'missed' when the cycle window has elapsed
 *     without an ack, posts a chat escalation if configured
 *   - Expires — without escalating — any cycle whose window shut longer
 *     than sched_stale_cutoff_min ago (Phase 127)
 *
 * INSTALLING THIS — CHECK THAT A SCHEDULER EXISTS FIRST
 * -----------------------------------------------------
 * This job was originally shipped as a /etc/cron.d drop-in. Both servers
 * running TicketsCAD turned out to have no cron daemon installed, so it
 * never ran once between 11 June and 29 July 2026 and nothing reported it.
 * A file in /etc/cron.d on a host without cron fails completely silently.
 *
 *   systemctl is-active cron                     # "not-found" => no cron daemon
 *   systemctl list-timers --all | grep par-tick  # is a timer scheduled instead?
 *   journalctl -u ticketscad-par-tick -n 20      # what the last runs actually did
 *
 * /var/log/par_tick.log belonged to the cron line. The timer unit logs to the
 * journal, so that file stays 0 bytes on a healthy host — an empty log is no
 * longer evidence of anything.
 *
 * Prefer a systemd timer, which needs no extra package. Unit files and
 * install steps: docs/MAINTENANCE-RUNBOOK.md, "Scheduled background jobs".
 *
 *   sudo systemctl enable --now ticketscad-par-tick.timer
 *
 * Whichever you use, Settings → Status → Scheduled jobs now shows the last
 * successful run and goes red when this job stops.
 *
 * No-op when par_enabled=0 in settings.
 *
 * Usage: php tools/par_tick.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/par.php';
require_once __DIR__ . '/../inc/scheduled-jobs.php';

$t0 = microtime(true);
$ts = date('Y-m-d H:i:s');

try {
    $r = par_run_scheduler();
    $detail = "cycles_started={$r['cycles_started']} units_missed={$r['units_missed']}"
            . ' units_expired=' . ($r['units_expired'] ?? 0)
            . ' cycles_expired=' . ($r['cycles_expired'] ?? 0);
    if (isset($r['reason'])) $detail .= " reason={$r['reason']}";
    echo "[{$ts}] par_tick: {$detail}\n";
    sched_job_record('par_tick', 'ok', $detail, (int) round((microtime(true) - $t0) * 1000));
    exit(0);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    fwrite(STDERR, "[{$ts}] par_tick FAILED: {$msg}\n");
    sched_job_record('par_tick', 'error', $msg, (int) round((microtime(true) - $t0) * 1000));
    exit(1);
}
