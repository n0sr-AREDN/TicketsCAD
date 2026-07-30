<?php
/**
 * Clock-consistency gate (2026-07-29).
 *
 * ORIGIN. On your-server.example.com the APRS map showed "0 stations in
 * window" and a banner reading "APRS-IS receive listener is not active. Last
 * position received 5h ago" — while the listener was healthy and had written
 * 1,288 rows in the previous hour. `location_reports.reported_at` holds
 * area-local wall clock; api/aprs-positions.php measured it against
 * UTC_TIMESTAMP(). Commit 3c556fb pointed the reader at UTC back when the
 * listener really did write datetime.utcnow(); commit 0115613 moved the
 * listener to area-local stamps and left the reader behind.
 *
 * WHY A GATE. This bug class is INVISIBLE on a UTC server and silently wrong
 * everywhere else. Most volunteer groups run a local-timezone box, so it hits
 * real users and never hits the developer. The same sweep found three more
 * instances (api/mesh.php, api/external/v1/_auth.php, and six client-side
 * sites), and one of those had already survived a targeted fix because the
 * old guard string-matched a single call site instead of the pattern.
 *
 * These tests drive the REAL tool (tools/timezone_audit.php) against fixture
 * files via --path, rather than re-implementing its matching logic here. A
 * gate that only ever runs on a clean tree proves nothing, so every detector
 * is shown to FIRE on a known-bad input and stay quiet on a known-good one.
 *
 * Usage: php tests/test_timezone_audit.php
 */

$base = realpath(__DIR__ . '/..');
$php  = PHP_BINARY;
$tool = $base . '/tools/timezone_audit.php';

echo "=== Clock-consistency audit gate ===\n\n";
$pass = 0; $fail = 0;
function ok(string $n): void { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad(string $n, string $why = ''): void { global $fail; echo "[FAIL] $n" . ($why ? " — $why" : '') . "\n"; $fail++; }
function is_true($c, string $n, string $why = ''): void { $c ? ok($n) : bad($n, $why); }

/** Run the audit against a directory; return [exitCode, output]. */
function tza_run(string $tool, string $path = ''): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool);
    if ($path !== '') { $cmd .= ' ' . escapeshellarg('--path=' . $path); }
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

// ── Fixtures live outside the repo so a crash cannot leave bad SQL behind ──
$tmp = sys_get_temp_dir() . '/tza_fixtures_' . getmypid();
$bad = $tmp . '/bad';
$good = $tmp . '/good';
@mkdir($bad, 0777, true);
@mkdir($good, 0777, true);
register_shutdown_function(static function () use ($tmp) {
    foreach (['bad', 'good'] as $d) {
        foreach (glob("$tmp/$d/*") ?: [] as $f) { @unlink($f); }
        @rmdir("$tmp/$d");
    }
    @rmdir($tmp);
});

// ── Known-BAD fixtures: one per detector ──────────────────────────────────

// 1. The original production bug, verbatim in shape.
file_put_contents($bad . '/utc_sql.php', <<<'PHP'
<?php
$rows = db_fetch_all(
    "SELECT unit_identifier, TIMESTAMPDIFF(SECOND, reported_at, UTC_TIMESTAMP()) AS age_sec
       FROM `location_reports`
      WHERE provider_id = ?
        AND reported_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)",
    [1, 60]
);
PHP);

// 2. A UTC comparison on a column with no known local writer.
file_put_contents($bad . '/utc_unknown.php', <<<'PHP'
<?php
$r = db_fetch_all(
    "SELECT id FROM `some_widget_table` WHERE `polled_at` < UTC_TIMESTAMP()"
);
PHP);

// 3. gmdate() building a bare MySQL DATETIME (api/mesh.php + _auth.php shape).
file_put_contents($bad . '/gmdate_datetime.php', <<<'PHP'
<?php
$stamp = gmdate('Y-m-d H:i:s', (int) $epoch);
if ($token['expires_at'] < gmdate('Y-m-d H:i:s')) { die('expired'); }
PHP);

// 4. JS appending 'Z' to a MySQL DATETIME.
file_put_contents($bad . '/z_suffix.js', <<<'JS'
var updMs = Date.parse(String(r.updated).replace(' ', 'T') + 'Z');
JS);

// 5. JS reshaping toISOString() into a MySQL DATETIME.
file_put_contents($bad . '/iso_shape.js', <<<'JS'
var created = new Date().toISOString().slice(0, 19).replace('T', ' ');
JS);

// 6. A Python daemon stamping UTC as a MySQL DATETIME literal.
file_put_contents($bad . '/daemon.py', <<<'PY'
import datetime
now = datetime.datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S')
cur.execute("INSERT INTO location_reports (reported_at) VALUES (%s)", (now,))
PY);

[$code, $out] = tza_run($tool, $bad);

is_true($code === 1, 'audit exits non-zero on a tree containing clock bugs',
    "exit code was $code");

$expect = [
    'clock-conflict'        => 'the original APRS bug (local column vs UTC_TIMESTAMP)',
    'utc-compare'           => 'a UTC comparison with no local-write evidence',
    'php-gmdate-datetime'   => "gmdate() building a bare MySQL DATETIME",
    'js-utc-suffix'         => "JS appending 'Z' to a MySQL DATETIME",
    'js-iso-as-datetime'    => 'JS reshaping toISOString() into a MySQL DATETIME',
    'py-utc-datetime-write' => 'a Python daemon stamping UTC as a DATETIME literal',
];
foreach ($expect as $key => $desc) {
    is_true(strpos($out, $key) !== false, "detector fires: $desc",
        "no '$key' finding in output");
}

// The APRS finding must name the column, so the message is actionable.
is_true(
    strpos($out, 'location_reports.reported_at') !== false,
    'the conflict finding names the offending column'
);

// ── Known-GOOD fixtures: the correct forms must stay silent ───────────────
file_put_contents($good . '/local_sql.php', <<<'PHP'
<?php
$rows = db_fetch_all(
    "SELECT TIMESTAMPDIFF(SECOND, reported_at, NOW()) AS age_sec
       FROM `location_reports`
      WHERE reported_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)",
    [60]
);
db_query("UPDATE `responder` SET `updated` = NOW() WHERE id = ?", [1]);
PHP);

// gmdate() emitting a WIRE format carries its own offset — correct.
file_put_contents($good . '/wire_formats.php', <<<'PHP'
<?php
$cot  = gmdate('Y-m-d\TH:i:s\Z');
$http = gmdate('D, d M Y H:i:s \G\M\T');
$rfc  = gmdate('c');
PHP);

file_put_contents($good . '/local_parse.js', <<<'JS'
var updMs = Date.parse(String(r.updated).replace(' ', 'T'));
var iso = new Date().toISOString();
JS);

// isoformat() is a wire value for a /health payload — correct as UTC.
file_put_contents($good . '/health.py', <<<'PY'
import datetime
stats = {"connected_at": datetime.datetime.now(datetime.timezone.utc).isoformat()}
PY);

[$gcode, $gout] = tza_run($tool, $good);
is_true($gcode === 0, 'audit stays silent on the correct local-clock forms',
    "exit code $gcode; output:\n$gout");

// ── The real tree must be clean ───────────────────────────────────────────
[$rcode, $rout] = tza_run($tool);
$tail = implode("\n", array_slice(explode("\n", $rout), -14));
is_true($rcode === 0, 'no NEW clock mismatches in the app tree', $tail);

// ── The specific sites this session fixed must not regress ────────────────
$aprs = (string) file_get_contents($base . '/api/aprs-positions.php');
// Strip comments so the explanatory clock-note (which names UTC_TIMESTAMP)
// cannot mask a real regression in the SQL.
$aprsCode = (string) preg_replace('#^\s*//.*$#m', '', $aprs);
is_true(strpos($aprsCode, 'UTC_TIMESTAMP') === false,
    'api/aprs-positions.php measures reported_at with NOW(), not UTC_TIMESTAMP',
    'a UTC comparison came back — the APRS map will show 0 stations on any non-UTC server');
is_true(substr_count($aprsCode, 'NOW()') >= 4,
    'all four APRS clock comparisons use NOW()',
    'found ' . substr_count($aprsCode, 'NOW()'));

$mesh = (string) file_get_contents($base . '/api/mesh.php');
is_true(strpos($mesh, "gmdate('Y-m-d H:i:s', (int) \$p['received_at'])") === false,
    'api/mesh.php stamps mesh_packet_log.received_at with date(), not gmdate()');

$auth = (string) file_get_contents($base . '/api/external/v1/_auth.php');
is_true(strpos($auth, "gmdate('Y-m-d H:i:s')") === false,
    'api/external/v1/_auth.php compares token expiry against the local clock');

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
