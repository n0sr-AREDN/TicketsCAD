<?php
/**
 * Phase 137 — teams de-duplication + UNIQUE(team) (2026-08-06).
 *
 * GH: Chris Byrd, Google Group -- "After the update to 4.2.8 it did fix the
 * teams issue but ... it duplicated all the teams in the list." Root cause:
 * `teams` had a PRIMARY KEY on `id` and nothing else -- no unique
 * constraint on `team` (the name column) -- so sql/membership.sql's
 * `INSERT IGNORE INTO teams (team, mission, active) VALUES (...)` was never
 * actually protected by IGNORE the way its sibling seeds (member_types,
 * member_status, certifications) are. install_fresh.php re-imports
 * membership.sql whenever its tracked content hash changes, by design --
 * and with nothing for IGNORE to suppress, that re-import just inserted
 * the same four starter teams again. Confirmed reproduced on this
 * session's own long-lived local dev database before this fix (two full
 * sets of the four starter teams).
 *
 * This test exercises the REAL migration script as a subprocess (not a
 * reimplementation of its logic, which could silently drift from what
 * actually ships) against a REAL duplicate condition it creates itself:
 * temporarily drop the unique key (if present), insert controlled
 * duplicates including an overlapping team_members assignment, run
 * sql/run_phase137_teams_name_unique.php for real, then assert the merge
 * was correct and the key is back -- restoring the exact protected state
 * the test found the database in, so this leaves no residue either way.
 */

declare(strict_types=1);
chdir(dirname(__DIR__));

require_once 'config.php';
require_once 'inc/db.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; }
    else { $fail++; echo "  FAIL: {$what}\n"; }
}

$prefix     = $GLOBALS['db_prefix'] ?? '';
$teamsTbl   = $prefix . 'teams';
$membersTbl = $prefix . 'team_members';

// ── Structural checks ───────────────────────────────────────────────────

$migSrc = file_get_contents('sql/run_phase137_teams_name_unique.php');
ok('migration is CLI-only', (bool) preg_match('/PHP_SAPI\s*!==\s*[\'"]cli[\'"]/', $migSrc));
ok('migration verifies its own outcome before exiting 0', (bool) preg_match('/verify the outcome|verify.*outcome/i', $migSrc));

$baseSchema = file_get_contents('sql/base_schema.sql');
ok('base_schema.sql\'s teams table declares uk_teams_team_name',
   (bool) preg_match('/CREATE TABLE.*?`teams`.*?UNIQUE KEY `uk_teams_team_name` \(`team`\)/s', $baseSchema));

$membershipSql = file_get_contents('sql/membership.sql');
ok('membership.sql no longer calls the teams INSERT IGNORE merely "defensive"',
   !preg_match('/teams\.name.*defensive/i', $membershipSql));

// ── Functional: exercise the real merge logic against a real duplicate ──
//
// Everything from here on runs inside try/finally: this test deliberately
// creates duplicate rows (the exact condition it's proving the fix
// resolves), and if any assertion or exception fires partway through, a
// bare exit would leave that test data behind -- which happened for real
// during this fix's own development (two crashed early revisions of this
// file left "Phase137-Dedupe-Test-*" rows sitting in the live teams list
// until they were noticed and cleaned up by hand). The finally block below
// removes anything matching the marker prefix regardless of how far the
// test got, so a future regression here fails loudly without polluting
// whatever database it ran against.

$marker = 'Phase137-Dedupe-Test-' . getmypid();

try {

$hadUniqueKey = (int) db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'uk_teams_team_name'",
    [$teamsTbl]
) > 0;

if ($hadUniqueKey) {
    db_query("ALTER TABLE `{$teamsTbl}` DROP INDEX `uk_teams_team_name`");
}

$genCols = array_column(db_fetch_all(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND EXTRA LIKE '%GENERATED%'",
    [$teamsTbl]), 'COLUMN_NAME');
$teamCols = array_diff(array_column(db_fetch_all("SHOW COLUMNS FROM `{$teamsTbl}`"), 'Field'), $genCols);

$legacyDefaults = ['sub-group' => '', 'ttypes_id' => 0, 'mission' => 'test', 'leader' => 0, 'leader_dpty' => 0, 'by' => 0, 'from' => 'test'];
function insertTeam(string $table, array $cols, array $legacyDefaults, string $name): int {
    $fields = ['team' => $name];
    foreach ($legacyDefaults as $c => $v) { if (in_array($c, $cols, true)) $fields[$c] = $v; }
    $colList = implode(',', array_map(fn($c) => "`{$c}`", array_keys($fields)));
    $ph = implode(',', array_fill(0, count($fields), '?'));
    if (in_array('on', $cols, true)) {
        db_query("INSERT INTO `{$table}` ({$colList}, `on`) VALUES ({$ph}, NOW())", array_values($fields));
    } else {
        db_query("INSERT INTO `{$table}` ({$colList}) VALUES ({$ph})", array_values($fields));
    }
    return (int) db_insert_id();
}

// Group A: two rows, one with 2 members, one with 0 -- the one WITH
// members must survive (real-usage signal beats lowest-id).
$aEmptyId = insertTeam($teamsTbl, $teamCols, $legacyDefaults, $marker . '-A');
$aBusyId  = insertTeam($teamsTbl, $teamCols, $legacyDefaults, $marker . '-A');

// Group B: two rows, each with one team_members row, ONE of which is the
// SAME member on both -- tests that the merge de-dupes via team_members'
// own UNIQUE KEY(team_id, member_id) instead of failing or double-counting.
$bId1 = insertTeam($teamsTbl, $teamCols, $legacyDefaults, $marker . '-B');
$bId2 = insertTeam($teamsTbl, $teamCols, $legacyDefaults, $marker . '-B');

$hasTeamMembers = (int) db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$membersTbl]) > 0;

$testMemberIds = [];
if ($hasTeamMembers) {
    // Reuse two real members if any exist; otherwise skip the member-merge
    // assertions but still exercise the plain "keep the busier row" path.
    $memberIds = array_column(db_fetch_all("SELECT id FROM `{$prefix}member` LIMIT 3"), 'id');
    if (count($memberIds) >= 2) {
        $testMemberIds = array_slice($memberIds, 0, 2);
        // Group A: 2 members on the "busy" row only.
        db_query("INSERT IGNORE INTO `{$membersTbl}` (team_id, member_id, role) VALUES (?, ?, 'Member'), (?, ?, 'Member')",
            [$aBusyId, $testMemberIds[0], $aBusyId, $testMemberIds[1]]);
        // Group B: member[0] on BOTH rows (the overlap case), member[1] only on bId2.
        db_query("INSERT IGNORE INTO `{$membersTbl}` (team_id, member_id, role) VALUES (?, ?, 'Member')", [$bId1, $testMemberIds[0]]);
        db_query("INSERT IGNORE INTO `{$membersTbl}` (team_id, member_id, role) VALUES (?, ?, 'Member'), (?, ?, 'Member')",
            [$bId2, $testMemberIds[0], $bId2, $testMemberIds[1]]);
    }
}

// Run the REAL migration as a subprocess.
$php = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
$cmd = escapeshellarg($php) . ' ' . escapeshellarg(realpath('sql/run_phase137_teams_name_unique.php'));
exec($cmd . ' 2>&1', $outputLines, $exitCode);
$output = implode("\n", $outputLines);

ok('migration subprocess exits 0', $exitCode === 0);
ok('migration output reports merging the test duplicate groups',
   strpos($output, $marker . '-A') !== false && strpos($output, $marker . '-B') !== false);

// Group A assertions: exactly one row survives, and it's the busy one.
$groupARows = db_fetch_all("SELECT id FROM `{$teamsTbl}` WHERE `team` = ?", [$marker . '-A']);
ok('group A: exactly one row survives the merge', count($groupARows) === 1);
if ($groupARows) {
    ok('group A: the row WITH members survived, not the empty one', (int) $groupARows[0]['id'] === $aBusyId);
}
ok('group A: the empty loser row is gone', !db_fetch_one("SELECT id FROM `{$teamsTbl}` WHERE id = ?", [$aEmptyId]));

// Group B assertions: exactly one row survives; both members present on
// the keeper with no duplicate (team_id, member_id) rows.
$groupBRows = db_fetch_all("SELECT id FROM `{$teamsTbl}` WHERE `team` = ?", [$marker . '-B']);
ok('group B: exactly one row survives the merge', count($groupBRows) === 1);
if ($groupBRows && $testMemberIds) {
    $keeperId = (int) $groupBRows[0]['id'];
    $keeperMembers = db_fetch_all("SELECT member_id FROM `{$membersTbl}` WHERE team_id = ?", [$keeperId]);
    ok('group B: keeper has exactly 2 distinct members (overlap de-duplicated, not doubled)',
       count($keeperMembers) === 2);
    $keeperMemberIds = array_map('intval', array_column($keeperMembers, 'member_id'));
    sort($keeperMemberIds);
    $expected = array_map('intval', $testMemberIds);
    sort($expected);
    ok('group B: keeper has exactly the expected two members', $keeperMemberIds === $expected);
}

// The UNIQUE KEY must exist after the run, regardless of whether it
// existed before this test started.
$keyNow = (int) db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'uk_teams_team_name'",
    [$teamsTbl]
) > 0;
ok('uk_teams_team_name exists after the migration run', $keyNow);

// A second run must be a clean no-op (idempotency), matching the Phase
// 128 A9b discipline this project holds every migration to.
exec($cmd . ' 2>&1', $secondRunLines, $secondExit);
ok('a second migration run exits 0 (idempotent)', $secondExit === 0);
ok('a second run reports no duplicates found', strpos(implode("\n", $secondRunLines), 'no duplicate team names found') !== false);

// ── With the UNIQUE KEY now real, saving a team with a name that already
//    exists must surface a friendly error, not a raw SQL exception ──────
require_once 'inc/team-write.php';
$collisionName = $marker . '-Collision';
$first = team_upsert_internal(['name' => $collisionName], 0);
ok('first save with a fresh name succeeds', empty($first['errors']) && $first['id'] > 0);

$second = team_upsert_internal(['name' => $collisionName], 0);
ok('a second CREATE with the same name is rejected, not silently duplicated',
   !empty($second['errors']));
ok('the rejection is the friendly duplicate-name message, not a raw SQL exception',
   !empty($second['errors']) && stripos($second['errors'][0], 'already exists') !== false
   && stripos($second['errors'][0], 'SQLSTATE') === false);
ok('the rejected create did not actually insert a second row',
   (int) db_fetch_value("SELECT COUNT(*) FROM `{$teamsTbl}` WHERE `team` = ?", [$collisionName]) === 1);

// Renaming a DIFFERENT existing team to collide with it must be rejected
// the same way.
$other = team_upsert_internal(['name' => $marker . '-RenameMe'], 0);
$renameAttempt = team_upsert_internal(['id' => $other['id'], 'name' => $collisionName], 0);
ok('renaming a team to an already-used name is rejected the same friendly way',
   !empty($renameAttempt['errors']) && stripos($renameAttempt['errors'][0], 'already exists') !== false);
ok('the rejected rename did not change the other team\'s name',
   (int) db_fetch_value("SELECT COUNT(*) FROM `{$teamsTbl}` WHERE `team` = ?", [$marker . '-RenameMe']) === 1);

} catch (Throwable $e) {
    $fail++;
    echo "  FAIL: uncaught exception during the test: " . $e->getMessage() . "\n";
} finally {
    // Marker-prefix cleanup catches every sub-name (A, B, Collision,
    // RenameMe) in one shot regardless of which line the test reached --
    // deliberately not itemized, so it can't silently miss one added later.
    $leftoverIds = array_column(
        db_fetch_all("SELECT id FROM `{$teamsTbl}` WHERE `team` LIKE ?", [$marker . '%']),
        'id'
    );
    foreach ($leftoverIds as $id) {
        if ($hasTeamMembers) db_query("DELETE FROM `{$membersTbl}` WHERE team_id = ?", [$id]);
        db_query("DELETE FROM `{$teamsTbl}` WHERE id = ?", [$id]);
    }
}

printf("\n=== %d passed, %d failed ===\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
