<?php
/**
 * NewUI v4.0 — Net-Control Check-Ins (Phase 131)
 *
 * Net control calls for check-ins; several stations answer in quick
 * succession, each giving an identifier and often a very short report:
 *
 *     "1234 Tornado"  "3344 Hail"  "6543 Hail"  "3243 Wind Damage"
 *
 * NCS reads the list back — "I have 1234, 3344, 6543 and 3243. 1234, go ahead
 * with your report" — and works them one at a time. This file is the storage
 * and the parser behind the `/net` command bar entry and the floating widget
 * that keeps that list in front of the operator.
 *
 * THE PARSER LIVES HERE AND ONLY HERE. The command bar sends the raw string
 * the operator typed; the server decides what it means. A second parser in JS
 * would be two implementations of one rule, and they would drift.
 *
 * PER-OPERATOR: every function takes $userId and scopes to it. The list is
 * the operator's own — not global, not visible to another dispatcher. The
 * endpoint supplies $userId from the session and nowhere else, so the client
 * has no id to tamper with.
 *
 * See specs/phase-131-net-control-checkins/.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// Sanity caps. A net is fast, not infinite — these stop a paste accident from
// writing thousands of rows, without ever being reachable in normal use.
if (!defined('NET_MAX_ENTRIES_PER_COMMAND')) define('NET_MAX_ENTRIES_PER_COMMAND', 50);
if (!defined('NET_MAX_IDENTIFIER_LEN'))      define('NET_MAX_IDENTIFIER_LEN', 64);
if (!defined('NET_MAX_NOTE_LEN'))            define('NET_MAX_NOTE_LEN', 255);

/** Valid lifecycle states. `pending` is waiting to be called on. */
function net_statuses(): array {
    return ['pending', 'worked', 'deleted'];
}

/**
 * Admin-overridable behaviour, with defaults.
 *
 * READ WITH get_variable(), NEVER get_setting(). They are different stores:
 * get_variable() reads the `settings` table, which is what the Settings UI
 * writes; get_setting() reads the separate little `config` table, which the
 * Settings UI does not write — so an admin toggle read that way returns the
 * default forever and the panel appears to do nothing (CLAUDE.md, GH #79).
 * This is the ONLY place the phase reads configuration, so there is exactly
 * one place it could be got wrong.
 *
 * @return array{history_count:int,autofocus:bool,order:string,separator:string,digit_guard:bool,retention_days:int}
 */
function net_config(): array {
    $get = function (string $key, $default) {
        $raw = get_variable($key);
        // get_variable() returns FALSE for "not set" — distinct from a stored
        // "0", which is a real value an admin chose.
        if ($raw === false || $raw === null || $raw === '') return $default;
        return $raw;
    };

    $sep = (string) $get('net_checkin_separator', '/');
    // Exactly one character, and never whitespace or a digit — a digit
    // separator would make the digit-guard incoherent, and a whitespace
    // separator would make every note its own entry.
    if ($sep === '' || mb_strlen($sep) !== 1 || preg_match('/[\s0-9]/', $sep)) {
        $sep = '/';
    }

    $order = strtolower(trim((string) $get('net_checkin_order', 'arrival')));
    if (!in_array($order, ['arrival', 'priority'], true)) $order = 'arrival';

    return [
        'history_count'  => max(0, (int) $get('net_checkin_history_count', 10)),
        'autofocus'      => ((string) $get('net_checkin_autofocus', '1')) === '1',
        'order'          => $order,
        'separator'      => $sep,
        'digit_guard'    => ((string) $get('net_checkin_separator_digit_guard', '1')) === '1',
        'retention_days' => max(0, (int) $get('net_checkin_retention_days', 7)),
    ];
}

/**
 * Parse a raw `/net` argument string into check-in entries.
 *
 *   "1234 tornado / 3344 hail / 6543 hail / 3243 wind damage"
 *     → [ [1234, tornado], [3344, hail], [6543, hail], [3243, wind damage] ]
 *
 * Rules:
 *   - Entries are separated by $separator (default '/').
 *   - Within an entry the FIRST whitespace-delimited token is the identifier;
 *     everything after it is the note.
 *   - An entry may have no note at all: "/net 1234" is valid.
 *   - Extra whitespace anywhere is insignificant.
 *   - Punctuation inside a note is preserved verbatim.
 *
 * THE FRACTIONAL-INCH GUARD. NWS hail sizes are fractional inches — 1/4" pea,
 * 3/4" penny, 1 1/2" walnut — so on a hail net, which is exactly the net this
 * was built for, '/' appears inside perfectly legitimate notes. With
 * $digitGuard on (default), a separator with a digit immediately on BOTH sides
 * is literal text:
 *
 *     "1234 tornado / 3344 hail"  → two entries   (spaces either side)
 *     "6543 hail 3/4\""           → one entry, note kept whole
 *
 * Admins who want the naive split can turn the guard off; admins who prefer a
 * different separator entirely can set one, which makes the point moot.
 *
 * @param  string $raw        what the operator typed after "/net "
 * @param  string $separator  single, non-digit, non-whitespace character
 * @param  bool   $digitGuard treat digit-flanked separators as literal
 * @return array<int,array{identifier:string,note:string}>
 */
function net_parse_checkins(string $raw, string $separator = '/', bool $digitGuard = true): array {
    $raw = trim($raw);
    if ($raw === '') return [];

    if ($separator === '' || mb_strlen($separator) !== 1 || preg_match('/[\s0-9]/', $separator)) {
        $separator = '/';
    }

    $chunks = $digitGuard
        // Split on the separator only when it is NOT flanked by digits on both
        // sides. Lookbehind/lookahead so the separator itself is consumed and
        // the flanking characters stay with their chunk.
        ? preg_split('/(?<![0-9])' . preg_quote($separator, '/') . '|'
                   . preg_quote($separator, '/') . '(?![0-9])/u', $raw)
        : explode($separator, $raw);

    if ($chunks === false) $chunks = [$raw];

    $entries = [];
    foreach ($chunks as $chunk) {
        // Collapse whitespace runs (including newlines from a paste) so
        // tokenising is predictable, then trim the ends.
        $chunk = trim(preg_replace('/\s+/u', ' ', (string) $chunk) ?? '');
        if ($chunk === '') continue;   // "a // b" or a trailing separator

        $space = mb_strpos($chunk, ' ');
        if ($space === false) {
            $identifier = $chunk;
            $note       = '';
        } else {
            $identifier = mb_substr($chunk, 0, $space);
            $note       = trim(mb_substr($chunk, $space + 1));
        }

        if ($identifier === '') continue;

        $entries[] = [
            'identifier' => mb_substr($identifier, 0, NET_MAX_IDENTIFIER_LEN),
            'note'       => mb_substr($note, 0, NET_MAX_NOTE_LEN),
        ];

        if (count($entries) >= NET_MAX_ENTRIES_PER_COMMAND) break;
    }

    return $entries;
}

/**
 * Store parsed entries for one operator.
 *
 * @param  array $entries  as returned by net_parse_checkins()
 * @param  int   $userId   the OWNER — session user, never client-supplied
 * @return array{added:int,ids:array<int,int>}
 */
function net_add_entries(array $entries, int $userId): array {
    if ($userId <= 0 || !$entries) return ['added' => 0, 'ids' => []];

    $tbl = db_table('net_checkins');
    $now = date('Y-m-d H:i:s');

    // Continue the arrival sequence from wherever this operator left off, so
    // ordering by seq stays stable across several /net commands in one net.
    try {
        $seq = (int) db_fetch_value(
            "SELECT COALESCE(MAX(`seq`), 0) FROM {$tbl} WHERE `user_id` = ?", [$userId]);
    } catch (Exception $e) {
        $seq = 0;
    }

    $ids = [];
    foreach ($entries as $e) {
        $identifier = trim((string) ($e['identifier'] ?? ''));
        if ($identifier === '') continue;
        $note = (string) ($e['note'] ?? '');
        $seq++;
        try {
            db_query(
                "INSERT INTO {$tbl}
                    (`user_id`, `identifier`, `note`, `status`, `seq`, `priority`,
                     `created_at`, `updated_at`)
                 VALUES (?, ?, ?, 'pending', ?, 0, ?, ?)",
                [$userId,
                 mb_substr($identifier, 0, NET_MAX_IDENTIFIER_LEN),
                 mb_substr($note, 0, NET_MAX_NOTE_LEN),
                 $seq, $now, $now]
            );
            $ids[] = (int) db_insert_id();
        } catch (Exception $ex) {
            // Never swallow silently — a check-in that did not store is a
            // spotter nobody will be called on.
            error_log('[net-checkins] insert failed: ' . $ex->getMessage());
        }
    }

    return ['added' => count($ids), 'ids' => $ids];
}

/**
 * The operator's list: everything still waiting, plus up to $historyCount of
 * the most recently touched worked/deleted entries.
 *
 * Ordering of the waiting entries follows the configured mode — arrival
 * sequence (the order they checked in) or an operator-set priority. History is
 * always most-recent-first, because it is a lookback, not a work queue.
 *
 * @param int      $userId
 * @param int|null $historyCount  null → the configured default
 * @param string   $order         '' → the configured default
 */
function net_list(int $userId, ?int $historyCount = null, string $order = ''): array {
    if ($userId <= 0) return [];

    $cfg   = net_config();
    $hist  = $historyCount === null ? $cfg['history_count'] : max(0, $historyCount);
    $order = $order !== '' ? $order : $cfg['order'];
    if (!in_array($order, ['arrival', 'priority'], true)) $order = 'arrival';

    $tbl = db_table('net_checkins');

    // priority DESC first so a hand-raised entry rises; seq breaks the tie so
    // equal-priority entries stay in the order they checked in.
    $activeOrder = ($order === 'priority')
        ? '`priority` DESC, `seq` ASC, `id` ASC'
        : '`seq` ASC, `id` ASC';

    try {
        $active = db_fetch_all(
            "SELECT * FROM {$tbl} WHERE `user_id` = ? AND `status` = 'pending'
             ORDER BY {$activeOrder}", [$userId]);
    } catch (Exception $e) {
        error_log('[net-checkins] list failed: ' . $e->getMessage());
        return [];
    }

    $history = [];
    if ($hist > 0) {
        try {
            $history = db_fetch_all(
                "SELECT * FROM {$tbl} WHERE `user_id` = ? AND `status` <> 'pending'
                 ORDER BY `updated_at` DESC, `id` DESC LIMIT " . (int) $hist,
                [$userId]);
        } catch (Exception $e) {
            error_log('[net-checkins] history failed: ' . $e->getMessage());
        }
    }

    return array_map('net_shape_row', array_merge($active, $history));
}

/** One entry by id, scoped to its owner. Returns null for someone else's row. */
function net_get_entry(int $id, int $userId): ?array {
    if ($id <= 0 || $userId <= 0) return null;
    try {
        $row = db_fetch_one(
            "SELECT * FROM " . db_table('net_checkins') . " WHERE `id` = ? AND `user_id` = ?",
            [$id, $userId]);
    } catch (Exception $e) {
        error_log('[net-checkins] get failed: ' . $e->getMessage());
        return null;
    }
    return $row ? net_shape_row($row) : null;
}

/**
 * Normalise a DB row into the shape the widget consumes.
 *
 * The JS reads exactly these keys and no others — when wiring JS to an
 * endpoint, read the endpoint's ACTUAL output mapping rather than guessing
 * key names (CLAUDE.md, the API<->JS contract pattern).
 */
function net_shape_row(array $r): array {
    return [
        'id'         => (int) $r['id'],
        'identifier' => (string) $r['identifier'],
        'note'       => (string) $r['note'],
        'status'     => (string) $r['status'],
        'seq'        => (int) $r['seq'],
        'priority'   => (int) $r['priority'],
        'ticket_id'  => isset($r['ticket_id']) && $r['ticket_id'] !== null ? (int) $r['ticket_id'] : null,
        'created_at' => (string) $r['created_at'],
        'worked_at'  => $r['worked_at'] ?? null,
        'deleted_at' => $r['deleted_at'] ?? null,
        'updated_at' => (string) $r['updated_at'],
    ];
}

/**
 * Correct a misheard callsign or a note that needs fixing.
 *
 * Scoped `AND user_id = ?` — a guessed id belonging to another operator
 * matches zero rows and is reported as not-found, exactly like an id that does
 * not exist. No oracle, no IDOR.
 */
function net_update_entry(int $id, int $userId, ?string $identifier, ?string $note): bool {
    if ($id <= 0 || $userId <= 0) return false;

    $sets = [];
    $params = [];
    if ($identifier !== null) {
        $identifier = trim($identifier);
        if ($identifier === '') return false;     // an entry without an ID is not a check-in
        $sets[] = '`identifier` = ?';
        $params[] = mb_substr($identifier, 0, NET_MAX_IDENTIFIER_LEN);
    }
    if ($note !== null) {
        $sets[] = '`note` = ?';
        $params[] = mb_substr(trim($note), 0, NET_MAX_NOTE_LEN);
    }
    if (!$sets) return false;

    $sets[] = '`updated_at` = ?';
    $params[] = date('Y-m-d H:i:s');
    $params[] = $id;
    $params[] = $userId;

    try {
        $st = db_query("UPDATE " . db_table('net_checkins') . " SET " . implode(', ', $sets)
                     . " WHERE `id` = ? AND `user_id` = ?", $params);
        return $st->rowCount() > 0 || net_get_entry($id, $userId) !== null;
    } catch (Exception $e) {
        error_log('[net-checkins] update failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Move an entry through its lifecycle.
 *
 *   pending  — waiting to be called on
 *   worked   — an incident exists for it; drops out of the active list
 *   deleted  — struck off; still recoverable from the history view
 *
 * `worked` is set when an incident is actually created or a note is actually
 * saved — NOT when the operator presses the key. If they abandon the form, the
 * check-in correctly stays in the waiting list. A spotter nobody called on is
 * the exact failure this feature exists to prevent.
 */
function net_set_status(int $id, int $userId, string $status, ?int $ticketId = null): bool {
    if ($id <= 0 || $userId <= 0) return false;
    if (!in_array($status, net_statuses(), true)) return false;

    $now = date('Y-m-d H:i:s');
    $sets   = ['`status` = ?', '`updated_at` = ?'];
    $params = [$status, $now];

    // Stamp the moment of transition, and clear the other stamp so a restored
    // entry does not carry a stale worked_at/deleted_at into the next round.
    if ($status === 'worked') {
        $sets[] = '`worked_at` = ?';  $params[] = $now;
        $sets[] = '`deleted_at` = NULL';
    } elseif ($status === 'deleted') {
        $sets[] = '`deleted_at` = ?'; $params[] = $now;
    } else {  // pending — an undo
        $sets[] = '`worked_at` = NULL';
        $sets[] = '`deleted_at` = NULL';
    }

    if ($ticketId !== null && $ticketId > 0) {
        $sets[] = '`ticket_id` = ?'; $params[] = $ticketId;
    }

    $params[] = $id;
    $params[] = $userId;

    try {
        db_query("UPDATE " . db_table('net_checkins') . " SET " . implode(', ', $sets)
               . " WHERE `id` = ? AND `user_id` = ?", $params);
    } catch (Exception $e) {
        error_log('[net-checkins] status change failed: ' . $e->getMessage());
        return false;
    }

    // Verify the outcome rather than trusting rowCount (which is 0 when the
    // values were already what we asked for). Ask the database.
    $row = net_get_entry($id, $userId);
    return $row !== null && $row['status'] === $status;
}

/** Operator-set priority, for installs that work by priority rather than arrival. */
function net_set_priority(int $id, int $userId, int $priority): bool {
    if ($id <= 0 || $userId <= 0) return false;
    try {
        db_query("UPDATE " . db_table('net_checkins')
               . " SET `priority` = ?, `updated_at` = ? WHERE `id` = ? AND `user_id` = ?",
                 [$priority, date('Y-m-d H:i:s'), $id, $userId]);
    } catch (Exception $e) {
        error_log('[net-checkins] priority failed: ' . $e->getMessage());
        return false;
    }
    $row = net_get_entry($id, $userId);
    return $row !== null && $row['priority'] === $priority;
}

/**
 * Drop this operator's finished entries once they are older than the retention
 * window. Waiting entries are NEVER pruned regardless of age — an unworked
 * check-in is outstanding work, however long the net has run.
 *
 * Deliberately lazy and in-request rather than scheduled. Neither training nor
 * your deployment has a cron daemon, and a file dropped in /etc/cron.d there fails
 * completely silently — two jobs sat at zero bytes for seven weeks before
 * anyone noticed (CLAUDE.md, 2026-07-29). Nothing here needs to happen while
 * nobody is looking, so nothing here is scheduled.
 *
 * @return int rows removed
 */
function net_prune(int $userId, ?int $retentionDays = null): int {
    if ($userId <= 0) return 0;
    $days = $retentionDays === null ? net_config()['retention_days'] : max(0, $retentionDays);
    if ($days <= 0) return 0;   // 0 = keep forever

    try {
        $st = db_query(
            "DELETE FROM " . db_table('net_checkins') . "
             WHERE `user_id` = ? AND `status` <> 'pending'
               AND `updated_at` < DATE_SUB(NOW(), INTERVAL ? DAY)
             LIMIT 500",
            [$userId, $days]);
        return $st->rowCount();
    } catch (Exception $e) {
        error_log('[net-checkins] prune failed: ' . $e->getMessage());
        return 0;
    }
}

/** Is there work still waiting? Drives whether the widget auto-focuses. */
function net_pending_count(int $userId): int {
    if ($userId <= 0) return 0;
    try {
        return (int) db_fetch_value(
            "SELECT COUNT(*) FROM " . db_table('net_checkins')
          . " WHERE `user_id` = ? AND `status` = 'pending'", [$userId]);
    } catch (Exception $e) {
        return 0;
    }
}
