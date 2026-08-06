<?php
/**
 * Phase 134 — Inbound routing to the sender's assigned incident (Model 3,
 * GH #23), Step 4: the poller.
 *
 * specs/phase-134-inbound-routing-model3/{spec.md,plan.md}. This file is
 * the single core function tools/channel_receive_tick.php calls every tick
 * — the same shape as inc/par.php's par_run_scheduler() and inc/pending-
 * messages.php's pending_sweep(): a well-tested core function returning a
 * summary array, with a thin CLI wrapper that records the heartbeat.
 *
 * WHAT THIS DOES NOT DO (out of scope for this step — see plan.md §9):
 *   - Does NOT wire mi_attach_message_to_assigned_incidents() or the
 *     Model-1 general-chat fallback into router_evaluate() — that is
 *     Step 5. This file only calls broker_receive(), which (as of Step 4)
 *     already runs router_evaluate() internally for whatever routes exist
 *     TODAY; it adds no new route.
 *   - Does NOT enable polling for anyone. Both `telegram_poll_inbound` and
 *     `slack_poll_inbound` default to unset/'0' — a channel is only ever
 *     polled once an operator has explicitly opted in via Settings.
 *
 * CAPABILITY FLAG, NOT AN ALLOWLIST (plan.md §1): which channels this
 * poller is allowed to touch is read from the broker registry's
 * 'pollable' flag (channel_receive_pollable_channels() below), never from
 * a hardcoded list of channel codes. local_chat and every other channel
 * that has not opted in by setting 'pollable' => true in its own
 * broker_register() call is structurally invisible to this file — there
 * is no channel-name string anywhere in this file to keep in sync.
 *
 * PER-CHANNEL BACKOFF (plan.md §1 / §3): mirrors the CONCEPT of the
 * existing Zello reconnect backoff (a long-lived WS process at a
 * different architectural layer — proxy/ZelloUpstream.php — so the code
 * itself is not reused, just the idea): track consecutive failures and a
 * "don't retry before this time" cutoff per channel, in the `settings`
 * table under `{channel}_poll_fail_count` / `{channel}_poll_backoff_
 * until`, written with DIRECT db_query() calls rather than through
 * get_variable()'s request-cached read — the same reason
 * _telegram_set_update_offset() (Step 2) uses a direct query: this code
 * may write a channel's backoff state and read it again later in the SAME
 * process (repeated calls to channel_receive_run() within one long-lived
 * script, or between this run and the next channel in the same tick).
 * `{channel}_poll_inbound` — the opt-in switch itself — IS read through
 * get_variable() per the standing GH #79 rule (the Settings UI writes to
 * `settings`, never the separate `config` table `get_setting()` reads).
 */

if (!function_exists('broker_receive')) {
    require_once __DIR__ . '/broker.php';
}

/**
 * Capped exponential backoff, in whole minutes: 1, 2, 4, 8, 16, 32, then
 * capped at 60. A channel failing every tick is how an install gets
 * throttled by the upstream API (plan.md §1) — capping at once-an-hour
 * bounds the worst case for a channel that is simply broken (bad token,
 * upstream outage) without ever giving up on it entirely, matching the
 * "never silently stop" discipline the rest of this project's scheduled
 * jobs follow.
 */
if (!defined('CHANNEL_RECEIVE_BACKOFF_CAP_MIN')) {
    define('CHANNEL_RECEIVE_BACKOFF_CAP_MIN', 60);
}

function _cr_backoff_minutes(int $failCount): int {
    $failCount = max(1, $failCount);
    return min(CHANNEL_RECEIVE_BACKOFF_CAP_MIN, (int) (2 ** ($failCount - 1)));
}

/**
 * Every registered broker channel that has opted in to being pollable —
 * i.e. declared `'pollable' => true` in its own broker_register() call.
 * Reads the SAME registry broker_channel_statuses() reads, so this can
 * never drift from what is actually registered.
 *
 * @return array  List of ['code' => string, 'label' => string, 'handler' => array]
 */
function channel_receive_pollable_channels(): array {
    global $_broker_channels;
    $out = [];
    foreach (($_broker_channels ?? []) as $code => $handler) {
        if (($handler['pollable'] ?? null) !== true) continue;
        $label = is_callable($handler['name'] ?? null)
            ? call_user_func($handler['name'])
            : ($handler['name'] ?? ucfirst((string) $code));
        $out[] = ['code' => (string) $code, 'label' => (string) $label, 'handler' => $handler];
    }
    return $out;
}

/**
 * Read a channel's current backoff bookkeeping. Always a direct SELECT —
 * never get_variable() — see file header for why.
 *
 * @return array{fail_count:int, backoff_until:?int}  backoff_until is a
 *         unix timestamp, or null when the channel is not backing off (no
 *         recorded failure, or its last recorded failure was cleared by a
 *         subsequent success).
 */
function _cr_get_backoff_state(string $channel): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $failCount = 0;
    $backoffUntil = null;
    try {
        $fc = db_fetch_value(
            "SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?",
            [$channel . '_poll_fail_count']
        );
        if ($fc !== false && $fc !== null && $fc !== '') $failCount = max(0, (int) $fc);
    } catch (Exception $e) {
        // Treat as "no recorded failures" — a settings-read failure must
        // not itself look like a backing-off channel.
    }
    try {
        $bu = db_fetch_value(
            "SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?",
            [$channel . '_poll_backoff_until']
        );
        if ($bu !== false && $bu !== null && $bu !== '') $backoffUntil = (int) $bu;
    } catch (Exception $e) {
        // Same as above.
    }
    return ['fail_count' => $failCount, 'backoff_until' => $backoffUntil];
}

/**
 * Record a poll failure for $channel and advance its backoff. Returns the
 * new (post-increment) failure count.
 */
function _cr_record_poll_failure(string $channel, ?int $now = null): int {
    if ($now === null) $now = time();
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $state = _cr_get_backoff_state($channel);
    $failCount = $state['fail_count'] + 1;
    $backoffUntil = $now + (_cr_backoff_minutes($failCount) * 60);
    try {
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [$channel . '_poll_fail_count', (string) $failCount]
        );
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [$channel . '_poll_backoff_until', (string) $backoffUntil]
        );
    } catch (Exception $e) {
        error_log("[channel-receive] could not persist backoff state for '{$channel}': " . $e->getMessage());
    }
    return $failCount;
}

/** Clear a channel's backoff bookkeeping after a successful poll. */
function _cr_record_poll_success(string $channel): void {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "DELETE FROM `{$prefix}settings` WHERE `name` IN (?, ?)",
            [$channel . '_poll_fail_count', $channel . '_poll_backoff_until']
        );
    } catch (Exception $e) {
        error_log("[channel-receive] could not clear backoff state for '{$channel}': " . $e->getMessage());
    }
}

/**
 * Run one poll tick across every pollable, opted-in channel.
 *
 * For each channel returned by channel_receive_pollable_channels():
 *   1. Skip (recorded in skipped_disabled) unless `{code}_poll_inbound`
 *      (read via get_variable()) is exactly '1'. Default state — the
 *      setting unset entirely — is "skip", so a fresh/upgraded install
 *      polls nothing until an operator opts in.
 *   2. Skip (recorded in skipped_backoff) if the channel is still inside
 *      a backoff window from a previous failure.
 *   3. Otherwise call broker_receive($code) — this is where dedup, the
 *      message log write, and route evaluation all happen (inc/broker.php).
 *      Success clears any backoff state; a Throwable records a failure and
 *      advances the backoff.
 *
 * One channel's failure — at ANY of these steps — can never stop the
 * others: every step is wrapped in its own try/catch, matching this
 * project's "one bad iteration must not block the rest" discipline (e.g.
 * inc/message-incident.php's per-ticket note-write loop).
 *
 * @param int|null $now  Injectable for tests; defaults to time().
 * @return array{
 *   channels_polled: array,
 *   messages_received: int,
 *   skipped_backoff: array,
 *   skipped_disabled: array,
 *   errors: array
 * }
 */
function channel_receive_run(?int $now = null): array {
    if ($now === null) $now = time();

    $summary = [
        'channels_polled'   => [],
        'messages_received' => 0,
        'skipped_backoff'   => [],
        'skipped_disabled'  => [],
        'errors'            => [],
    ];

    foreach (channel_receive_pollable_channels() as $ch) {
        $code = $ch['code'];
        try {
            $onKey = $code . '_poll_inbound';
            $on = false;
            try {
                $on = function_exists('get_variable') ? get_variable($onKey) : false;
            } catch (Exception $e) {
                $on = false;
            }
            if ($on !== '1') {
                $summary['skipped_disabled'][] = $code;
                continue;
            }

            $state = _cr_get_backoff_state($code);
            if ($state['backoff_until'] !== null && $state['backoff_until'] > $now) {
                $summary['skipped_backoff'][] = [
                    'channel'    => $code,
                    'until'      => $state['backoff_until'],
                    'fail_count' => $state['fail_count'],
                ];
                continue;
            }

            try {
                $messages = broker_receive($code);
                $count = is_array($messages) ? count($messages) : 0;
                $summary['messages_received'] += $count;
                $summary['channels_polled'][] = ['channel' => $code, 'messages' => $count];
                _cr_record_poll_success($code);
            } catch (Throwable $e) {
                $failCount = _cr_record_poll_failure($code, $now);
                $summary['errors'][] = [
                    'channel'    => $code,
                    'error'      => $e->getMessage(),
                    'fail_count' => $failCount,
                ];
            }
        } catch (Throwable $e) {
            // Defensive outer layer — a failure in the bookkeeping ABOVE the
            // broker_receive() call (e.g. a settings read blowing up) must
            // still never take down the rest of the channels in this tick.
            $summary['errors'][] = ['channel' => $code, 'error' => $e->getMessage()];
        }
    }

    return $summary;
}
