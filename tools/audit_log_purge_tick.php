<?php
/**
 * Phase 133 (2026-08-03) — audit-log retention scheduled tick.
 *
 * Run once a day. Archives (gzip NDJSON) and removes newui_audit_log rows
 * older than `audit_log_retention_days`, if that setting is nonzero. A no-op,
 * exit 0, when retention is disabled — see inc/audit-retention.php for the
 * full model.
 *
 * INSTALLING THIS — CHECK THAT A SCHEDULER EXISTS FIRST. This project has
 * twice shipped a background job as a cron/cron.d drop-in onto hosts with no
 * cron daemon at all, and it failed completely silently both times (see
 * CLAUDE.md, "A file in /etc/cron.d on a host with NO cron daemon fails
 * completely silently"). Verify before relying on anything:
 *
 *   systemctl is-active cron                          # "not-found" => no cron daemon
 *   systemctl list-timers --all | grep audit-log       # is a timer scheduled instead?
 *
 * Prefer a systemd timer — see docs/MAINTENANCE-RUNBOOK.md, "Scheduled
 * background jobs", for the unit-file template (same shape as
 * ticketscad-par-tick.service/.timer):
 *
 *   sudo systemctl enable --now ticketscad-audit-purge.timer
 *
 * Whichever scheduler you use, Settings -> Audit Log -> Retention & Purge and
 * Settings -> System Health -> Scheduled Jobs both show the last run and turn
 * red when this job stops (but ONLY once retention is actually turned on —
 * an install with retention off is never nagged about a job it does not
 * need; see sched_job_required('audit_log_purge') in inc/scheduled-jobs.php).
 *
 * A run that fails because the database user cannot DELETE from
 * newui_audit_log (the expected result of following the tamper-resistance
 * advice in docs/CJIS-POSTURE.md) is reported as a FAILURE here and on both
 * status pages — never a silent no-op.
 *
 * Usage: php tools/audit_log_purge_tick.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/audit-retention.php';
require_once __DIR__ . '/../inc/scheduled-jobs.php';

$t0 = microtime(true);
$ts = date('Y-m-d H:i:s');

try {
    $r = audit_purge_run(['triggered_by' => 'scheduled']);
    $ms = (int) round((microtime(true) - $t0) * 1000);

    if (!empty($r['skipped'])) {
        echo "[{$ts}] audit_log_purge_tick: {$r['detail']}\n";
        sched_job_record('audit_log_purge', 'ok', $r['detail'], $ms);
        exit(0);
    }
    if ($r['ok']) {
        echo "[{$ts}] audit_log_purge_tick: {$r['detail']}\n";
        sched_job_record('audit_log_purge', 'ok', $r['detail'], $ms);
        exit(0);
    }

    $detail = $r['detail'] ?? ($r['error'] ?? 'unknown failure');
    fwrite(STDERR, "[{$ts}] audit_log_purge_tick FAILED: {$detail}\n");
    sched_job_record('audit_log_purge', 'error', $detail, $ms);
    exit(1);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    $ms  = (int) round((microtime(true) - $t0) * 1000);
    fwrite(STDERR, "[{$ts}] audit_log_purge_tick FAILED: {$msg}\n");
    sched_job_record('audit_log_purge', 'error', $msg, $ms);
    exit(1);
}
