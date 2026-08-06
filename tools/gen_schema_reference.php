<?php
/**
 * Generate docs/SCHEMA-REFERENCE.md — the full live database schema, plus a
 * curated list of gotchas that cannot be seen from the DDL alone.
 *
 * WHY THIS EXISTS (2026-08-04). Across the Phase 132/134 sessions, agents
 * repeatedly ran one-off `SHOW COLUMNS`/`SHOW INDEX` queries to answer
 * questions a static reference should answer: does `responder` have a
 * `member_id` column (no), does `settings.name` have a real unique key (yes),
 * does `message_routes.source_channel` support a wildcard (yes, '*'), is
 * `member_comm_identifiers.sort_order` in the base schema (no — it's added
 * lazily at runtime). Each of those cost a live query and a context-window
 * detour. This file exists so an agent `Read`s or `Grep`s it once instead.
 *
 * This is a SIBLING to sql/schema_manifest.json (tools/gen_schema_manifest.php),
 * not a replacement — that file is narrower and load-bearing (columns the code
 * WRITES to, checked by inc/schema-verify.php against a live install). This
 * file is broader and purely informational: every column on every table, for
 * a human or an agent to read, never checked by any test or gate. Regenerate
 * it whenever the schema changes meaningfully; a stale copy is misleading but
 * not load-bearing, so there is no CI gate on it (unlike schema_manifest.json).
 *
 * The "Known gotchas" section is hand-curated, not derived — this is exactly
 * the class of fact (self-healed columns, dual-store settings, NULL-in-
 * unique-index traps) that no schema dump can show by itself. Add to it
 * whenever a live query answers a question that surprised you; that is the
 * whole point of this file.
 *
 * Usage:
 *   php tools/gen_schema_reference.php     # write docs/SCHEMA-REFERENCE.md
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$out    = fopen('docs/SCHEMA-REFERENCE.md', 'w');
if ($out === false) {
    fwrite(STDERR, "Could not open docs/SCHEMA-REFERENCE.md for writing\n");
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────
// 1. Known gotchas — hand-curated. Extend this array as new surprises turn
//    up; do not try to auto-detect these, most of them can't be.
// ─────────────────────────────────────────────────────────────────────────
$gotchas = [
    [
        'table' => 'member_comm_identifiers',
        'what'  => '`sort_order` is NOT in the base schema. It is added lazily, at '
            . 'runtime, the first time `api/comm-identifiers.php`\'s '
            . '`_ensure_sort_order_column()` runs. A fresh CI install (which never '
            . 'hits that admin endpoint) will not have it — naming it in a raw INSERT '
            . '1054-errors there even though it works on any dev DB that has. Check '
            . '`inc/comm_resolve.php`\'s `_comm_resolve_has_sort_order()` for the '
            . 'existing guard pattern before referencing this column anywhere new.',
    ],
    [
        'table' => 'settings / config',
        'what'  => 'TWO separate stores, easy to cross. `settings` (name/value, ~255 '
            . 'rows) is what the Settings UI actually writes and what '
            . '`get_variable($name)` reads — this is where a new feature toggle '
            . 'belongs. `config` (key/value, ~8 rows) is a small bootstrap-ish store '
            . 'read by `get_setting($key, $default)` that the Settings UI does NOT '
            . 'write to. Reading a UI-saved toggle with `get_setting()` silently '
            . 'returns the default forever, with no error anywhere (GH #79).',
    ],
    [
        'table' => 'message_routes',
        'what'  => '`source_channel = \'*\'` is a real wildcard `_router_get_routes()` '
            . 'matches (`WHERE source_channel = ? OR source_channel = \'*\'`) — one '
            . 'row can cover every present and future channel rather than needing a '
            . 'row per channel.',
    ],
    [
        'table' => 'ticket_disposition',
        'what'  => 'Deliberately has NO database-level UNIQUE key on (code, org_id), '
            . 'because `org_id` is NULLable and MySQL/MariaDB treat every NULL as '
            . 'distinct in a unique index — a naive unique constraint would enforce '
            . 'nothing for global (org_id IS NULL) rows. Uniqueness is enforced at '
            . 'the application level instead (`disposition_code_exists()`).',
    ],
    [
        'table' => 'assigns',
        'what'  => '`clear` is a DATETIME, not a boolean. An assignment is OPEN when '
            . '`clear IS NULL`, cleared when it holds a timestamp. Never add an '
            . '`is_clear`/`active` column for this — the whole codebase already '
            . 'keys off `clear IS NULL`.',
    ],
    [
        'table' => 'responder',
        'what'  => 'Has NO `member_id` column — a query written against a remembered '
            . '`responder.member_id` will 1054 or (worse) silently return nothing. '
            . 'The two real member<->responder linkages are (1) '
            . '`unit_personnel_assignments` (responder_id, member_id, status, '
            . 'released_at — many-people-one-unit model) and (2) '
            . '`responder.personal_for_member_id` (one responder tied to exactly one '
            . 'member — the "personal unit" model). See '
            . '`inc/comm_resolve.php`\'s `comm_resolve_responder_member_id()` for '
            . 'the canonical resolution order (checks #1 then #2).',
    ],
    [
        'table' => 'user',
        'what'  => '`level` is a legacy v3 column. RBAC (`role_id` + the '
            . '`roles`/`permissions`/`role_permissions` tables) is the ONLY '
            . 'permission system as of Phase 128 — `user.level` must never gate '
            . 'anything, not even as an OR-fallback. Also: the admin account is NOT '
            . 'necessarily user id 1 — `base_schema.sql` pins `AUTO_INCREMENT=3` on '
            . 'this table (a legacy-dump artefact), so the first account a fresh '
            . 'install creates is id 3. Use `tests/_test_admin.php`\'s '
            . '`test_admin_user_id()` in tests, never a hardcoded `1`.',
    ],
    [
        'table' => 'ticket',
        'what'  => '`status`: 1 = CLOSED, 2 = ACTIVE/open, 3 = SCHEDULED (booked_date '
            . 'in the future; auto-activates 3->2 lazily when the date passes). A '
            . 'query filtering "open" incidents wants `status = 2`, not `status != 1`.',
    ],
    [
        'table' => 'comm_modes',
        'what'  => 'Fully data-driven: `fields_json` on each row defines the per-mode '
            . 'form field shape, and the existing Roster -> member -> Comm/Location '
            . 'IDs UI renders any row generically. Adding a new identifier type '
            . '(a new messaging channel, a new device type) is a migration seeding '
            . 'one row plus a reverse-map entry in `inc/comm_resolve.php` -- it is '
            . 'NOT new UI code.',
    ],
];

fwrite($out, "# TicketsCAD NewUI — Schema Reference\n\n");
fwrite($out, "**Generated:** " . date('Y-m-d H:i') . " by `tools/gen_schema_reference.php` "
    . "— do not hand-edit, regenerate instead.\n\n");
fwrite($out, "Purely informational — every column on every table in this live database, "
    . "for an agent to `Read` or `Grep` instead of running a one-off `SHOW COLUMNS`/"
    . "`SHOW INDEX` query. NOT load-bearing: nothing checks this file against the "
    . "live schema (that job belongs to `sql/schema_manifest.json` + "
    . "`inc/schema-verify.php`, which cover columns the code actually writes to). "
    . "A stale copy here is misleading, not breaking — regenerate when the schema "
    . "changes meaningfully.\n\n");

fwrite($out, "## Known gotchas\n\n");
fwrite($out, "Facts a schema dump alone cannot show. Read this section before writing "
    . "any new query against an unfamiliar table.\n\n");
foreach ($gotchas as $g) {
    fwrite($out, "### `{$g['table']}`\n\n{$g['what']}\n\n");
}

// ─────────────────────────────────────────────────────────────────────────
// 2. Live schema dump — every table, every column, every key.
// ─────────────────────────────────────────────────────────────────────────
$tables = db_fetch_all(
    "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
       FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
      ORDER BY TABLE_NAME"
);

$columnsByTable = [];
foreach (db_fetch_all(
    "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
            COLUMN_KEY, EXTRA
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
      ORDER BY TABLE_NAME, ORDINAL_POSITION"
) as $c) {
    $columnsByTable[$c['TABLE_NAME']][] = $c;
}

$indexesByTable = [];
foreach (db_fetch_all(
    "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SEQ_IN_INDEX
       FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE()
      ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX"
) as $i) {
    $indexesByTable[$i['TABLE_NAME']][$i['INDEX_NAME']]['non_unique'] = (int) $i['NON_UNIQUE'];
    $indexesByTable[$i['TABLE_NAME']][$i['INDEX_NAME']]['columns'][] = $i['COLUMN_NAME'];
}

fwrite($out, "## Tables (" . count($tables) . ")\n\n");
fwrite($out, "| Table | Jump |\n|---|---|\n");
foreach ($tables as $t) {
    $name = _sr_strip_prefix($t['TABLE_NAME'], $prefix);
    // GitHub's markdown anchor slugger lowercases and strips backticks/punctuation
    // but PRESERVES underscores — a header of "### `unit_personnel_assignments`"
    // anchors to #unit_personnel_assignments, not #unitpersonnelassignments.
    fwrite($out, "| `{$name}` | [#`{$name}`](#" . strtolower($name) . ") |\n");
}
fwrite($out, "\n");

foreach ($tables as $t) {
    $rawName = $t['TABLE_NAME'];
    $name    = _sr_strip_prefix($rawName, $prefix);
    fwrite($out, "### `{$name}`\n\n");
    fwrite($out, "Engine: {$t['ENGINE']} · Collation: {$t['TABLE_COLLATION']}\n\n");

    fwrite($out, "| Column | Type | Null | Key | Default | Extra |\n");
    fwrite($out, "|---|---|---|---|---|---|\n");
    foreach ($columnsByTable[$rawName] ?? [] as $c) {
        $default = $c['COLUMN_DEFAULT'] === null ? '' : $c['COLUMN_DEFAULT'];
        fwrite($out, "| `{$c['COLUMN_NAME']}` | {$c['COLUMN_TYPE']} | {$c['IS_NULLABLE']} | "
            . "{$c['COLUMN_KEY']} | {$default} | {$c['EXTRA']} |\n");
    }

    $indexes = $indexesByTable[$rawName] ?? [];
    // PRIMARY is already implied by the Key column above; skip it here.
    $nonPrimary = array_filter($indexes, fn ($k) => $k !== 'PRIMARY', ARRAY_FILTER_USE_KEY);
    if (!empty($nonPrimary)) {
        fwrite($out, "\nIndexes:\n");
        foreach ($nonPrimary as $indexName => $idx) {
            $kind = $idx['non_unique'] ? 'KEY' : 'UNIQUE KEY';
            fwrite($out, "- `{$kind} {$indexName}` (" . implode(', ', $idx['columns']) . ")\n");
        }
    }
    fwrite($out, "\n");
}

fclose($out);

$tableCount  = count($tables);
$columnCount = array_sum(array_map('count', $columnsByTable));
echo "docs/SCHEMA-REFERENCE.md written: {$tableCount} tables, {$columnCount} columns, "
    . count($gotchas) . " curated gotchas.\n";

/** Strip the configured table prefix from a raw table name, if present. */
function _sr_strip_prefix(string $rawName, string $prefix): string {
    if ($prefix !== '' && str_starts_with($rawName, $prefix)) {
        return substr($rawName, strlen($prefix));
    }
    return $rawName;
}
