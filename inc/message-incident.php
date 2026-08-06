<?php
/**
 * Phase 111 Slice A — Message → active-event incident auto-logging.
 *
 * The single entry point the router calls when a matched route is marked
 * attach_action='add_note'. Keeps inc/router.php lean: all the "which event
 * is active, who sent this, format the note, write it" logic lives here.
 *
 * SAFETY CONTRACT (this is a live-radio install):
 *   - mi_attach_message_to_active_event() is a hard NO-OP when no active
 *     event is configured (active_event_ticket_id unset or 0). The router's
 *     existing forwarding behaviour is byte-for-byte unchanged in that case.
 *   - Nothing in here EVER throws into the caller. Every DB / resolve /
 *     format step is wrapped; a failure is logged (error_log) and swallowed
 *     so a bad message can never break the router's forward loop.
 *   - Note text is ASCII-sanitised before writing. The legacy `action`
 *     table is latin1_swedish_ci and rejects multibyte unicode; the
 *     net-control code learned this the hard way.
 *
 * Dependencies (loaded lazily so a fresh install without them degrades):
 *   inc/incident-write.php  → incident_add_note_internal()
 *   inc/comm_resolve.php    → comm_resolve_member_by_address() (Link 1)
 */

if (!function_exists('db_query')) {
    require_once __DIR__ . '/db.php';
}

/**
 * Read the active-event incident id from the `settings` table.
 *
 * Returns a positive ticket id, or 0 when the feature is off (setting unset,
 * empty, or non-positive). Static-cached per process — the router may call
 * mi_attach_message_to_active_event() many times in one request during a
 * message burst, and the active event doesn't change mid-request.
 *
 * @return int
 */
function mi_active_event_ticket_id(): int {
    static $cached = null;

    // A caller (test harness / the active-event API right after a write)
    // can force a re-read via mi_reset_active_event_cache().
    if (!empty($GLOBALS['__mi_active_event_force_reload'])) {
        $cached = null;
        unset($GLOBALS['__mi_active_event_force_reload']);
    }

    if ($cached !== null) return $cached;

    $prefix = $GLOBALS['db_prefix'] ?? '';
    $cached = 0;
    try {
        $val = db_fetch_value(
            "SELECT `value` FROM `{$prefix}settings` WHERE `name` = ? LIMIT 1",
            ['active_event_ticket_id']
        );
        if ($val !== false && $val !== null) {
            $id = (int) $val;
            if ($id > 0) $cached = $id;
        }
    } catch (Exception $e) {
        // settings table missing / query failed — feature stays off.
        $cached = 0;
    }
    return $cached;
}

/**
 * Reset the mi_active_event_ticket_id() static cache.
 *
 * Only needed by tests (which flip the setting mid-process) and by the
 * active-event API right after it writes a new value. Production request
 * paths read the setting once and never change it within the same request.
 */
function mi_reset_active_event_cache(): void {
    // Re-derive on next call by poking the static through a fresh read.
    // PHP has no direct "unset static", so we mirror the value in a global
    // the reader consults first when present.
    $GLOBALS['__mi_active_event_force_reload'] = true;
}

/**
 * ASCII-safe a note for the latin1 `action.description` column.
 *
 * Strips characters outside printable ASCII (plus tab/newline), collapsing
 * anything multibyte to a '?' placeholder so the write can't fail on a
 * unicode emoji / smart-quote a field radio app might inject. Trims to a
 * sane length.
 */
function _mi_ascii_note(string $text): string {
    // Replace any non-ASCII byte sequence with '?'. iconv is the cleanest
    // available transliteration; fall back to a regex strip if it's absent
    // or errors on malformed input.
    $out = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($out === false || $out === null) {
        $out = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $text);
    } else {
        // iconv//TRANSLIT can still emit stray bytes on some libc builds;
        // final-pass strip to guarantee pure printable ASCII.
        $out = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $out);
    }
    $out = trim((string) $out);
    if (strlen($out) > 1000) {
        $out = substr($out, 0, 1000);
    }
    return $out;
}

/**
 * Human label for a source channel, used in the note prefix.
 * e.g. 'zello' → 'Zello', 'dmr' → 'DMR', 'meshtastic' → 'Meshtastic'.
 */
function _mi_channel_label(string $channel): string {
    $c = strtolower(trim($channel));
    static $labels = [
        'zello'      => 'Zello',
        'dmr'        => 'DMR',
        'meshtastic' => 'Meshtastic',
        'meshcore'   => 'MeshCore',
        'aprs'       => 'APRS',
        'local_chat' => 'Chat',
        'sms'        => 'SMS',
        'email'      => 'Email',
    ];
    if (isset($labels[$c])) return $labels[$c];
    // Fallback: title-case a clean token.
    $clean = preg_replace('/[^a-zA-Z0-9_]/', '', $channel);
    return $clean !== '' ? ucfirst($clean) : 'Message';
}

/**
 * Pull the sender handle out of a broker-shaped message array. Different
 * channels populate different keys; try them in likely order.
 */
function _mi_message_sender(array $message): string {
    foreach (['from', 'sender', 'sender_username', 'from_handle', 'callsign', 'node_id', 'radio_id'] as $k) {
        if (!empty($message[$k]) && is_scalar($message[$k])) {
            return trim((string) $message[$k]);
        }
    }
    return '';
}

/**
 * Build the note text for an inbound message, e.g.:
 *   "[Zello: alice_sar] crowd heavy at bandshell"
 * When the sender is empty:
 *   "[Zello] crowd heavy at bandshell"
 *
 * @param array  $message        Broker message array (body + sender fields)
 * @param string $sourceChannel  Channel code the message arrived on
 * @return string  ASCII-safe note text (already sanitised)
 */
function _mi_build_note(array $message, string $sourceChannel): string {
    $label  = _mi_channel_label($sourceChannel);
    $sender = _mi_message_sender($message);
    $body   = (string) ($message['body'] ?? '');

    $prefix = $sender !== '' ? "[{$label}: {$sender}] " : "[{$label}] ";
    return _mi_ascii_note($prefix . $body);
}

/**
 * THE single entry point the router calls for a matched attach_action route.
 *
 * If no active event is configured this returns IMMEDIATELY (the router's
 * behaviour is unchanged). Otherwise it resolves the sender to a member
 * (Link 1), formats an ASCII note, and appends it to the active event's
 * ICS-214 activity log via incident_add_note_internal(), tagged with the
 * source channel + resolved author.
 *
 * Attribution to the acting user: there is no logged-in user in the router
 * path (it runs on an inbound message, not an HTTP session). We stamp
 * action.user = 0 (system) — the human attribution lives in
 * author_member_id (the resolved sender), which is what the per-person 214
 * pulls by.
 *
 * NEVER throws. A resolve/format/write failure is logged and swallowed.
 *
 * @param array  $message        Broker message array (body + sender fields,
 *                               and optionally _source_message_id set by the
 *                               router forward, or source_message_id).
 * @param string $sourceChannel  Channel code the message arrived on.
 * @return void
 */
function mi_attach_message_to_active_event(array $message, string $sourceChannel): void {
    try {
        $ticketId = mi_active_event_ticket_id();
        if ($ticketId <= 0) {
            return; // Feature off — hard no-op. Router unaffected.
        }

        $body = trim((string) ($message['body'] ?? ''));
        if ($body === '') {
            return; // Nothing to log (e.g. a voice PTT with no transcript).
        }

        // Resolve the sender to a member (Link 1). Null when unknown — the
        // note still logs, just without author attribution (a dispatcher
        // can attribute it later in Slice B's tray).
        $authorMemberId = null;
        $sender = _mi_message_sender($message);
        if ($sender !== '' && function_exists('comm_resolve_member_by_address')) {
            try {
                $authorMemberId = comm_resolve_member_by_address($sourceChannel, $sender);
            } catch (Exception $e) {
                $authorMemberId = null; // resolution is best-effort
            }
        }

        // Source message id: prefer the router-forward metadata, then a
        // plain key, else null.
        $srcMsgId = null;
        foreach (['_source_message_id', 'source_message_id', 'message_id', 'id'] as $k) {
            if (isset($message[$k]) && $message[$k] !== null && (int) $message[$k] > 0) {
                $srcMsgId = (int) $message[$k];
                break;
            }
        }

        $note = _mi_build_note($message, $sourceChannel);
        if ($note === '') {
            return;
        }

        if (!function_exists('incident_add_note_internal')) {
            require_once __DIR__ . '/incident-write.php';
        }

        $meta = [
            'source_channel'    => strtolower(trim($sourceChannel)),
            'source_message_id' => $srcMsgId,
            'author_member_id'  => $authorMemberId,
        ];

        // System user (0) — see docblock. The note writer is defensive
        // about the meta columns' existence, so this is safe pre-migration.
        $result = incident_add_note_internal($ticketId, $note, 0, $meta);

        if (!empty($result['errors'])) {
            error_log('[message-incident] add_note returned errors for ticket '
                . $ticketId . ': ' . implode('; ', $result['errors']));
        }
    } catch (Throwable $e) {
        // ABSOLUTE guarantee: never propagate into the router.
        error_log('[message-incident] attach failed (swallowed): ' . $e->getMessage());
    }
}

/**
 * Phase 134 (Model 3, GH #23) — member -> responder -> open-assignment ->
 * ticket resolution.
 *
 * Given a member id, returns the deduplicated set of OPEN incident ticket
 * ids that member's unit(s) are currently assigned to. This is the FORWARD
 * direction of inc/comm_resolve.php's comm_resolve_responder_member_id()
 * (which goes responder -> member); the same two link shapes are reused
 * here, reversed:
 *
 *   1. unit_personnel_assignments — an ACTIVE, non-released row
 *      (status = 'active' AND released_at IS NULL) links a member to a
 *      responder (the "many people, one unit" model).
 *   2. responder.personal_for_member_id — the personal-unit model
 *      (inc/personnel-units.php) ties exactly one responder to exactly
 *      one member.
 *
 * `responder` has NO `member_id` column — plan.md §4's original SQL sketch
 * guessed one; it does not exist on this schema (confirmed via
 * `SHOW COLUMNS`) and must not be reintroduced.
 *
 * An assignment is OPEN when `assigns.clear IS NULL` (per spec.md's
 * definition). A ticket must ALSO not be soft-deleted — nothing cascades a
 * soft-delete onto its `assigns` rows (see incident_soft_delete_internal()'s
 * docblock), so a ticket can be soft-deleted while an open assign row still
 * points at it. Relying on `assigns.clear IS NULL` alone would resolve a
 * message onto a deleted incident — the "stranded assigns" class of bug
 * this project's CLAUDE.md documents extensively — so `ticket.deleted_at`
 * is filtered explicitly here rather than trusted to the assigns state.
 *
 * NEVER throws. Returns [] for $memberId <= 0 or on any DB error.
 *
 * @param int $memberId
 * @return int[] Deduplicated open ticket ids the member is assigned to.
 */
function mi_assigned_incident_ticket_ids(int $memberId): array {
    if ($memberId <= 0) return [];

    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $rows = db_fetch_all(
            "SELECT DISTINCT a.ticket_id
               FROM `{$prefix}assigns` a
               JOIN `{$prefix}responder` r ON r.id = a.responder_id
               JOIN `{$prefix}ticket` t ON t.id = a.ticket_id
              WHERE a.clear IS NULL
                AND (t.deleted_at IS NULL OR t.deleted_at = '0000-00-00 00:00:00')
                AND (
                     r.personal_for_member_id = ?
                  OR r.id IN (
                       SELECT responder_id FROM `{$prefix}unit_personnel_assignments`
                        WHERE member_id = ? AND status = 'active' AND released_at IS NULL
                     )
                )",
            [$memberId, $memberId]
        );
    } catch (Throwable $e) {
        error_log('[message-incident] mi_assigned_incident_ticket_ids failed (swallowed): ' . $e->getMessage());
        return [];
    }

    $ids = [];
    foreach ($rows as $r) {
        $tid = (int) ($r['ticket_id'] ?? 0);
        if ($tid > 0) {
            $ids[$tid] = $tid; // dedupe by key
        }
    }
    return array_values($ids);
}

/**
 * Phase 134 (Model 3, GH #23) — sibling to
 * mi_attach_message_to_active_event(), same body shape and same absolute
 * never-throws guarantee, but resolves the sender's OWN open assignment(s)
 * (mi_assigned_incident_ticket_ids()) instead of one designated active
 * event, and can attach to more than one incident.
 *
 * This function is ONLY the resolve-and-attach step. It deliberately does
 * NOT implement the Model-1 "general dispatch chat" fallback described in
 * spec.md ("Fallback, never silent drop") — an unresolved sender, or a
 * resolved sender with zero open assignments, is a silent no-op here by
 * design. The fallback broadcast lives one level up in the router wiring
 * (plan.md §6), a LATER phase step this function has no dependency on.
 *
 * Per spec.md's explicit v1 decision, a sender assigned (open) to two
 * different incidents gets a note attached to BOTH — this is intentional,
 * not a bug to guard against; no "primary unit" gate is required to ship
 * this v1.
 *
 * Each per-ticket write is wrapped in ITS OWN try/catch (not one catch
 * around the whole loop, per plan.md §4) so one ticket's write failure can
 * never block the notes for the sender's other open incidents.
 *
 * NEVER throws. Every DB / resolve / format / write step is wrapped; a
 * failure is logged (error_log) and swallowed.
 *
 * @param array  $message        Broker message array (body + sender fields,
 *                               and optionally _source_message_id set by the
 *                               router forward, or source_message_id).
 * @param string $sourceChannel  Channel code the message arrived on
 *                               (e.g. 'telegram', 'slack').
 * @return void
 */
function mi_attach_message_to_assigned_incidents(array $message, string $sourceChannel): void {
    try {
        $sender = _mi_message_sender($message);
        if ($sender === '') {
            return; // Nothing to resolve.
        }

        // Resolve the sender to a member. Best-effort, same shape as
        // mi_attach_message_to_active_event()'s Link 1 resolution.
        $authorMemberId = null;
        if (function_exists('comm_resolve_member_by_address')) {
            try {
                $authorMemberId = comm_resolve_member_by_address($sourceChannel, $sender);
            } catch (Exception $e) {
                $authorMemberId = null; // resolution is best-effort
            }
        }
        if (empty($authorMemberId)) {
            // Unresolved sender — the expected common case for most inbound
            // chatter, not an error. The caller falls through to Model 1
            // general chat; this function implements no fallback itself.
            return;
        }

        $ticketIds = mi_assigned_incident_ticket_ids((int) $authorMemberId);
        if (empty($ticketIds)) {
            // Resolved, but nothing open to attach to right now — same
            // "let the caller fall through" contract as the unresolved case.
            return;
        }

        $body = trim((string) ($message['body'] ?? ''));
        if ($body === '') {
            return; // Nothing to log (e.g. a voice PTT with no transcript).
        }

        $note = _mi_build_note($message, $sourceChannel);
        if ($note === '') {
            return;
        }

        if (!function_exists('incident_add_note_internal')) {
            require_once __DIR__ . '/incident-write.php';
        }

        // Source message id: prefer the router-forward metadata, then a
        // plain key, else null. Same precedence as the active-event sibling.
        $srcMsgId = null;
        foreach (['_source_message_id', 'source_message_id', 'message_id', 'id'] as $k) {
            if (isset($message[$k]) && $message[$k] !== null && (int) $message[$k] > 0) {
                $srcMsgId = (int) $message[$k];
                break;
            }
        }

        $meta = [
            'source_channel'    => strtolower(trim($sourceChannel)),
            'source_message_id' => $srcMsgId,
            'author_member_id'  => (int) $authorMemberId,
        ];

        foreach ($ticketIds as $ticketId) {
            try {
                $result = incident_add_note_internal((int) $ticketId, $note, 0, $meta);
                if (!empty($result['errors'])) {
                    error_log('[message-incident] assigned-incident add_note returned errors for ticket '
                        . $ticketId . ': ' . implode('; ', $result['errors']));
                }
            } catch (Throwable $e) {
                // Per-iteration catch (plan.md §4) — one ticket's failure
                // must not block the notes for the sender's other incidents.
                error_log('[message-incident] assigned-incident attach failed for ticket '
                    . $ticketId . ' (swallowed): ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        // ABSOLUTE guarantee: never propagate into the caller.
        error_log('[message-incident] attach-to-assigned-incidents failed (swallowed): ' . $e->getMessage());
    }
}
