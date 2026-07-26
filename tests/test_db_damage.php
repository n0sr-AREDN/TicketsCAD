<?php
/**
 * Phase 121 — storage-damage detection ("empty" must never mean "damaged").
 *
 * Beta report 2026-07-25: a crashed `teams` table made the Personnel screen
 * render 0 people (the roster query LEFT JOINs teams) while System Overview
 * correctly reported 8, because the read helper swallowed the exception and
 * returned []. The operator reasonably concluded the data was lost.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db-damage.php';

$base = realpath(__DIR__ . '/..');
echo "=== Phase 121 — DB storage-damage detection ===\n\n";
$pass = 0; $fail = 0;
function ok($n)  { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad($n, $why = '') { global $fail; echo "[FAIL] $n" . ($why ? " — $why" : '') . "\n"; $fail++; }

// ── 1. Real driver messages that ARE damage ────────────────────────
$damaged = [
    "SQLSTATE[42S02]: Base table or view not found: 1932 Table 'ticketscad.teams' doesn't exist in engine"
        => ['teams', 'missing_tablespace'],
    "SQLSTATE[HY000]: General error: 1194 Table './ticketscad/equipment' is marked as crashed and should be repaired"
        => ['equipment', 'crashed'],
    "SQLSTATE[HY000]: General error: 145 Table '.\\ticketscad\\vehicles' is marked as crashed and last (automatic?) repair failed"
        => ['vehicles', 'crashed'],
    "SQLSTATE[HY000]: General error: 1034 Incorrect key file for table 'member'; try to repair it"
        => ['member', 'bad_index'],
    "SQLSTATE[HY000]: General error: 1030 Got error 194 from storage engine"
        => [null, 'engine_error'],
    "SQLSTATE[HY000]: General error: 1812 Tablespace is missing for table 'ticket'"
        => ['ticket', 'missing_tablespace'],
];
foreach ($damaged as $msg => $expect) {
    $got = db_damage_classify($msg);
    if (!$got) { bad('classified as damage: ' . substr($msg, 0, 60)); continue; }
    $kindOk  = $got['kind'] === $expect[1];
    $tableOk = ($expect[0] === null) || ($got['table'] === $expect[0]);
    ($kindOk && $tableOk)
        ? ok("damage: {$expect[1]}" . ($expect[0] ? " on `{$expect[0]}`" : ''))
        : bad("damage: {$expect[1]}", 'got kind=' . $got['kind'] . ' table=' . var_export($got['table'], true));
}

// ── 2. Things that are NOT damage (must not false-positive) ────────
// A merely-missing table is a pending migration and MUST keep flowing through
// the existing graceful-degradation paths — flagging it would break older
// schemas everywhere.
$notDamage = [
    "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'ticketscad.optional_thing' doesn't exist",
    "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'hide' in 'field list'",
    "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' for key 'PRIMARY'",
    "SQLSTATE[HY000] [2002] Connection refused",
    "SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax",
];
foreach ($notDamage as $msg) {
    db_damage_classify($msg) === null
        ? ok('not damage: ' . substr($msg, 0, 52))
        : bad('not damage: ' . substr($msg, 0, 52), 'false positive');
}

// ── 3. Recording + payload ─────────────────────────────────────────
$GLOBALS['_db_damage'] = [];   // reset
db_damage_seen() === false ? ok('no damage recorded initially') : bad('clean initial state');

db_damage_note(new RuntimeException(
    "SQLSTATE[42S02]: Base table or view not found: 1932 Table 'ticketscad.teams' doesn't exist in engine"));
db_damage_seen() === true ? ok('damage is recorded') : bad('damage is recorded');

db_damage_note(new RuntimeException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'x'"));
$p = db_damage_payload();
(count($p['damaged_tables']) === 1 && $p['damaged_tables'][0] === 'teams')
    ? ok('non-damage exceptions do not pollute the table list') : bad('table list clean', json_encode($p['damaged_tables']));

(stripos($p['error'], 'NOT an empty list') !== false && stripos($p['error'], 'recoverable') !== false)
    ? ok('message says "not empty" and "recoverable"') : bad('reassuring message', $p['error']);
(!empty($p['next_steps']) && stripos(implode(' ', $p['next_steps']), 'mysqlcheck') !== false)
    ? ok('payload gives actionable next steps (mysqlcheck)') : bad('next steps');

// ── 4. Intercept only replaces SUCCESS replies ─────────────────────
is_array(db_damage_intercept(200)) ? ok('intercepts a 200 success') : bad('intercepts 200');
db_damage_intercept(403) === null  ? ok('leaves a 403 error reply alone') : bad('leaves 4xx alone');
db_damage_intercept(500) === null  ? ok('leaves a 500 error reply alone') : bad('leaves 5xx alone');

$GLOBALS['_db_damage'] = [];   // reset
db_damage_intercept(200) === null ? ok('no interception when nothing is damaged') : bad('clean pass-through');

// ── 5. Wiring ──────────────────────────────────────────────────────
$dbsrc = @file_get_contents("$base/inc/db.php") ?: '';
(strpos($dbsrc, 'db_damage_note(') !== false && strpos($dbsrc, 'throw $e') !== false)
    ? ok('db_query records damage and still rethrows') : bad('db_query wiring');

$fn = @file_get_contents("$base/inc/functions.php") ?: '';
(strpos($fn, 'db_damage_intercept(') !== false && strpos($fn, '503') !== false)
    ? ok('json_response surfaces damage as 503') : bad('json_response wiring');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
