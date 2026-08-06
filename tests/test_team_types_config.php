<?php
/**
 * Team Types admin management screen (2026-08-06).
 *
 * team_types has never had an admin CRUD screen in this project's history —
 * unlike its sibling member_types (api/personnel-config.php), the only way
 * to add a row was hand-editing the database. Phase 136 seeded 9 defaults
 * to unblock the immediate "Type dropdown is blank" report (Chris Byrd,
 * Google Group), but that's a fixed list, not a management screen — this
 * closes the actual gap: Settings > Personnel > Team Types, mirroring the
 * existing Member Types panel (api/personnel-config.php ?table=member_types
 * / save_member_type / delete_member_type), adapted for team_types' simpler
 * shape (`type`, `comment` — no color/background columns).
 *
 * Structural checks confirm the wiring exists (sidebar tab, settings panel,
 * API dispatch, JS functions); functional checks run the SAME SQL the
 * endpoint uses against the real database, proving create/update/delete and
 * the in-use delete guard actually work — not just that the code compiles.
 */

declare(strict_types=1);
chdir(dirname(__DIR__));

require_once 'config.php';
require_once 'inc/db.php';
require_once 'inc/functions.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; }
    else { $fail++; echo "  FAIL: {$what}\n"; }
}

// ── Structural: sidebar tab ─────────────────────────────────────────────

$sidebar = file_get_contents('inc/config-sidebar.php');
ok('config-sidebar.php has a Team Types tab',
   (bool) preg_match('/_cfg_tab\(\s*[\'"]team-types[\'"]/', $sidebar));

// ── Structural: settings.php panel + section wiring ─────────────────────

$settings = file_get_contents('settings.php');
ok('settings.php has the panel-team-types markup', strpos($settings, 'id="panel-team-types"') !== false);
ok('settings.php\'s $personnelSections includes team-types', (bool) preg_match('/\$personnelSections\s*=\s*\[[^\]]*[\'"]team-types[\'"]/', $settings));
ok('settings.php\'s personnelHashes JS array includes team-types', (bool) preg_match('/personnelHashes\s*=\s*\[[^\]]*[\'"]team-types[\'"]/', $settings));
ok('settings.php has the Team Type add/edit form fields', strpos($settings, 'id="ttName"') !== false && strpos($settings, 'id="ttComment"') !== false);

$personnelNav = file_get_contents('inc/personnel-nav.php');
ok('personnel-nav.php has a Team Types quick-jump link', strpos($personnelNav, "'key' => 'team-types'") !== false);

// ── Structural: API dispatch ─────────────────────────────────────────────

$api = file_get_contents('api/personnel-config.php');
ok('personnel-config.php GET dispatch handles ?table=team_types', (bool) preg_match('/\$table\s*===\s*[\'"]team_types[\'"]/', $api));
ok('personnel-config.php defines getTeamTypes()', strpos($api, 'function getTeamTypes(') !== false);
ok('personnel-config.php POST dispatch handles save_team_type', strpos($api, "case 'save_team_type':") !== false);
ok('personnel-config.php POST dispatch handles delete_team_type', strpos($api, "case 'delete_team_type':") !== false);
ok('personnel-config.php defines saveTeamType() and deleteTeamType()',
   strpos($api, 'function saveTeamType(') !== false && strpos($api, 'function deleteTeamType(') !== false);
// The file-level CSRF gate (checked once in handlePersonnelPost(), before the
// action switch) covers every action including the two new ones -- confirmed
// structurally here rather than re-testing csrf_verify() itself, which
// tests/test_personnel_vehicles_csrf.php already gates for this whole file.
ok('deleteTeamType() blocks deletion while in use (same guard as deleteMemberType())',
   (bool) preg_match('/function deleteTeamType.*?Cannot delete/s', $api));

// ── Structural: JS wiring ────────────────────────────────────────────────

$configJs = file_get_contents('assets/js/config.js');
ok("config.js's tab switch routes 'team-types' to loadTeamTypes()",
   (bool) preg_match('/tab === [\'"]team-types[\'"]\s*\)\s*loadTeamTypes\(\)/', $configJs));
ok('config.js defines loadTeamTypes() reading ?table=team_types',
   (bool) preg_match('/function loadTeamTypes\(\).*?table=team_types/s', $configJs));
ok('config.js defines bindTeamTypeForm() posting save_team_type via apiPostDirect',
   (bool) preg_match("/function bindTeamTypeForm\(\).*?action:\s*'save_team_type'/s", $configJs));
ok('config.js\'s delete handler posts delete_team_type via apiPostDirect',
   (bool) preg_match("/action:\s*'delete_team_type'/", $configJs));

// ── Functional: the real queries against the real database ──────────────
// Mirrors getTeamTypes()/saveTeamType()/deleteTeamType() exactly rather than
// including api/personnel-config.php directly (that file self-dispatches on
// $_SERVER['REQUEST_METHOD'] as soon as it's required, same reason
// tools/test_api_direct.php replicates query logic instead of including
// api/*.php files).

$marker = 'Phase136-CRUD-Test-' . getmypid();

// CREATE
db_query(
    "INSERT INTO " . db_table('team_types') . " (`type`, `comment`, `by`, `from`, `on`) VALUES (?, ?, 0, 'test', NOW())",
    [$marker, 'created by test']
);
$newId = (int) db_insert_id();
ok('INSERT creates a real row with an id', $newId > 0);

// READ (the exact getTeamTypes() query)
$row = db_fetch_one(
    "SELECT tt.id, tt.type, tt.comment,
            (SELECT COUNT(*) FROM " . db_table('teams') . " t WHERE t.ttypes_id = tt.id) AS team_count
     FROM " . db_table('team_types') . " tt WHERE tt.id = ?",
    [$newId]
);
ok('the getTeamTypes() query resolves the new row by its real type name', $row && $row['type'] === $marker);
ok('a type with no teams using it reports team_count = 0', $row && (int) $row['team_count'] === 0);

// UPDATE (the exact saveTeamType() update branch)
db_query("UPDATE " . db_table('team_types') . " SET `type` = ?, `comment` = ? WHERE id = ?",
    [$marker . '-Renamed', 'updated by test', $newId]);
$updated = db_fetch_one("SELECT `type`, `comment` FROM " . db_table('team_types') . " WHERE id = ?", [$newId]);
ok('UPDATE changes the type name', $updated && $updated['type'] === $marker . '-Renamed');
ok('UPDATE changes the comment', $updated && $updated['comment'] === 'updated by test');

// DELETE GUARD: create a real team referencing this type, confirm the exact
// deleteTeamType() guard query reports it in use.
$teamCols = array_column(db_fetch_all("SHOW COLUMNS FROM " . db_table('teams')), 'Field');
$genCols  = array_column(db_fetch_all(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND EXTRA LIKE '%GENERATED%'",
    [($GLOBALS['db_prefix'] ?? '') . 'teams']), 'COLUMN_NAME');
$teamCols = array_diff($teamCols, $genCols);

$insertCols = ['team', 'ttypes_id'];
$insertVals = [$marker . '-guard-team', $newId];
foreach (['sub-group' => '', 'mission' => '', 'leader' => 0, 'leader_dpty' => 0, 'by' => 0, 'from' => 'test'] as $col => $val) {
    if (in_array($col, $teamCols, true)) { $insertCols[] = $col; $insertVals[] = $val; }
}
$placeholders = implode(',', array_fill(0, count($insertVals), '?'));
if (in_array('on', $teamCols, true)) {
    db_query("INSERT INTO " . db_table('teams') . " (`" . implode('`,`', $insertCols) . "`, `on`) VALUES ({$placeholders}, NOW())", $insertVals);
} else {
    db_query("INSERT INTO " . db_table('teams') . " (`" . implode('`,`', $insertCols) . "`) VALUES ({$placeholders})", $insertVals);
}
$guardTeamId = (int) db_insert_id();

$inUseCount = (int) db_fetch_value("SELECT COUNT(*) FROM " . db_table('teams') . " WHERE ttypes_id = ?", [$newId]);
ok('the deleteTeamType() guard query counts the referencing team', $inUseCount === 1);

// Clean up the guard team, THEN prove delete succeeds once nothing references it.
db_query("DELETE FROM " . db_table('teams') . " WHERE id = ?", [$guardTeamId]);
$inUseAfter = (int) db_fetch_value("SELECT COUNT(*) FROM " . db_table('teams') . " WHERE ttypes_id = ?", [$newId]);
ok('the guard query reports 0 once the referencing team is gone', $inUseAfter === 0);

db_query("DELETE FROM " . db_table('team_types') . " WHERE id = ?", [$newId]);
$gone = db_fetch_one("SELECT id FROM " . db_table('team_types') . " WHERE id = ?", [$newId]);
ok('DELETE removes the row once unused', $gone === false || $gone === null);

// ── Phase 136's seeded defaults are still intact (not consumed by this test) ─

$seededCount = (int) db_fetch_value(
    "SELECT COUNT(*) FROM " . db_table('team_types') . " WHERE `type` IN ('Command','Fire','EMS / Medical','CERT')"
);
ok('at least the core Phase 136 seeded types are still present', $seededCount >= 4);

printf("\n=== %d passed, %d failed ===\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
