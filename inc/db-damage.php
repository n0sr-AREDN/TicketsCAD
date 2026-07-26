<?php
/**
 * Phase 121 (2026-07-25) — detect and SURFACE storage-level table damage.
 *
 * The problem this solves. After an unclean shutdown, MySQL can bring the
 * server up cleanly while individual tables stay unreadable. TicketsCAD's
 * defensive read helpers (the safe_fetch_all_* family) catch any exception and
 * return an empty array, which is right for "this optional column is missing on
 * an older schema" — but badly wrong here: the screen renders as EMPTY, so the
 * operator concludes their data is gone. A beta tester lost an evening to this
 * (2026-07-25): `teams` was damaged, the roster query LEFT JOINs `teams`, so
 * Personnel showed 0 people while Settings → System Overview — which counts
 * `member` directly and never touches `teams` — correctly said 8.
 *
 * Empty because there is no data, and empty because the table is broken, must
 * never look the same.
 *
 * How it works. Two chokepoints, so every endpoint is covered without editing
 * a dozen per-file helpers:
 *   1. db_query() calls db_damage_note() on failure — records damage in a
 *      request-scoped global, then rethrows (behaviour otherwise unchanged).
 *   2. json_response() calls db_damage_intercept() before emitting a success —
 *      if damage was recorded, the reply becomes a clear 503 explaining which
 *      table is damaged and that the data is recoverable, instead of a
 *      misleading empty list.
 *
 * Deliberately NARROW. Only genuine storage damage counts. A missing table
 * (error 1146) is a pending migration, not damage, and must keep flowing
 * through the existing graceful-degradation paths untouched.
 */

/** Request-scoped record of the first damage seen. */
function &_db_damage_store(): array {
    if (!isset($GLOBALS['_db_damage'])) {
        $GLOBALS['_db_damage'] = [];
    }
    return $GLOBALS['_db_damage'];
}

/**
 * Classify an exception. Returns null when it is NOT storage damage.
 * Pure (no I/O, no globals) so the rules are unit-testable.
 *
 * @return array|null ['table' => ?string, 'kind' => string, 'detail' => string]
 */
function db_damage_classify(string $message): ?array {
    $m = $message;

    // "Table 'db.teams' doesn't exist in engine"  (MySQL 1932)
    //   The dictionary knows the table but the tablespace is gone/unregistered.
    // NOTE: this is NOT the same as 1146 "Table 'db.x' doesn't exist", which
    // means a migration hasn't run — deliberately not matched here.
    if (stripos($m, "doesn't exist in engine") !== false
        || stripos($m, 'does not exist in engine') !== false) {
        return ['table' => _db_damage_table($m), 'kind' => 'missing_tablespace',
                'detail' => 'the table is registered but its data file is missing or unreadable'];
    }
    // "Table './db/x' is marked as crashed and should be repaired"      (1194)
    // "… and last (automatic?) repair failed"                           (1195)
    if (stripos($m, 'marked as crashed') !== false) {
        return ['table' => _db_damage_table($m), 'kind' => 'crashed',
                'detail' => 'the table is marked as crashed and needs repair'];
    }
    // "Incorrect key file for table 'x'; try to repair it"              (1034)
    if (stripos($m, 'incorrect key file for table') !== false) {
        return ['table' => _db_damage_table($m), 'kind' => 'bad_index',
                'detail' => "the table's index file is damaged"];
    }
    // "Got error 194 from storage engine" / "Got error N from storage engine"
    if (stripos($m, 'from storage engine') !== false) {
        return ['table' => _db_damage_table($m), 'kind' => 'engine_error',
                'detail' => 'the storage engine refused to read the table'];
    }
    // "Tablespace is missing for table 'x'"
    if (stripos($m, 'tablespace is missing') !== false
        || stripos($m, 'tablespace has been discarded') !== false) {
        return ['table' => _db_damage_table($m), 'kind' => 'missing_tablespace',
                'detail' => "the table's tablespace is missing"];
    }
    return null;
}

/** Best-effort table name out of a driver message. Cosmetic only. */
function _db_damage_table(string $m): ?string {
    if (preg_match("/'([^']+)'/", $m, $mm)) {
        // Normalise ".\\db\\teams" / "./db/teams" / "db.teams" → "teams"
        $raw = str_replace('\\', '/', $mm[1]);
        $raw = preg_replace('#^\./#', '', $raw);
        $parts = preg_split('#[/.]#', $raw);
        $last = end($parts);
        return $last !== '' ? $last : null;
    }
    return null;
}

/**
 * Record damage seen during this request. Safe to call on every query failure —
 * non-damage exceptions are ignored.
 */
function db_damage_note(Throwable $e): void {
    $info = db_damage_classify($e->getMessage());
    if (!$info) return;
    $store = &_db_damage_store();
    // Keep the first (root) damage, but collect distinct table names.
    if (!$store) {
        $store = $info + ['tables' => []];
    }
    if ($info['table'] && !in_array($info['table'], $store['tables'], true)) {
        $store['tables'][] = $info['table'];
    }
    error_log(sprintf('[db-damage] %s (%s): %s', $info['table'] ?? '?', $info['kind'], $e->getMessage()));
}

/** Was storage damage seen during this request? */
function db_damage_seen(): bool {
    $store = &_db_damage_store();
    return !empty($store);
}

/** The operator-facing explanation. Plain language, no SQL, no blame. */
function db_damage_payload(): array {
    $store = &_db_damage_store();
    $tables = $store['tables'] ?? [];
    $named  = $tables ? implode(', ', $tables) : 'one of the database tables';
    return [
        'error'          => 'Some data could not be read because a database table is damaged — '
                          . 'this is NOT an empty list, and your data is very likely recoverable.',
        'damaged'        => true,
        'damaged_tables' => $tables,
        'reason'         => ucfirst($store['detail'] ?? 'the table could not be read'),
        'explanation'    => "The database is running, but the table(s) {$named} cannot be read, so this "
                          . 'screen cannot show a complete or accurate list. This usually follows an '
                          . 'unclean shutdown (a power loss or hard power-off with MySQL running). '
                          . 'Records in undamaged tables are unaffected.',
        'next_steps'     => [
            'Do not re-enter your data yet — it is probably still there.',
            'Back up the MySQL data directory before changing anything.',
            'Run a check to list every damaged table: mysqlcheck -u root <database>',
            'See docs/TROUBLESHOOTING.md → "App looks empty / fresh install after a crash" for the repair steps.',
        ],
        'doc'            => 'docs/TROUBLESHOOTING.md#app-empty-after-crash',
    ];
}

/**
 * Called from json_response() just before a SUCCESS reply is emitted. If damage
 * was recorded, replace the (misleadingly empty/partial) payload with the
 * explanation. Only intercepts 2xx — a handler already reporting an error keeps
 * its own message.
 *
 * @return array|null replacement payload, or null to emit the original
 */
function db_damage_intercept(int $status): ?array {
    if ($status < 200 || $status >= 300) return null;
    if (!db_damage_seen()) return null;
    return db_damage_payload();
}
