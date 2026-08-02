<?php
/**
 * Import/Export dataset definitions vs the real schema.
 *
 * GH TicketsCAD#14 (a beta tester): four of the eleven Import/Export targets
 * declared columns their table has never had — twelve phantom columns in
 * total. Exporting Facilities, Teams, User Accounts or Incidents failed
 * outright on a stock schema, and the failure misdirected: the operator was
 * told to run `php tools/check-schema.php --repair`, which correctly reported
 * "Schema OK" and changed nothing, because the manifest does not list those
 * columns either. An error that says the database is behind, and a repair tool
 * that says the database is fine, is a closed loop.
 *
 * This is the schema-mismatch pattern from CLAUDE.md at the import/export
 * boundary: SQL written against a REMEMBERED schema, silenced by a
 * `catch { return ''; }` into "Export failed — no data or table error", which
 * reads identically to an empty table.
 *
 * What this gate does, and why it is shaped this way:
 *
 *   1. It asks the DATABASE which columns exist, never a hand-maintained list.
 *      A test carrying its own copy of the column set passes for the entire
 *      time the two drift apart.
 *   2. It resolves `legacy` aliases exactly the way export_csv() and
 *      execute_import() do, so an alias counts as satisfied by the column it
 *      points AT — that is the whole mechanism.
 *   3. It runs the REAL export_csv() for every target and requires a header
 *      row. A genuinely empty table still emits its header, so an empty string
 *      always means a failed query, never "no rows".
 *   4. Import targets additionally may not resolve to a GENERATED column —
 *      `teams`.`name` exists only where sql/seed_scheduling_data.php has run,
 *      and there only as a VIRTUAL alias of `team` that cannot be INSERTed
 *      into. That install shape is what made Teams fail in both directions.
 *
 * @requires-db
 */

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/inc/import-export.php';

$pass = 0;
$fail = 0;

function test(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "[PASS] $name\n";
    } else {
        $fail++;
        echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

// ── Prerequisite ────────────────────────────────────────────────────────────
try {
    db_fetch_value('SELECT 1');
} catch (Throwable $e) {
    echo "SKIP: no database connection (" . $e->getMessage() . ")\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

$targets = get_supported_targets();
test('get_supported_targets() returns targets', count($targets) > 0, 'got ' . count($targets));

/**
 * Live column metadata for a table, keyed by column name.
 * Returns null when the table is absent.
 */
function live_columns(string $table): ?array
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    try {
        $rows = db_fetch_all('SHOW COLUMNS FROM ' . db_table($table));
    } catch (Throwable $e) {
        return $cache[$table] = null;
    }
    $out = [];
    foreach ($rows as $r) {
        $out[$r['Field']] = [
            'generated' => stripos((string) ($r['Extra'] ?? ''), 'GENERATED') !== false,
        ];
    }
    return $cache[$table] = $out;
}

// ── 1. Every declared column resolves to a real column ──────────────────────
echo "\n-- Declared columns exist --\n";

foreach ($targets as $target => $label) {
    $cfg = get_table_config($target);
    if (!$cfg) {
        test("$target: has a config", false);
        continue;
    }

    $live = live_columns($cfg['table']);
    if ($live === null) {
        echo "[SKIP] $target: table {$cfg['table']} not present on this install\n";
        continue;
    }

    $phantom = [];
    foreach ($cfg['columns'] as $key => $def) {
        // export_csv():      SELECT `legacy` AS `key`, else SELECT `key`
        // execute_import():  INSERT INTO … (`legacy`|`key`)
        $actual = $def['legacy'] ?? $key;
        if (!isset($live[$actual])) {
            $phantom[] = $key . ($actual !== $key ? " (-> $actual)" : '');
        }
    }
    test("$target ({$cfg['table']}): every declared column exists",
        $phantom === [],
        'not in the table: ' . implode(', ', $phantom));
}

// ── 2. Import columns must not resolve to a GENERATED column ────────────────
echo "\n-- Importable columns are writable --\n";

foreach ($targets as $target => $label) {
    $cfg = get_table_config($target);
    if (!$cfg) continue;
    $live = live_columns($cfg['table']);
    if ($live === null) continue;

    $generated = [];
    foreach ($cfg['columns'] as $key => $def) {
        if (empty($def['import'])) continue;
        $actual = $def['legacy'] ?? $key;
        if (isset($live[$actual]) && $live[$actual]['generated']) {
            $generated[] = "$key -> $actual";
        }
    }
    test("$target: no importable column targets a GENERATED column",
        $generated === [],
        'cannot be INSERTed into: ' . implode(', ', $generated));
}

// ── 3. Match columns resolve too ────────────────────────────────────────────
echo "\n-- Match columns resolve --\n";

foreach ($targets as $target => $label) {
    $cfg = get_table_config($target);
    if (!$cfg) continue;
    $live = live_columns($cfg['table']);
    if ($live === null) continue;

    $bad = [];
    foreach (($cfg['match_columns'] ?? []) as $matchExpr) {
        // execute_import() splits a match expression on '+' — a composite key
        // such as 'last_name+first_name' is two columns, not one.
        foreach (explode('+', $matchExpr) as $mc) {
            $def    = $cfg['columns'][$mc] ?? null;
            $actual = $def['legacy'] ?? $mc;
            if (!isset($live[$actual])) {
                $bad[] = $mc;
            }
        }
    }
    if (($cfg['match_columns'] ?? []) === []) continue;
    test("$target: match_columns resolve to real columns", $bad === [], implode(', ', $bad));
}

// ── 4. The real export runs and produces a header ───────────────────────────
//
// This is the assertion the issue is actually about. It drives export_csv()
// itself, not a reconstruction of it — a reconstruction would agree with a
// broken definition.
echo "\n-- export_csv() produces output --\n";

foreach ($targets as $target => $label) {
    $cfg = get_table_config($target);
    if (!$cfg) continue;
    if (live_columns($cfg['table']) === null) continue;

    $csv = export_csv($cfg);
    // Empty string is export_csv()'s failure return. An empty TABLE still
    // yields the header row, so '' can only mean the query failed.
    test("$target: export_csv() returns a header row",
        $csv !== '' && strpos($csv, ',') !== false,
        $csv === '' ? 'empty string — the SELECT failed (check error_log)' : 'no header');
}

// ── 5. The four targets named in the issue, specifically ────────────────────
echo "\n-- Regression: the four targets from GH#14 --\n";

$regressions = [
    'facility' => ['zip', 'capacity'],                       // dropped: no equivalent
    'incident' => ['dispatched'],                            // dropped: lives on `assigns`
    'user'     => ['name'],                                  // never existed; split name_l/name_f
];
foreach ($regressions as $target => $gone) {
    $cfg  = get_table_config($target);
    $keys = array_keys($cfg['columns'] ?? []);
    $still = array_values(array_intersect($gone, $keys));
    test("$target: phantom column(s) no longer declared", $still === [], implode(', ', $still));
}

$facility = get_table_config('facility');
test('facility: phone is aliased to contact_phone',
    ($facility['columns']['phone']['legacy'] ?? null) === 'contact_phone');
test('facility: contact is aliased to contact_name',
    ($facility['columns']['contact']['legacy'] ?? null) === 'contact_name');

$team = get_table_config('team');
test('team: name is aliased to the base column `team`',
    ($team['columns']['name']['legacy'] ?? null) === 'team');
test('team: description is aliased to mission',
    ($team['columns']['description']['legacy'] ?? null) === 'mission');
test('team: team_type_id is aliased to ttypes_id',
    ($team['columns']['team_type_id']['legacy'] ?? null) === 'ttypes_id');

$user = get_table_config('user');
test('user: names export as the split columns that exist',
    isset($user['columns']['name_l'], $user['columns']['name_f']));

$incident = get_table_config('incident');
foreach ([
    'address'       => 'street',
    'caller_name'   => 'contact',
    'caller_phone'  => 'phone',
    'call_received' => 'date',
    'closed'        => 'problemend',
] as $key => $expect) {
    test("incident: $key is aliased to $expect",
        ($incident['columns'][$key]['legacy'] ?? null) === $expect);
}
test('incident: problemstart is exported',
    isset($incident['columns']['problemstart']));

// ── 6. The drift message must not send the operator in a circle ─────────────
//
// A column NOTHING declares and a column the manifest declares are both a
// MySQL 1054, and they need opposite advice. Telling someone whose database
// is fine that it is "behind the code" is what cost the reporter the time.
echo "\n-- Drift advice distinguishes the two faults --\n";

require_once $root . '/inc/schema-verify.php';

test('schema_drift_column_is_declared() is defined',
    function_exists('schema_drift_column_is_declared'));

if (function_exists('schema_drift_column_is_declared')) {
    test('a column the manifest declares reads as declared',
        schema_drift_column_is_declared('description'));
    test('a phantom column reads as undeclared',
        !schema_drift_column_is_declared('zzz_not_a_real_column_' . substr(md5('x'), 0, 6)));
    test('facilities.zip — the column from GH#14 — is undeclared',
        !schema_drift_column_is_declared('zip'));
}

// Drive the real payload builder through the real capture path.
if (function_exists('schema_drift_note') && function_exists('schema_drift_payload')) {
    schema_drift_note(new RuntimeException(
        "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'zzz_phantom_col' in 'field list'"
    ));
    $payload = schema_drift_payload();
    test('an undeclared column yields schema_column_undeclared, not schema_out_of_date',
        ($payload['error'] ?? '') === 'schema_column_undeclared',
        'got ' . ($payload['error'] ?? 'nothing'));
    test('the message no longer claims the database is behind the code',
        stripos($payload['message'] ?? '', 'structure is behind the code') === false);
    test('the repair advice does not point at --repair for a column it cannot create',
        strpos($payload['repair'] ?? '', '--repair') === false,
        $payload['repair'] ?? '');
    test('the undeclared column is named back to the operator',
        in_array('zzz_phantom_col', $payload['undeclared'] ?? [], true));
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
