<?php
/**
 * Clock-consistency audit — catches SQL that measures a stored timestamp
 * against the WRONG clock.
 *
 * WHY THIS EXISTS (2026-07-29)
 * ----------------------------
 * TicketsCAD stores DATETIME columns as WALL-CLOCK time in the install's
 * `area_timezone`. inc/db.php and config.php both sync the MySQL session
 * `time_zone` to PHP's default zone, so within a request:
 *
 *     NOW()  ==  CURRENT_TIMESTAMP  ==  PHP date('Y-m-d H:i:s')
 *
 * ...and all three are the clock every writer stamps rows in. `UTC_TIMESTAMP()`
 * and `UTC_DATE()` are the odd ones out: on any server whose area_timezone is
 * not UTC they are off by the whole UTC offset (5 hours on US Central).
 *
 * That makes this bug class INVISIBLE on a UTC box and silently wrong
 * everywhere else. Most volunteer groups run a local-timezone server, so it
 * hits real users and never hits the developer. It has already shipped twice:
 *
 *   - api/aprs-positions.php compared `location_reports.reported_at` (written
 *     in area-local time) against UTC_TIMESTAMP(). Every row looked exactly
 *     one UTC offset old, so the APRS map showed "0 stations in window" and a
 *     banner claiming the listener was down — while the listener was healthy
 *     and writing ~1,300 rows an hour. Commit 3c556fb pointed the reader at
 *     UTC when the listener really did write UTC; commit 0115613 moved the
 *     listener to area-local stamps and left the reader behind.
 *   - services/aprs/aprs_listener.py used datetime.utcnow(), landing every row
 *     ~5 h in the FUTURE ("-17999s ago", units permanently fresh) until
 *     commit 0115613 switched it to the area zone.
 *
 * WHAT IT CHECKS
 * --------------
 *   [utc-compare]  SQL that compares a stored column against UTC_TIMESTAMP() /
 *                  UTC_DATE(). On this stack that is a mismatch unless the
 *                  column is genuinely written in UTC — so findings are
 *                  default-deny and legitimate cases go in the baseline.
 *   [clock-conflict] A column with LOCAL write evidence read against a UTC
 *                  function, or a column with UTC write evidence read against
 *                  NOW(). This is the fatal shape; the baseline should stay
 *                  empty for it.
 *   [py-utc-write] A services/*.py daemon stamping datetime.utcnow() — the
 *                  exact regression 0115613 fixed. Python writers must use the
 *                  install's area timezone (see _now_local_str()).
 *
 * Write evidence comes from two places: SQL literals (`col` = NOW() and
 * INSERT ... VALUES (NOW())) and, for columns stamped by application code
 * through a `?` placeholder, the curated $APP_CLOCK registry below. Add an
 * entry there when you add a writer that stamps a datetime in PHP/Python
 * rather than in SQL.
 *
 * Exit code: 0 = clean (or only baseline-listed findings), 1 = new findings.
 * Baseline lives in tools/timezone_audit_baseline.txt — one finding key per
 * line, '#' comments allowed.
 *
 * Usage:
 *   php tools/timezone_audit.php            # report + exit code
 *   php tools/timezone_audit.php --all      # include baseline-listed findings
 */

declare(strict_types=1);

chdir(__DIR__ . '/..');
require_once __DIR__ . '/sql_extract.php';

$showAll = in_array('--all', $argv ?? [], true);

// ── 1. Columns whose clock is set by application code, not by SQL ─────────
//
// These are stamped through a `?` placeholder, so no SQL literal proves the
// clock. Each entry records the writers that were verified by hand. Keep this
// list honest: an entry here silences/creates findings, so only add a column
// after actually reading its writers.
$APP_CLOCK = [
    // Python listener stamps _now_local_str() (services/aprs/aprs_listener.py
    // :139-143,185 — datetime.now(area_tz)); api/location.php:279,578 uses
    // date('Y-m-d H:i:s'); inc/atak_route.php:95 converts inbound ISO-Z to
    // local with date('Y-m-d H:i:s', strtotime($iso)) before the INSERT.
    'location_reports.reported_at' => 'local',
    'location_reports.received_at' => 'local',

    // Bridge-supplied Unix epoch rendered with date() (api/mesh.php:236); the
    // COALESCE fallback in that same statement is NOW(3), and all five readers
    // measure with NOW() (api/mesh.php:1212,1225, api/atak.php:138,
    // api/messaging-bridges.php:83, inc/channels/meshtastic.php:220).
    'mesh_packet_log.received_at' => 'local',

    // Operator-entered wall clock from a <input type="datetime-local">
    // (settings.php -> api/external-api-tokens.php:171 ->
    // inc/external-auth.php:70). Sibling columns use NOW()/CURRENT_TIMESTAMP.
    'external_api_tokens.expires_at' => 'local',
];

// Datetime-ish column-name heuristic, used when resolving which identifier in
// a comparison is the stored timestamp.
const TZA_DATE_NAME = '/(^|_)(at|on|date|time|until|expires?|expiry|seen|stamp|
                          timestamp|due|start|end|begin|created|updated|
                          deleted|last|next)($|_)/x';

function tza_looks_like_datetime(string $col): bool
{
    $c = strtolower($col);
    return (bool) preg_match(TZA_DATE_NAME, $c)
        || (bool) preg_match('/(_at|_date|_time|_ts|_until|_on)$/', $c);
}

/** Strip alias/backticks/function-wrappers off a captured column expression. */
function tza_clean_col(string $expr): string
{
    $e = trim($expr);
    // Peel MAX( ) / MIN( ) / COALESCE( ... ) wrappers, keep the first ident.
    if (preg_match('/^[A-Za-z_]+\s*\(\s*(.+?)\s*\)$/s', $e, $m)) {
        $e = trim(explode(',', $m[1])[0]);
    }
    $e = trim($e, '` ');
    // alias.col -> col
    if (strpos($e, '.') !== false) {
        $parts = explode('.', $e);
        $e = trim(end($parts), '` ');
    }
    return strtolower($e);
}

// ── 2. Collect source files ───────────────────────────────────────────────
//
// --path=<dir> scans an arbitrary root instead of the app tree. That is how
// tests/test_timezone_audit.php proves each detector actually fires: it points
// the REAL tool at fixture files rather than asserting against a hand-rolled
// copy of the matching logic (and without dropping bad SQL into the app tree).
$scanRoot = null;
foreach ($argv ?? [] as $a) {
    if (strpos($a, '--path=') === 0) { $scanRoot = substr($a, 7); }
}

/** Recursively collect files with the given extension under a directory. */
function tza_collect(string $dir, string $ext): array
{
    $out = [];
    if (!is_dir($dir)) { return $out; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $f) {
        $p = str_replace('\\', '/', $f->getPathname());
        if (!$f->isFile() || substr($p, -strlen($ext)) !== $ext) { continue; }
        if (strpos($p, '/vendor/') !== false || strpos($p, '/node_modules/') !== false) { continue; }
        $out[] = $p;
    }
    return $out;
}

$phpFiles = [];
$pyFiles  = [];
$jsFiles  = [];
if ($scanRoot !== null) {
    $phpFiles = tza_collect($scanRoot, '.php');
    $pyFiles  = tza_collect($scanRoot, '.py');
    $jsFiles  = tza_collect($scanRoot, '.js');
} else {
    foreach (['api', 'inc', 'sql', 'tools', 'proxy'] as $dir) {
        $phpFiles = array_merge($phpFiles, tza_collect($dir, '.php'));
    }
    foreach (glob('*.php') as $f) { $phpFiles[] = $f; }
    $pyFiles = tza_collect('services', '.py');
    $jsFiles = array_filter(
        tza_collect('assets/js', '.js'),
        static fn($p) => !preg_match('/\.min\.js$/', $p)
    );
}
sort($phpFiles);
sort($pyFiles);
sort($jsFiles);

echo count($phpFiles) . ' PHP files, ' . count($pyFiles) . " Python files scanned\n";

// ── 3. Pass one: harvest write-clock evidence from SQL literals ───────────
$writeClock = [];   // "table.col" and bare "col" => ['local'=>[sites], 'utc'=>[sites]]

function tza_note_write(string $table, string $col, string $clock, string $file, int $line): void
{
    global $writeClock;
    $col = strtolower(trim($col, '` '));
    if ($col === '') { return; }
    foreach (array_unique([$table !== '' ? "$table.$col" : '', $col]) as $key) {
        if ($key === '') { continue; }
        $writeClock[$key][$clock][] = "$file:$line";
    }
}

/** Which clock does this SQL scalar expression represent, if any? */
function tza_clock_of(string $expr): ?string
{
    if (preg_match('/\bUTC_(?:TIMESTAMP|DATE)\b/i', $expr)) { return 'utc'; }
    if (preg_match('/\b(?:NOW|CURRENT_TIMESTAMP|SYSDATE|CURDATE|CURRENT_DATE)\b/i', $expr)) { return 'local'; }
    return null;
}

$allSql = [];   // [file, line, normalized sql]
foreach ($phpFiles as $file) {
    $src = @file_get_contents($file);
    if ($src === false) { continue; }
    foreach (sql_extract_strings($src) as [$line, $raw]) {
        if (!sql_extract_is_query($raw)) { continue; }
        $norm = preg_replace('/\s+/', ' ', sql_extract_normalize($raw));
        $allSql[] = [$file, (int) $line, (string) $norm];
    }
}
echo count($allSql) . " SQL statements extracted\n";

foreach ($allSql as [$file, $line, $sql]) {
    // UPDATE t SET `col` = NOW()  /  col = UTC_TIMESTAMP()
    $tbl = '';
    if (preg_match('/\bUPDATE\s+`?([a-z0-9_]+)`?\s+SET\b/i', $sql, $m)) {
        $tbl = strtolower($m[1]);
    }
    if (preg_match_all('/`?([a-z0-9_]+)`?\s*=\s*([A-Za-z_]+\s*\(\s*\)[^,]*)/i', $sql, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $m) {
            $clock = tza_clock_of($m[2]);
            if ($clock !== null) { tza_note_write($tbl, $m[1], $clock, $file, $line); }
        }
    }

    // INSERT INTO t (a, b, c) VALUES (NOW(), ?, UTC_TIMESTAMP()) — positional.
    if (preg_match('/INSERT\s+(?:IGNORE\s+)?INTO\s+`?([a-z0-9_]+)`?\s*\(([^)]+)\)\s*VALUES\s*\((.+?)\)\s*(?:ON\s+DUPLICATE|$|;)/is', $sql, $m)) {
        $itbl = strtolower($m[1]);
        $cols = array_map(static fn($c) => strtolower(trim($c, '` ')), explode(',', $m[2]));
        // Split VALUES on top-level commas so NOW() stays intact.
        $vals = [];
        $depth = 0; $cur = '';
        foreach (str_split($m[3]) as $ch) {
            if ($ch === '(') { $depth++; }
            if ($ch === ')') { $depth--; }
            if ($ch === ',' && $depth === 0) { $vals[] = $cur; $cur = ''; continue; }
            $cur .= $ch;
        }
        $vals[] = $cur;
        if (count($vals) === count($cols)) {
            foreach ($cols as $i => $c) {
                $clock = tza_clock_of($vals[$i]);
                if ($clock !== null) { tza_note_write($itbl, $c, $clock, $file, $line); }
            }
        }
    }
}

// Registry entries are authoritative write evidence.
foreach ($APP_CLOCK as $key => $clock) {
    $writeClock[$key][$clock][] = 'tools/timezone_audit.php:$APP_CLOCK';
    $bare = substr($key, (int) strpos($key, '.') + 1);
    $writeClock[$bare][$clock][] = 'tools/timezone_audit.php:$APP_CLOCK';
}

// ── 4. Pass two: find comparisons against a UTC clock ─────────────────────
$findings = [];   // key => [[file, line, message], ...]

function tza_add(string $key, string $file, int $line, string $msg): void
{
    global $findings;
    $findings[$key][] = [$file, $line, $msg];
}

/** Extract [column, utcFunc] pairs compared inside one SQL statement. */
function tza_utc_comparisons(string $sql): array
{
    $hits = [];
    $u = 'UTC_TIMESTAMP\s*\(\s*\)|UTC_DATE\s*\(\s*\)';

    // TIMESTAMPDIFF(unit, <col>, UTC_TIMESTAMP())  and the reversed arg order.
    if (preg_match_all('/TIMESTAMPDIFF\s*\(\s*\w+\s*,\s*([^,]+?)\s*,\s*(' . $u . ')\s*\)/i', $sql, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $m) { $hits[] = [tza_clean_col($m[1]), $m[2]]; }
    }
    if (preg_match_all('/TIMESTAMPDIFF\s*\(\s*\w+\s*,\s*(' . $u . ')\s*,\s*([^,)]+?)\s*\)/i', $sql, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $m) { $hits[] = [tza_clean_col($m[2]), $m[1]]; }
    }
    // <col> <op> [DATE_SUB|DATE_ADD](UTC_TIMESTAMP(), ...)  /  <col> <op> UTC_...
    if (preg_match_all(
        '/([`\w.]+)\s*(?:>=|<=|<>|!=|>|<|=|\bBETWEEN\b)\s*(?:DATE_SUB|DATE_ADD|TIMESTAMPADD)?\s*\(?\s*(' . $u . ')/i',
        $sql, $mm, PREG_SET_ORDER
    )) {
        foreach ($mm as $m) { $hits[] = [tza_clean_col($m[1]), $m[2]]; }
    }
    // (DATE_SUB(UTC_TIMESTAMP(), ...)) <op> <col>   — reversed operand order.
    if (preg_match_all(
        '/(' . $u . ')(?:[^<>=]{0,60}?)\s*(?:>=|<=|<>|!=|>|<|=)\s*([`\w.]+)/i',
        $sql, $mm, PREG_SET_ORDER
    )) {
        foreach ($mm as $m) {
            $c = tza_clean_col($m[2]);
            if ($c !== '' && !is_numeric($c)) { $hits[] = [$c, $m[1]]; }
        }
    }

    // De-dupe, and drop captures that are plainly not columns.
    $out = [];
    foreach ($hits as [$c, $f]) {
        if ($c === '' || is_numeric($c)) { continue; }
        if (in_array($c, ['interval', 'second', 'minute', 'hour', 'day', 'null'], true)) { continue; }
        $out[$c . '|' . strtoupper(preg_replace('/\s+/', '', $f))] = [$c, strtoupper(preg_replace('/\s*\(\s*\)/', '()', $f))];
    }
    return array_values($out);
}

foreach ($allSql as [$file, $line, $sql]) {
    if (!preg_match('/\bUTC_(?:TIMESTAMP|DATE)\b/i', $sql)) { continue; }

    $tables = sql_extract_referenced_tables($sql);
    foreach (tza_utc_comparisons($sql) as [$col, $ufn]) {
        // Resolve to table.col when exactly one referenced table claims it.
        $qualified = '';
        foreach ($tables as $t) {
            if (isset($writeClock["$t.$col"])) { $qualified = "$t.$col"; break; }
        }
        $key = $qualified !== '' ? $qualified : $col;

        $ev = $writeClock[$key] ?? $writeClock[$col] ?? [];
        if (!empty($ev['local'])) {
            // The fatal shape: written local, measured against UTC.
            $src = $ev['local'][0];
            tza_add(
                "clock-conflict: $key measured against $ufn but written LOCAL",
                $file, $line,
                "$ufn vs `$col` — written with a local clock at $src"
                . (count($ev['local']) > 1 ? ' (+' . (count($ev['local']) - 1) . ' more writers)' : '')
            );
        } else {
            tza_add(
                "utc-compare: $key vs $ufn",
                $file, $line,
                "`$col` compared against $ufn; no local-write evidence found — "
                . 'confirm the writer really stamps UTC, then baseline this'
            );
        }
    }
}

// Any column with BOTH local and UTC write evidence is a split-brain writer.
foreach ($writeClock as $key => $ev) {
    if (strpos($key, '.') === false) { continue; }   // qualified keys only
    if (empty($ev['local']) || empty($ev['utc'])) { continue; }
    tza_add(
        "clock-conflict: $key written with BOTH clocks",
        $key, 0,
        'local at ' . $ev['local'][0] . ' vs UTC at ' . $ev['utc'][0]
        . ' — normalize the writers; a reader cannot be correct for both'
    );
}

// ── 5. Python daemons must stamp the install's area timezone ──────────────
//
// Discriminating a real bug from a false positive here comes down to the
// FORMAT, not the clock. `.isoformat()` (and any format carrying 'T' or 'Z')
// is a wire value — an ISO-8601 string for a /health JSON payload or an HTTP
// POST body — and carries its own offset, so UTC is correct there.
// `.strftime('%Y-%m-%d %H:%M:%S')` is a bare MySQL DATETIME literal with no
// offset attached: whatever clock produced it becomes the stored wall-clock
// value, so it MUST be the area zone. That is precisely the distinction
// between services/meshtastic/bridge.py (stats → isoformat → /health JSON,
// no DB writes at all) and the regression 0115613 fixed in the APRS listener.
const TZA_PY_UTC = '/datetime\.utcnow\s*\(|datetime\.now\s*\(\s*(?:datetime\.)?timezone\.utc\s*\)/';

foreach ($pyFiles as $file) {
    $src = @file_get_contents($file);
    if ($src === false) { continue; }
    $hasSql = (bool) preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET)\b/i', $src);
    foreach (explode("\n", $src) as $i => $lineTxt) {
        // Skip comments — the APRS listener documents the utcnow() bug it
        // already fixed, and a docstring is not a writer.
        $code = preg_replace('/#.*$/', '', $lineTxt);
        if (trim($code) === '' || !preg_match(TZA_PY_UTC, $code)) { continue; }

        $isDbFormat = (bool) preg_match(
            "/strftime\s*\(\s*['\"][^'\"]*%Y-%m-%d[ T]?%H:%M:%S[^'\"]*['\"]/",
            $code
        ) && !preg_match('/[TZ]\s*[\'"]\s*\)/', $code);

        if ($isDbFormat) {
            tza_add(
                "py-utc-datetime-write: $file",
                $file, $i + 1,
                'formats a UTC clock as a bare MySQL DATETIME literal — the PHP '
                . 'stack reads these columns with NOW(), so stamp the area '
                . 'timezone instead (see _now_local_str())'
            );
        } elseif ($hasSql && !preg_match('/isoformat\s*\(/', $code)) {
            tza_add(
                "py-utc-near-sql: $file",
                $file, $i + 1,
                'UTC stamp in a module that also writes SQL — confirm this value '
                . 'never lands in a DATETIME column, then baseline it'
            );
        }
    }
}

// ── 6. PHP: gmdate() producing a bare MySQL DATETIME literal ──────────────
//
// Every legitimate gmdate() in this tree emits a WIRE format that carries its
// own offset — 'Y-m-d\TH:i:s\Z' (18 sites), 'c', 'r', 'D, d M Y H:i:s T'.
// Those are correct: ISO-8601/RFC values are supposed to be UTC. A bare
// 'Y-m-d H:i:s' has no offset attached, so it is a MySQL DATETIME literal
// destined for a column this stack reads with NOW() — on a non-UTC server it
// lands one whole UTC offset away. Both real instances of this shape were
// bugs: api/mesh.php:236 (mesh_packet_log.received_at, split-brain with the
// NOW(3) fallback in the same statement) and api/external/v1/_auth.php:57
// (external_api_tokens.expires_at, tokens retired 5 h early).
foreach ($phpFiles as $file) {
    if (basename($file) === 'timezone_audit.php') { continue; }
    $src = @file_get_contents($file);
    if ($src === false) { continue; }
    foreach (explode("\n", $src) as $i => $lineTxt) {
        if (!preg_match_all('/gmdate\s*\(\s*([\'"])(.*?)\1/s', $lineTxt, $mm, PREG_SET_ORDER)) { continue; }
        foreach ($mm as $m) {
            $fmt = $m[2];
            // Wire formats declare their zone — skip them.
            if (preg_match('/\\\\T|\\\\Z|\bT\b|GMT|^[crU]$|D,|\\\\G\\\\M\\\\T/', $fmt)) { continue; }
            if (strpos($fmt, 'T') !== false || strpos($fmt, 'Z') !== false) { continue; }
            // Only the MySQL DATETIME/DATE shapes matter here.
            if (!preg_match('/^Y-m-d(?: H:i:s(?:\.v)?)?$/', $fmt)) { continue; }
            tza_add(
                "php-gmdate-datetime: $file",
                $file, $i + 1,
                "gmdate('$fmt') builds a bare MySQL DATETIME with no offset — "
                . 'this stack stores area-local wall clock and reads it with '
                . "NOW(); use date('$fmt')"
            );
        }
    }
}

// ── 7. JS: appending 'Z' to a MySQL DATETIME ──────────────────────────────
//
// The client-side face of the same bug. A MySQL DATETIME arrives as
// "2026-07-29 20:20:32" — area-local wall clock. Appending 'Z' tells the
// browser to read it as UTC while Date.now() stays browser-local, so every
// age is off by the UTC offset and healthy units render stale.
//
// Phase 58 fixed fmtAge() in owntracks-diagnostics.php and guarded it in
// tests/test_comm_identifiers_phase46.php:997 — but that guard string-matches
// fmtAge's exact `ts.replace(' ', 'T') + 'Z'` text, so a SECOND call site in
// the very same file (spelled r.last_post) survived, along with four more in
// assets/js/. Match the PATTERN, not one call site.
foreach (array_merge($jsFiles, $phpFiles) as $file) {
    // This file quotes both patterns in its own regexes and comments.
    if (basename($file) === 'timezone_audit.php') { continue; }
    $src = @file_get_contents($file);
    if ($src === false) { continue; }
    foreach (explode("\n", $src) as $i => $lineTxt) {
        // .replace(' ', 'T') + 'Z'   — with any spacing/quote style.
        if (preg_match('/replace\s*\(\s*([\'"])\s\1\s*,\s*([\'"])T\2\s*\)\s*\+\s*([\'"])Z\3/', $lineTxt)) {
            tza_add(
                "js-utc-suffix: $file",
                $file, $i + 1,
                "appends 'Z' to a MySQL DATETIME — that reads area-local wall "
                . 'clock as UTC and skews every age by the UTC offset; parse '
                . "without the 'Z'"
            );
        }
        // toISOString() reshaped into a MySQL DATETIME — a UTC wall clock
        // wearing a local-looking format. chat-widget.js built its optimistic
        // echo this way, so a just-sent message rendered a UTC offset off
        // until the next poll overwrote it with the server's value.
        if (preg_match('/toISOString\s*\(\s*\)\s*\.\s*slice\s*\([^)]*\)\s*\.\s*replace\s*\(\s*([\'"])T\1/', $lineTxt)
            || preg_match('/toISOString\s*\(\s*\)\s*\.\s*replace\s*\(\s*([\'"])T\1[^)]*\)\s*\.\s*slice/', $lineTxt)) {
            tza_add(
                "js-iso-as-datetime: $file",
                $file, $i + 1,
                'reshapes toISOString() (UTC) into a MySQL DATETIME string — '
                . 'build the value from local getters instead (see '
                . 'localSqlNow() in assets/js/chat-widget.js)'
            );
        }
    }
}

// ── 8. Report ─────────────────────────────────────────────────────────────
$baselineFile = __DIR__ . '/timezone_audit_baseline.txt';
$baseline = [];
if (is_file($baselineFile)) {
    foreach (file($baselineFile) as $l) {
        $l = trim($l);
        if ($l !== '' && $l[0] !== '#') { $baseline[] = $l; }
    }
}

ksort($findings);
$newCount = 0;
foreach ($findings as $key => $sites) {
    $inBaseline = in_array($key, $baseline, true);
    if ($inBaseline && !$showAll) { continue; }
    if (!$inBaseline) { $newCount++; }
    echo ($inBaseline ? '[baseline] ' : '[NEW]      ') . $key . "\n";
    foreach (array_slice($sites, 0, 5) as [$f, $l, $msg]) {
        echo "             $f" . ($l ? ":$l" : '') . " — $msg\n";
    }
    if (count($sites) > 5) { echo '             … +' . (count($sites) - 5) . " more sites\n"; }
}

echo "\n" . count($findings) . " distinct finding(s), $newCount new (not in baseline)\n";
if ($newCount > 0) {
    echo "\nOn this stack DATETIME columns hold wall-clock time in the install's\n"
       . "area_timezone, and the MySQL session time_zone is synced to match — so\n"
       . "NOW() is the right clock and UTC_TIMESTAMP() is off by the UTC offset on\n"
       . "every non-UTC server. Fix the query, or baseline it in\n"
       . "tools/timezone_audit_baseline.txt if the column really is stored in UTC.\n";
}
exit($newCount === 0 ? 0 : 1);
