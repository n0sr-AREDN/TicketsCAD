<?php
/**
 * Phase 123 — `teams` has ONE canonical schema, and nothing invents a second.
 *
 * Origin: `teams` had two competing CREATE TABLE definitions (base_schema.sql's
 * real one, and an invented one in membership.sql). Both used
 * CREATE TABLE IF NOT EXISTS, so the shape you got depended on script order;
 * where both applied, different code paths wrote to different halves and
 * produced teams with a type but no name. A beta tester's rebuilt install got
 * the invented shape and the Teams screen died with "Unknown column t.team".
 *
 * These tests lock in the canonical schema AND generalise the guard: no table
 * anywhere may be defined twice.
 */

require_once __DIR__ . '/../config.php';

$base = realpath(__DIR__ . '/..');
echo "=== Phase 123 — canonical `teams` schema ===\n\n";
$pass = 0; $fail = 0;
function ok($n)  { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad($n, $why = '') { global $fail; echo "[FAIL] $n" . ($why ? " — $why" : '') . "\n"; $fail++; }

// ── 1. Exactly ONE CREATE TABLE for teams (excluding the explicit reset file) ──
$defs = [];
foreach (glob("$base/sql/*.sql") ?: [] as $f) {
    if (strpos(basename($f), 'RESET_DESTRUCTIVE') !== false) continue;   // deliberate full-reset variant
    $s = file_get_contents($f) ?: '';
    if (preg_match('/CREATE TABLE\s+(IF NOT EXISTS\s+)?`?teams`?\s*\(/i', $s)) $defs[] = basename($f);
}
count($defs) === 1
    ? ok('exactly one CREATE TABLE for `teams` (' . $defs[0] . ')')
    : bad('one CREATE TABLE for `teams`', count($defs) . ' found: ' . implode(', ', $defs));

// ── 2. membership.sql must NOT redefine teams ─────────────────────────────
$mem = @file_get_contents("$base/sql/membership.sql") ?: '';
(stripos($mem, 'CREATE TABLE IF NOT EXISTS `teams`') === false)
    ? ok('membership.sql no longer defines a second `teams` table') : bad('membership.sql redefines teams');
(stripos($mem, 'canonical definition lives in base_schema') !== false)
    ? ok('membership.sql explains where the canonical definition lives') : bad('membership.sql explanatory note');

// ── 3. No code may reference the invented duplicate columns on teams ──────
// Only the duplicates are banned; `active` is a legitimate addition and stays.
$invented = ['team_type', 'leader_id', 'deputy_id'];
$offenders = [];
foreach (array_merge(glob("$base/api/*.php") ?: [], glob("$base/inc/*.php") ?: []) as $f) {
    $s = file_get_contents($f) ?: '';
    // Only look at files that actually query the teams table.
    if (strpos($s, "db_table('teams')") === false) continue;
    foreach ($invented as $col) {
        // `t.<col>` = reading the dropped column. Aliases (AS leader_id) are fine.
        if (preg_match('/\bt\.`?' . preg_quote($col, '/') . '`?\b/', $s)) {
            $offenders[] = basename($f) . ':t.' . $col;
        }
    }
    if (preg_match('/\bt\.`?name`?\s+AS\s+(team_name|assigned_team_name)/i', $s)) {
        $offenders[] = basename($f) . ':t.name (dropped duplicate)';
    }
}
empty($offenders)
    ? ok('no teams query reads a dropped duplicate column')
    : bad('teams queries use dropped columns', implode(', ', $offenders));

// ── 4. The normalization migration exists and is non-destructive to data ──
$mig = "$base/sql/run_teams_schema_normalize.php";
is_file($mig) ? ok('normalization migration exists') : bad('migration exists');
$ms = @file_get_contents($mig) ?: '';
(strpos($ms, 'DELETE') === false)
    ? ok('migration never deletes rows') : bad('migration deletes rows');
(stripos($ms, 'backfill') !== false && strpos($ms, 'DROP COLUMN') !== false)
    ? ok('migration backfills canonical BEFORE dropping duplicates') : bad('migration backfill+drop');

// ── 5. GENERALISED GUARD: no table may be defined twice anywhere in sql/ ──
// This is the class-level fix — the specific bug was one instance of it.
$byTable = [];
foreach (glob("$base/sql/*.sql") ?: [] as $f) {
    if (strpos(basename($f), 'RESET_DESTRUCTIVE') !== false) continue;
    $s = file_get_contents($f) ?: '';
    if (preg_match_all('/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`?([A-Za-z0-9_]+)`?\s*\(/i', $s, $m)) {
        foreach ($m[1] as $t) { $byTable[strtolower($t)][] = basename($f); }
    }
}
// KNOWN OUTSTANDING duplicates, found by this guard on 2026-07-25 when it was
// written. Each is a latent copy of the `teams` bug and should be normalized
// the same way (inspect the live columns, pick the canonical definition,
// backfill, drop the duplicates, fix the code). They are listed — not ignored —
// so the debt is visible and, critically, so any NEW duplicate fails the suite.
// Remove entries from this list as they are fixed; never add to it.
// Phase 124 (2026-07-26): all four are FIXED — base_schema.sql is now the single
// source of CREATE TABLE, and the columns the removed definitions contributed
// are ensured by sql/run_schema_canonicalize.php. The list is empty and must
// stay that way: any table defined twice now fails the suite outright.
$knownDebt = [];

$dupes = [];
foreach ($byTable as $t => $files) {
    $files = array_unique($files);
    if (count($files) > 1) $dupes[$t] = $t . ' (' . implode(' + ', $files) . ')';
}
$newDupes = array_diff_key($dupes, array_flip($knownDebt));
empty($newDupes)
    ? ok('no NEW table is defined by two different files')
    : bad('NEW duplicate table definitions introduced', implode('; ', $newDupes));

// `teams` specifically must be clean — it is the one this phase fixed.
!isset($dupes['teams']) ? ok('`teams` is no longer double-defined') : bad('teams still double-defined');

$stillOwed = array_intersect_key($dupes, array_flip($knownDebt));
if ($stillOwed) {
    echo "[NOTE] " . count($stillOwed) . " table(s) still carry duplicate definitions (known debt):\n";
    foreach ($stillOwed as $d) echo "         - $d\n";
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
