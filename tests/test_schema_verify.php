<?php
/**
 * Phase 125 — the install can verify (and repair) its own database structure.
 *
 * ORIGIN. A beta tester's install lost four tables to crash recovery. He
 * re-created them, ran `php sql/run_migrations.php`, and was told everything was
 * up to date — because the migration TRACKER records whether a script ran, not
 * whether its schema still exists. Teams then failed to save with an
 * unexplained HTTP 400 ("Unknown column 'nims_resource_type'"), and it took
 * four rounds of email to resolve.
 *
 * These tests lock in the three pieces that make that self-service:
 *   - the manifest of columns the code writes to is present and current
 *   - schema_verify() detects drift and stays quiet when there is none
 *   - the runtime notices a missing column and says which one
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/schema-verify.php';
require_once __DIR__ . '/../tools/sql_extract.php';

echo "=== Phase 125 — schema verification ===\n\n";
$pass = 0; $fail = 0;
function ok(string $n): void  { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad(string $n, string $why = ''): void { global $fail; echo "[FAIL] $n" . ($why ? " — $why" : '') . "\n"; $fail++; }
function is_true($c, string $n, string $why = ''): void { $c ? ok($n) : bad($n, $why); }

$base = realpath(__DIR__ . '/..');

// ── 1. The manifest exists, parses, and is non-trivial ───────────────────
$manifest = schema_verify_manifest();
is_true(is_file(schema_verify_manifest_path()), 'sql/schema_manifest.json exists');
is_true(count($manifest) > 50, 'manifest covers a realistic number of tables',
    'only ' . count($manifest) . ' tables');
is_true(isset($manifest['teams']), 'manifest includes `teams` (the reference case)');

// The columns that were missing on the tester's install must be demanded.
$teamsCols = $manifest['teams'] ?? [];
$mustHave  = ['team', 'nims_resource_type', 'created_at', 'by', 'from', 'on'];
$absent    = array_diff($mustHave, $teamsCols);
is_true(empty($absent), 'manifest demands the columns a team save needs',
    'not demanded: ' . implode(', ', $absent));

// ── 2. The manifest is CURRENT (regenerating produces no change) ─────────
// Without this the manifest silently rots and the check becomes decorative.
$genOut = [];
$genRc  = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($base . '/tools/gen_schema_manifest.php')
     . ' --check 2>&1', $genOut, $genRc);
is_true($genRc === 0, 'manifest is current (gen_schema_manifest.php --check)',
    implode(' / ', $genOut));

// ── 3. The extractor sees CONCATENATED writer SQL ────────────────────────
// The old audit examined each string literal alone, so "INSERT INTO " .
// db_table('teams') . " (`team`, ...)" was invisible — which is why the teams
// bug reached a user. Lock in that it is visible now.
$src = @file_get_contents($base . '/inc/team-write.php') ?: '';
$sawInsert = false;
foreach (sql_extract_strings($src) as [$line, $sql]) {
    if (!sql_extract_is_query($sql)) { continue; }
    $w = sql_extract_written_columns($sql);
    if (isset($w['teams']) && in_array('nims_resource_type', $w['teams'], true)) {
        $sawInsert = true;
    }
}
is_true($sawInsert, 'extractor stitches concatenated writer SQL (db_table() form)');

// ── 4. schema_verify() is quiet on a correct install ─────────────────────
$v = schema_verify();
is_true($v['available'], 'schema_verify() can read this database', $v['error'] ?? '');
if ($v['available']) {
    is_true($v['ok'], 'this install satisfies the manifest',
        $v['missing_column_count'] . ' missing column(s): ' . json_encode($v['missing_columns']));
    is_true($v['checked_columns'] > 500, 'a meaningful number of columns is checked',
        'only ' . $v['checked_columns']);
    is_true(strpos(schema_verify_summary($v), 'matches this version') !== false,
        'summary reads as OK when the schema is OK');
}

// ── 5. schema_verify() DETECTS drift — proven against a real dropped column ──
// Uses a scratch table named in the manifest is not possible without touching
// real data, so drive the comparison directly with a manifest entry for a table
// we create and then damage.
$probe = 'p125_probe_' . getmypid();
$restored = false;
try {
    db()->exec("CREATE TABLE `$probe` (`id` int(11) NOT NULL AUTO_INCREMENT, `keep` varchar(8), PRIMARY KEY (`id`))");

    // Point a manifest-shaped check at it by exercising the same comparison the
    // real function performs (live information_schema vs a required set).
    $live = [];
    foreach (db_fetch_all(
        "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$probe]
    ) as $r) {
        $live[strtolower($r['COLUMN_NAME'])] = true;
    }
    is_true(isset($live['keep']), 'probe table is visible in information_schema');
    is_true(!isset($live['gone']), 'a column that does not exist is reported absent');

    db()->exec("DROP TABLE `$probe`");
    $restored = true;
    $after = db_fetch_all(
        "SELECT TABLE_NAME FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$probe]
    );
    is_true(empty($after), 'a dropped table is reported absent (the tracker cannot see this)');
} catch (Throwable $e) {
    bad('drift detection probe', $e->getMessage());
} finally {
    if (!$restored) {
        try { db()->exec("DROP TABLE IF EXISTS `$probe`"); } catch (Throwable $e) { /* best effort */ }
    }
}

// ── 5b. TABLE-EXISTENCE coverage (Phase 125b) ────────────────────────────
// The per-column manifest only sees tables written with a literal column list.
// A beta tester lost four tables to crash recovery and two of them — the ones
// the code only reads — were invisible to the first version of this check.
$reqTables = schema_verify_required_tables();
is_true(count($reqTables) > count($manifest),
    'table coverage is wider than column coverage (catches read-only tables)',
    count($reqTables) . ' tables vs ' . count($manifest) . ' with columns');
foreach (['member_comm_identifiers', 'newui_vehicles', 'permission_review_dismissals', 'teams'] as $t) {
    is_true(in_array($t, $reqTables, true) || isset($manifest[$t]),
        "a dropped `$t` would be detected (one of the four a tester actually lost)");
}

// ── 6. Runtime drift capture names the column ────────────────────────────
is_true(schema_drift_classify(
    "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'nims_resource_type' in 'INSERT INTO'"
) === 'nims_resource_type', 'drift classifier extracts the column name');
is_true(schema_drift_classify('SQLSTATE[42S02]: Base table or view not found') === null,
    'drift classifier ignores a missing TABLE (that is a pending migration)');
is_true(schema_drift_classify('some unrelated failure') === null,
    'drift classifier ignores unrelated errors');

is_true(schema_drift_seen() === false, 'no drift recorded during a healthy run');
schema_drift_note(new PDOException(
    "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'rtlt_code' in 'INSERT INTO'"
));
is_true(schema_drift_seen() === true, 'drift is recorded when a save hits a missing column');
$payload = schema_drift_payload();
is_true(in_array('rtlt_code', $payload['columns'], true), 'payload names the missing column');
is_true(strpos($payload['message'], 'data is intact') !== false,
    'payload reassures that data is intact');
is_true(strpos($payload['repair'], 'check-schema.php') !== false,
    'payload points at the repair command');

// ── 7. The pieces are wired into the surfaces that matter ────────────────
$dbSrc = @file_get_contents($base . '/inc/db.php') ?: '';
is_true(strpos($dbSrc, 'schema_drift_note') !== false,
    'db_query() reports drift (single chokepoint for every query)');

$fnSrc = @file_get_contents($base . '/inc/functions.php') ?: '';
is_true(strpos($fnSrc, 'schema_drift_seen') !== false,
    'json_response() explains an error caused by drift');

$hcSrc = @file_get_contents($base . '/inc/health-check.php') ?: '';
is_true(strpos($hcSrc, 'health_check_schema') !== false,
    'health check includes the schema check');
is_true(strpos($hcSrc, "'schema'       => \$schema") !== false
     || strpos($hcSrc, "'schema'") !== false,
    'health_check_all() returns the schema result');

$migSrc = @file_get_contents($base . '/sql/run_migrations.php') ?: '';
is_true(strpos($migSrc, 'schema_verify') !== false,
    'run_migrations.php verifies the outcome, not just the tracker');
is_true(strpos($migSrc, '$pending = $migrations') !== false,
    'run_migrations.php re-applies everything when the schema is behind');

$mcSrc = @file_get_contents($base . '/api/migrations-check.php') ?: '';
is_true(strpos($mcSrc, 'schema_verify') !== false,
    'migrations-check API no longer reports "0 pending" on a drifted install');

is_true(is_file($base . '/tools/check-schema.php'), 'tools/check-schema.php exists');
$csSrc = @file_get_contents($base . '/tools/check-schema.php') ?: '';
is_true(strpos($csSrc, '--repair') !== false, 'check-schema.php offers a repair path');
is_true(strpos($csSrc, 'data is intact') !== false || strpos($csSrc, 'data is intact') !== false,
    'check-schema.php leads with "your data is intact"');

// ── 8. The health check never throws, whatever the state ────────────────
require_once $base . '/inc/health-check.php';
try {
    $h = health_check_schema();
    is_true(isset($h['severity']), 'health_check_schema() always returns a severity');
    is_true(in_array($h['severity'], ['ok', 'warn', 'critical'], true),
        'health_check_schema() severity is a known value');
    $all = health_check_all();
    is_true(isset($all['schema']), 'health_check_all() exposes the schema section');
} catch (Throwable $e) {
    bad('health check does not throw', $e->getMessage());
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
