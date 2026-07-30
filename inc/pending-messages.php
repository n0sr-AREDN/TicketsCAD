<?php
/**
 * NewUI v4.0 — Pending routed messages (Phase 18e, 2026-06-11).
 *
 * Eric's spec ask: instead of post-send recall (which depends on
 * fragile protocol delete APIs), queue routed messages with a per-
 * label send delay. During the delay window, dispatchers can Kill
 * the row; killed messages NEVER go out.
 *
 * Public API:
 *
 *   pending_enqueue(array $msg): ?int
 *     Stash a routed message in the queue. Returns the row id, or null.
 *     The routing engine calls this when the resolved security label
 *     has routing_send_delay_secs > 0.
 *
 *   pending_list(string $status='pending', int $limit=50): array
 *     Rows for the admin/dispatcher UI.
 *
 *   pending_kill(int $id, ?int $userId, ?string $reason): bool
 *     Mark a row 'killed'. The cron sweep will not send killed rows.
 *
 *   pending_sweep(?int $now=null): array
 *     Scheduler entry point. For each pending row whose scheduled_send_at
 *     has passed, dispatch via the broker and mark sent/failed. Caps
 *     work at 200 rows per tick to avoid runaway.
 *
 * STALE WORK IS NOT DELIVERED (Phase 127, 2026-07-29)
 * ---------------------------------------------------
 * This sweep was installed as a cron job on hosts that had no cron daemon,
 * so it never ran once in seven weeks. The queue holds messages that were
 * deliberately delayed by a few minutes to give a dispatcher a kill window
 * — not messages that are meant to arrive in the middle of next month. The
 * first successful tick after a long outage would otherwise deliver the
 * entire backlog at once to a live emergency-response team, out of context
 * and possibly contradicting whatever happened since.
 *
 * So a row more than sched_stale_cutoff_min minutes past its scheduled time
 * is moved to status='expired' and NOT sent. The row is kept, not deleted;
 * send_error records the scheduled time, the age and the cutoff that
 * governed the call, so "why did I not get this?" has an answer. An
 * operator who decides a message should still go out can set it back to
 * 'pending' with a fresh scheduled_send_at.
 */

require_once __DIR__ . '/scheduled-jobs.php';

function pending_enqueue(array $msg): ?int {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query("
            INSERT INTO `{$prefix}pending_routed_messages`
                (ticket_id, route_id, channel, target, subject, body, priority,
                 scheduled_send_at, created_by, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
            [
                $msg['ticket_id']      ?? null,
                $msg['route_id']       ?? null,
                $msg['channel']        ?? '',
                $msg['target']         ?? '',
                $msg['subject']        ?? null,
                $msg['body']           ?? '',
                $msg['priority']       ?? null,
                $msg['scheduled_send_at'] ?? date('Y-m-d H:i:s'),
                $msg['created_by']     ?? null,
            ]
        );
        return (int) db_insert_id();
    } catch (Exception $e) { return null; }
}

function pending_list(string $status = 'pending', int $limit = 50): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $limit = max(1, min(200, $limit));
    try {
        return db_fetch_all(
            "SELECT * FROM `{$prefix}pending_routed_messages`
              WHERE status = ?
              ORDER BY scheduled_send_at ASC
              LIMIT {$limit}", [$status]);
    } catch (Exception $e) { return []; }
}

function pending_kill(int $id, ?int $userId, ?string $reason): bool {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query("
            UPDATE `{$prefix}pending_routed_messages`
               SET status = 'killed',
                   killed_at = NOW(),
                   killed_by = ?,
                   killed_reason = ?
             WHERE id = ? AND status = 'pending'",
            [$userId, $reason, $id]
        );
        if (function_exists('audit_log')) {
            audit_log('routing', 'kill', 'pending_message', $id,
                "Killed pending routed message #{$id}", ['reason' => $reason]);
        }
        return true;
    } catch (Exception $e) { return false; }
}

function pending_sweep(?int $now = null, ?int $cutoffMin = null): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if ($now === null) $now = time();
    $nowStr = date('Y-m-d H:i:s', $now);
    $sent = 0; $failed = 0; $considered = 0; $expired = 0;
    try {
        $rows = db_fetch_all(
            "SELECT * FROM `{$prefix}pending_routed_messages`
              WHERE status = 'pending'
                AND scheduled_send_at <= ?
              ORDER BY scheduled_send_at ASC
              LIMIT 200", [$nowStr]);
    } catch (Exception $e) {
        return ['considered' => 0, 'sent' => 0, 'failed' => 0, 'expired' => 0];
    }

    foreach ($rows as $r) {
        $considered++;

        // ── Stale-work cutoff ────────────────────────────────────────────
        // Too far past due to deliver as if it were current. Expire it,
        // recording why, and move on. Never delete: the message is user
        // data and the decision must be auditable and reversible.
        $dueTs = strtotime((string) $r['scheduled_send_at']);
        if ($dueTs && sched_is_stale($dueTs, $now, $cutoffMin)) {
            $reason = sched_expiry_reason((string) $r['scheduled_send_at'], $dueTs, $now, $cutoffMin);
            try {
                db_query("UPDATE `{$prefix}pending_routed_messages`
                             SET status = 'expired', send_error = ?
                           WHERE id = ? AND status = 'pending'", [substr($reason, 0, 255), $r['id']]);
                $expired++;
                if (function_exists('audit_log')) {
                    audit_log('routing', 'expire', 'pending_message', (int) $r['id'],
                        "Not delivered — {$reason}", [
                            'channel'           => $r['channel'],
                            'target'            => $r['target'],
                            'scheduled_send_at' => $r['scheduled_send_at'],
                            'ticket_id'         => $r['ticket_id'],
                        ]);
                }
            } catch (Exception $e) {
                error_log('pending_sweep expire failed for #' . $r['id'] . ': ' . $e->getMessage());
            }
            continue;
        }

        $ok = false;
        $err = null;
        if (function_exists('broker_send')) {
            try {
                // Phase 44 (Sonar php:S930): broker_send signature is
                // (channel, message_array). Earlier this passed a single
                // array; the channel field never reached the broker and the
                // retry silently went to the default channel. Fixed alongside
                // the matching bug in inc/par.php (commit c0c6677).
                $resp = broker_send($r['channel'], [
                    'from'     => 'pending-sweep',
                    'target'   => $r['target'],
                    'subject'  => $r['subject'],
                    'body'     => $r['body'],
                    'priority' => $r['priority'] ?? 'normal',
                    '_is_routed_forward' => true,
                ]);
                $ok = !empty($resp['success']);
                if (!$ok) $err = $resp['error'] ?? 'broker rejected';
            } catch (Exception $e) {
                $err = $e->getMessage();
            }
        } else {
            // No broker available — mark failed and log so an admin can investigate.
            $err = 'broker_send() not loaded';
        }
        try {
            if ($ok) {
                db_query("UPDATE `{$prefix}pending_routed_messages`
                             SET status = 'sent', sent_at = NOW(), send_error = NULL
                           WHERE id = ?", [$r['id']]);
                $sent++;
            } else {
                db_query("UPDATE `{$prefix}pending_routed_messages`
                             SET status = 'failed', send_error = ?
                           WHERE id = ?", [substr($err ?? 'unknown', 0, 255), $r['id']]);
                $failed++;
            }
        } catch (Exception $e) {}
    }
    return ['considered' => $considered, 'sent' => $sent,
            'failed' => $failed, 'expired' => $expired];
}
