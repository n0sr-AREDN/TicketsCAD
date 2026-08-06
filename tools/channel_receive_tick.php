<?php
/**
 * Phase 134 (2026-08) — inbound channel-poll tick (GH #23 Model 3, Step 4).
 *
 * Run once per minute. Each invocation polls every registered broker
 * channel that has declared itself 'pollable' AND has its
 * `{channel}_poll_inbound` setting turned on (Settings → Telegram / Slack)
 * — see inc/channel-receive.php's channel_receive_run() for the full
 * behaviour, including the per-channel backoff on repeated failure.
 *
 * No-op (0 channels polled) when nothing has opted in — this job is safe
 * to schedule on every install, whether or not Telegram/Slack polling is
 * configured; Settings → System Health only flags it as required once an
 * operator has actually turned a channel's polling on
 * (inc/scheduled-jobs.php's sched_job_required('channel_receive_tick')).
 *
 * INSTALLING THIS — CHECK THAT A SCHEDULER EXISTS FIRST
 * -----------------------------------------------------
 * Same lesson as par_tick.php / pending_messages_tick.php (Phase 127): a
 * file in /etc/cron.d on a host without cron fails completely silently.
 *
 *   systemctl is-active cron                              # "not-found" => no cron daemon
 *   systemctl list-timers --all | grep channel-receive    # is a timer scheduled instead?
 *   journalctl -u ticketscad-channel-receive-tick -n 20   # what the last runs actually did
 *
 * Prefer a systemd timer, which needs no extra package. Unit files and
 * install steps: docs/MAINTENANCE-RUNBOOK.md, "Scheduled background jobs".
 *
 *   sudo systemctl enable --now ticketscad-channel-receive-tick.timer
 *
 * On Windows, this ships as a third block in tools/run-scheduled-jobs.bat
 * under the SAME Task Scheduler entry as par_tick/pending_messages_tick —
 * see that file's header comment.
 *
 * Whichever you use, Settings → System Health → Scheduled jobs now shows the
 * last successful run and goes red when this job stops (once it is required —
 * see above).
 *
 * Usage: php tools/channel_receive_tick.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/channel-receive.php';
require_once __DIR__ . '/../inc/scheduled-jobs.php';

$t0 = microtime(true);
$ts = date('Y-m-d H:i:s');

try {
    $r = channel_receive_run();
    $polled  = count($r['channels_polled'] ?? []);
    $skippedDisabled = count($r['skipped_disabled'] ?? []);
    $skippedBackoff  = count($r['skipped_backoff'] ?? []);
    $errors  = count($r['errors'] ?? []);
    $detail = "channels_polled={$polled} messages_received=" . (int) ($r['messages_received'] ?? 0)
            . " skipped_disabled={$skippedDisabled} skipped_backoff={$skippedBackoff}"
            . " errors={$errors}";
    echo "[{$ts}] channel_receive_tick: {$detail}\n";
    // A channel-level error is recorded as a per-channel failure (and backs
    // that channel off) but is not itself fatal to the tick — matching
    // par_tick's shape, this job still reports 'ok' as long as it completed
    // its sweep, not as long as every channel individually succeeded.
    sched_job_record('channel_receive_tick', 'ok', $detail, (int) round((microtime(true) - $t0) * 1000));
    exit(0);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    fwrite(STDERR, "[{$ts}] channel_receive_tick FAILED: {$msg}\n");
    sched_job_record('channel_receive_tick', 'error', $msg, (int) round((microtime(true) - $t0) * 1000));
    exit(1);
}
