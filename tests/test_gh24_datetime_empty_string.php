<?php
/**
 * GH #24 — a DATETIME column must never be compared to the empty string,
 * and the auto-close gate must fail CLOSED when it cannot count.
 *
 * ── WHAT HAPPENED ────────────────────────────────────────────────────
 *
 * `assigns.clear` is DATETIME. Six queries tested it with a three-term
 * "open assignment" predicate that carried a MyISAM-era null-substitute:
 *
 *     (clear IS NULL OR clear = '' OR clear = '0000-00-00 00:00:00')
 *
 * MySQL 8.0 refuses the middle term outright — SQLSTATE[HY000] 1525,
 * "Incorrect DATETIME value: ''" — regardless of strict mode, and one bad
 * term throws the whole query. MariaDB coerces '' to the zero-date, so on
 * the development stack the term is simply redundant and nothing ever
 * surfaced. Every affected install ran MySQL 8.0.
 *
 * The damage was not the exception, it was what each caller did with it:
 *
 *   * inc/auto_close.php  caught it and `return 0` — and on that code
 *     path 0 is not a neutral failure value, it is the single answer that
 *     means "no crews left on this incident", i.e. exactly the condition
 *     that authorises an auto-close. Incidents closed with crews still
 *     assigned, those crews were force-cleared, and the audit log
 *     recorded "all units clear" as fact. The sweep's re-check safety net
 *     called the same function and was defeated the same way.
 *   * inc/router_recipients.php caught it and resolved every
 *     assignment-based push predicate to zero users, reported as
 *     "recipient predicate matched zero users" — which reads as a correct
 *     empty result rather than a failure.
 *   * api/message-tray.php caught it and fell back to a query with no
 *     deleted_at or status filter at all, so the picker listed
 *     soft-deleted and closed incidents.
 *
 * ── WHY THIS TEST IS SHAPED THIS WAY ─────────────────────────────────
 *
 * Two of the nine sites were missed by a hand-search of the tree, and one
 * of those two was affirmatively classified as safe, because it was
 * written `` `clear` = '' `` with backticks and a grep for `clear = ''`
 * does not find it. So the gate below does not grep for a remembered
 * spelling: it asks the DATABASE which columns are date/datetime/timestamp
 * and then checks every SQL literal in the tree against that list.
 *
 * And because a wiring check that only greps for the fix would have
 * passed for the whole time the bug was live, the second half drives the
 * real functions against real rows — including the failure path, by
 * pointing the prefix at a schema where `ticket` exists and `assigns`
 * does not, which is the shape of the fault as MySQL 8.0 produced it.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/auto_close.php';
require_once __DIR__ . '/../tools/sql_extract.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$realPrefix = $prefix;

$pass = 0; $fail = 0;
function ok(string $n): void  { global $pass; $pass++; echo "[PASS] $n\n"; }
function bad(string $n, string $why = ''): void {
    global $fail; $fail++; echo "[FAIL] $n" . ($why !== '' ? " — $why" : '') . "\n";
}

echo "=== GH #24 — DATETIME vs '' , and auto-close failing closed ===\n\n";

// ─────────────────────────────────────────────────────────────────────
// 1. The static gate: no temporal column compared to ''
// ─────────────────────────────────────────────────────────────────────

$temporalCols = [];
try {
    foreach (db_fetch_all(
        "SELECT DISTINCT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND DATA_TYPE IN ('datetime','date','timestamp')") as $r) {
        $c = (string) $r['COLUMN_NAME'];
        // Skip names that are not plausible SQL identifiers in this tree
        // and would only generate noise.
        if ($c === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $c)) continue;
        $temporalCols[strtolower($c)] = true;
    }
} catch (Exception $e) {
    echo "SKIP: cannot read information_schema — {$e->getMessage()}\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}
if (!$temporalCols) {
    echo "SKIP: no date/datetime/timestamp columns found (empty schema?)\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}
ok('schema reports ' . count($temporalCols) . ' distinct temporal column names');

/** Every .php file in the app tree, excluding vendored + scratch dirs. */
function gh24_php_files(string $root): array {
    $skip = ['/vendor/', '/node_modules/', '/.git/', '/.claude/', '/cache/',
             '/backups/', '/uploads/', '/tile-cache/'];
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') continue;
        $path = str_replace('\\', '/', $f->getPathname());
        foreach ($skip as $s) { if (strpos($path, $s) !== false) continue 2; }
        $out[] = $path;
    }
    sort($out);
    return $out;
}

/**
 * Find temporal-column-vs-'' comparisons in one PHP source.
 *
 * Works off sql_extract_strings(), so it looks ONLY inside SQL literals:
 * PHP comments and array-key comparisons (`$row['date'] === ''`) are not
 * SQL and are not findings. That also means the explanatory comments in
 * the fixed files, which necessarily quote the old broken predicate, do
 * not read as regressions.
 *
 * @return array<int,string> human-readable "~line  fragment (column)" hits
 */
function gh24_scan_src(string $src, array $temporalCols): array {
    if (strpos($src, "= ''") === false && strpos($src, "=''") === false) {
        return [];              // cheap reject before the tokenizer
    }
    $hits = [];
    foreach (sql_extract_strings($src) as [$line, $sql]) {
        if (!sql_extract_is_query($sql)) continue;
        foreach (array_keys($temporalCols) as $col) {
            // Optional `alias.` qualifier, optional backticks, then a
            // comparison against the empty string. The leading lookbehind
            // stops `problemend` matching the column `end`.
            $re = '/(?<![\w`])(?:`?\w+`?\s*\.\s*)?`?'
                . preg_quote($col, '/')
                . '`?\s*(?:=|<>|!=|<=>)\s*\'\'/i';
            if (preg_match($re, $sql, $m)) {
                $hits[] = '~' . $line . '  ' . trim($m[0])
                    . '   (column `' . $col . '` is date/datetime/timestamp)';
                break;          // one report per SQL literal is enough
            }
        }
    }
    return $hits;
}

// ── Positive control ─────────────────────────────────────────────────
// A gate that reports "clean" is only meaningful if it can report dirty.
// Plant each of the three spellings that appeared in the real code and
// confirm the detector sees all of them.
foreach ([
    'bare'    => '<?php db_query("SELECT COUNT(*) FROM a WHERE clear = \'\'");',
    'aliased' => '<?php db_query("SELECT 1 FROM assigns a WHERE a.clear = \'\'");',
    'quoted'  => '<?php db_query("SELECT 1 FROM a WHERE `clear` = \'\'");',
] as $shape => $probe) {
    if (gh24_scan_src($probe, $temporalCols)) ok("detector catches the $shape spelling");
    else bad("detector MISSES the $shape spelling — the gate would pass while broken");
}
// ...and does not fire on prose or on PHP-level empty-string tests.
$benign = '<?php // historical note: clear = \'\' used to be here' . "\n"
        . 'if ($row["clear"] === "") { $x = 1; }' . "\n"
        . 'db_query("SELECT 1 FROM assigns WHERE clear IS NULL OR clear = \'0000-00-00 00:00:00\'");';
if (!gh24_scan_src($benign, $temporalCols)) ok('detector ignores comments, PHP comparisons and the correct predicate');
else bad('detector false-positives on benign source');

$root  = str_replace('\\', '/', dirname(__DIR__));
$self  = str_replace('\\', '/', __FILE__);
$files = gh24_php_files($root);
ok('scanning ' . count($files) . ' PHP files');

$offenders = [];
foreach ($files as $path) {
    // This file is the one legitimate exception: its positive control
    // above plants all three broken spellings on purpose.
    if ($path === $self) continue;
    $src = @file_get_contents($path);
    if ($src === false) continue;
    foreach (gh24_scan_src($src, $temporalCols) as $hit) {
        $offenders[] = str_replace($root . '/', '', $path) . ':' . $hit;
    }
}

if (!$offenders) {
    ok('no temporal column is compared to the empty string anywhere in the tree');
} else {
    bad('temporal column compared to \'\' — MySQL 8.0 raises 1525 and throws the whole query',
        count($offenders) . ' site(s)');
    foreach ($offenders as $o) echo "        $o\n";
    echo "        FIX: drop the `= ''` term. `IS NULL` plus\n"
       . "        `= '0000-00-00 00:00:00'` covers every legacy row it stood in for.\n";
}

// The eight files that carried it, named so the gate is anchored to the
// real sites rather than only to a tree-wide sweep that a future
// exclusion could quietly narrow.
foreach ([
    'inc/auto_close.php', 'inc/router_recipients.php', 'inc/bed_auto.php',
    'inc/responder-write.php', 'api/owntracks-config.php',
    'tools/bed_auto_diagnose.php', 'inc/status-workflow.php',
    'api/message-tray.php',
] as $known) {
    $p = $root . '/' . $known;
    if (!is_file($p)) { bad("known GH #24 site missing: $known"); continue; }
    $hits = gh24_scan_src((string) file_get_contents($p), $temporalCols);
    if ($hits) bad("$known still compares a temporal column to ''", implode(' | ', $hits));
    else       ok("$known: clean");
}

// ─────────────────────────────────────────────────────────────────────
// 2. The counting path, driven for real
// ─────────────────────────────────────────────────────────────────────

$ticketId = 0; $respA = 0; $respB = 0;
$cleanup = function () use (&$ticketId, &$respA, &$respB, $realPrefix) {
    $GLOBALS['db_prefix'] = $realPrefix;
    try {
        if ($ticketId) {
            db_query("DELETE FROM `{$realPrefix}assigns` WHERE ticket_id = ?", [$ticketId]);
            db_query("DELETE FROM `{$realPrefix}ticket`  WHERE id = ?",        [$ticketId]);
        }
        foreach ([$respA, $respB] as $rid) {
            if ($rid) db_query("DELETE FROM `{$realPrefix}responder` WHERE id = ?", [$rid]);
        }
        db_query("DROP TABLE IF EXISTS `{$realPrefix}gh24_ticket`");
    } catch (Exception $e) { echo "  (cleanup warning: {$e->getMessage()})\n"; }
};
register_shutdown_function($cleanup);

// Warm get_variable()'s static cache while the prefix is still real, so
// the fault-injection below cannot accidentally test the settings read
// instead of the assigns read.
auto_close_enabled();

db_query(
    "INSERT INTO `{$prefix}ticket` (in_types_id, scope, description, status, `date`)
     VALUES (1, 'gh24 auto-close sentinel', '', 2, NOW())");
$ticketId = (int) db_insert_id();

// Dedicated sentinel units. Deliberately NOT the lowest-id responder:
// borrowing whatever unit happens to sort first is what made
// tests/test_phase16a_par.php order-dependent.
db_query("INSERT INTO `{$prefix}responder` (`name`, `description`)
          VALUES ('gh24 sentinel A', 'GH24 test sentinel')");
$respA = (int) db_insert_id();
db_query("INSERT INTO `{$prefix}responder` (`name`, `description`)
          VALUES ('gh24 sentinel B', 'GH24 test sentinel')");
$respB = (int) db_insert_id();

foreach ([$respA, $respB] as $rid) {
    db_query("INSERT INTO `{$prefix}assigns` (ticket_id, responder_id, user_id, dispatched)
              VALUES (?, ?, 1, NOW())", [$ticketId, $rid]);
}

$n = _auto_close_active_assigns($ticketId);
if ($n === 2) ok('two units assigned, neither cleared -> count 2');
else          bad('count with two open assigns', "got " . var_export($n, true));

// Clear ONE unit. This is the exact reported scenario: the operator
// cleared one crew while the other was still on scene.
db_query("UPDATE `{$prefix}assigns` SET `clear` = NOW()
           WHERE ticket_id = ? AND responder_id = ?", [$ticketId, $respA]);

$n = _auto_close_active_assigns($ticketId);
if ($n === 1) ok('one cleared, one still on scene -> count 1');
else          bad('count with one open assign', "got " . var_export($n, true));

$r = auto_close_maybe_schedule($ticketId, 1);
if (empty($r['scheduled']) && ($r['reason'] ?? '') === 'active_assigns_remain') {
    ok('auto-close DECLINES while a crew is still assigned');
} else {
    bad('auto-close must not schedule with a crew assigned', var_export($r, true));
}

// Clear the last unit — auto-close must still work. A "fix" that merely
// stopped auto-close from ever firing would pass every assertion above.
db_query("UPDATE `{$prefix}assigns` SET `clear` = NOW()
           WHERE ticket_id = ? AND responder_id = ?", [$ticketId, $respB]);

$n = _auto_close_active_assigns($ticketId);
if ($n === 0) ok('both units cleared -> count 0');
else          bad('count with no open assigns', "got " . var_export($n, true));

$r = auto_close_maybe_schedule($ticketId, 1);
if (!empty($r['scheduled'])) ok('auto-close SCHEDULES once the last unit clears');
else                         bad('auto-close should schedule when all clear', var_export($r, true));

db_query("UPDATE `{$prefix}ticket` SET auto_close_scheduled_at = NULL WHERE id = ?", [$ticketId]);

// ─────────────────────────────────────────────────────────────────────
// 3. Fail closed: a count that could not be taken is not a count of zero
// ─────────────────────────────────────────────────────────────────────
//
// Reproduce the MySQL 8.0 shape — the ticket read succeeds, the assigns
// read throws — by pointing the prefix at a schema where `ticket` exists
// and `assigns` does not.

db_query("DROP TABLE IF EXISTS `{$prefix}gh24_ticket`");
db_query("CREATE TABLE `{$prefix}gh24_ticket` LIKE `{$prefix}ticket`");
// Deliberately NOT scheduled yet: auto_close_maybe_schedule() short-circuits
// on an existing schedule before it ever counts, so testing the count gate
// requires an unscheduled ticket.
db_query("INSERT INTO `{$prefix}gh24_ticket`
          (id, in_types_id, scope, description, status, `date`, auto_close_scheduled_at)
          VALUES (?, 1, 'gh24 fault injection', '', 2, NOW(), NULL)",
         [$ticketId]);

$GLOBALS['db_prefix'] = $realPrefix . 'gh24_';

$n = _auto_close_active_assigns($ticketId);
if ($n < 0) ok('a count that could not be taken returns a negative sentinel, not 0');
else        bad('failed count must not read as zero', "got " . var_export($n, true));

$r = auto_close_maybe_schedule($ticketId, 1);
if (empty($r['scheduled']) && ($r['reason'] ?? '') === 'active_assigns_unknown') {
    ok('auto-close DECLINES when the assign count cannot be obtained');
} else {
    bad('auto-close must not schedule on an unobtainable count', var_export($r, true));
}

// Now plant a due schedule and confirm the sweep — the safety net that
// re-checks at fire time — also refuses, and does not throw the schedule
// away in the process.
db_query("UPDATE `{$realPrefix}gh24_ticket`
             SET auto_close_scheduled_at = DATE_SUB(NOW(), INTERVAL 5 MINUTE)
           WHERE id = ?", [$ticketId]);

$sw = auto_close_sweep(5);
if (($sw['closed'] ?? -1) === 0) ok('sweep closes nothing when the assign count is unobtainable');
else                             bad('sweep must not close on an unobtainable count', var_export($sw, true));

$still = db_fetch_value(
    "SELECT auto_close_scheduled_at FROM `{$realPrefix}gh24_ticket` WHERE id = ?", [$ticketId]);
if (!empty($still) && substr((string) $still, 0, 4) !== '0000') {
    ok('sweep leaves the schedule in place so the next sweep retries');
} else {
    bad('sweep should not discard a schedule it could not evaluate', var_export($still, true));
}

$GLOBALS['db_prefix'] = $realPrefix;
ok('prefix restored');

$cleanup();
$ticketId = 0; $respA = 0; $respB = 0;   // shutdown handler becomes a no-op

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
