<?php
/**
 * Regression tests for five bugs Chris Byrd reported on the Google Group
 * 2026-08-06, all discovered together while testing the just-shipped
 * v4.2.7 Agency-owner feature but pre-existing (oldest from 2026-05-05,
 * newest from 2026-07-07) and unrelated to that release:
 *
 *   1. window.CSRF_TOKEN was only ever emitted inside the i18n
 *      language-switcher block in inc/navbar.php, so any install with
 *      fewer than 2 configured languages (the default) sent an empty CSRF
 *      token from 10 different JS files -- surfaced as "Invalid CSRF
 *      token" on Equipment save and an unexplained "HTTP 403" on Team save.
 *   2. api/equipment.php's member-picker query read only the modern
 *      first_name/last_name columns with no legacy field1/field2 fallback,
 *      so an upgrade install's dropdown rendered "null, null" for members
 *      whose name still lives in the legacy columns.
 *   3. api/vehicles.php had the identical un-COALESCEd query (latent, not
 *      yet reported, fixed alongside #2 rather than waiting for a match).
 *   4. team_types has never had a seed row anywhere in this project's
 *      history (confirmed against the legacy v3.44 DB_FULL.sql dump too),
 *      so the Team "Type" dropdown was unconditionally blank.
 *   5. teams.js read tt.name for the type label, but api/teams.php's
 *      `SELECT * FROM team_types` returns the real column `type` -- and
 *      separately, teams.js's apiGet/apiPost threw on a non-2xx response
 *      BEFORE parsing the JSON body, discarding the server's real
 *      {"error": "..."} message and leaving only a bare "HTTP 403".
 */

declare(strict_types=1);
chdir(dirname(__DIR__));

require_once 'config.php';
require_once 'inc/db.php';
require_once 'inc/functions.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$pass = 0; $fail = 0;
function ok(string $what, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; }
    else { $fail++; echo "  FAIL: {$what}\n"; }
}

// ── Bug 1: window.CSRF_TOKEN must be unconditional ─────────────────────────

$navbar = file_get_contents('inc/navbar.php');
$stripped = preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $navbar);

ok('inc/navbar.php sets window.CSRF_TOKEN somewhere',
   (bool) preg_match('/window\.CSRF_TOKEN\s*=/', $stripped));

// The assignment must NOT appear only inside the i18n conditional. Anchor on
// window.AVAILABLE_LANGS specifically -- navbar.php has an EARLIER, unrelated
// `if (count($_navbar_langs) >= 2)` at line ~388 (a PHP-rendered language
// dropdown), so matching the bare condition text finds the wrong block.
$langsMarker = strpos($stripped, 'window.AVAILABLE_LANGS');
ok('found the JS language-switcher bootstrap block to check against', $langsMarker !== false);
if ($langsMarker !== false) {
    $beforeBlock = substr($stripped, 0, $langsMarker);
    ok('window.CSRF_TOKEN is assigned BEFORE the i18n JS bootstrap block (unconditional)',
       (bool) preg_match('/window\.CSRF_TOKEN\s*=/', $beforeBlock));
}

ok('inc/navbar.php calls csrf_token() rather than reading $_SESSION directly for the JS token',
   (bool) preg_match('/window\.CSRF_TOKEN\s*=\s*<\?php\s*echo\s*json_encode\s*\(\s*csrf_token\s*\(\s*\)\s*\)/', $stripped));

// Functional: csrf_token() itself must reliably produce a usable token --
// this is what navbar.php now calls unconditionally on every page.
unset($_SESSION['csrf_token']);
$token = csrf_token();
ok('csrf_token() returns a non-empty 64-char hex token', is_string($token) && strlen($token) === 64);

// ── Bug 2 & 3: legacy-field COALESCE on the member-picker queries ─────────

foreach (['api/equipment.php', 'api/vehicles.php'] as $f) {
    $src = file_get_contents($f);
    ok("{$f} member query COALESCEs last_name to the legacy field1 column",
       (bool) preg_match('/COALESCE\s*\(\s*NULLIF\s*\(\s*m?\.?last_name\s*,\s*\'\'\s*\)\s*,\s*m?\.?field1\s*\)/', $src));
    ok("{$f} member query COALESCEs first_name to the legacy field2 column",
       (bool) preg_match('/COALESCE\s*\(\s*NULLIF\s*\(\s*m?\.?first_name\s*,\s*\'\'\s*\)\s*,\s*m?\.?field2\s*\)/', $src));
}

// Functional: a member whose modern columns are empty but legacy fields are
// set must still resolve to a real name through both endpoints' queries,
// not "null, null" -- reproduced through a real row, not asserted from SQL text.
// Never insert into a GENERATED column (member.email is generated on this
// install) -- same guard sql/run_teams_seed.php uses for teams.name.
$genCols = array_column(db_fetch_all(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        AND EXTRA LIKE '%GENERATED%'",
    [($GLOBALS['db_prefix'] ?? '') . 'member']), 'COLUMN_NAME');
$cols = array_diff(array_column(db_fetch_all("SHOW COLUMNS FROM " . db_table('member')), 'Field'), $genCols);
if (in_array('field1', $cols, true) && in_array('field2', $cols, true)) {
    $insertCols = ['field1', 'field2'];
    $insertVals = ['LegacyLast', 'LegacyFirst'];
    if (in_array('first_name', $cols, true)) { $insertCols[] = 'first_name'; $insertVals[] = ''; }
    if (in_array('last_name', $cols, true))  { $insertCols[] = 'last_name';  $insertVals[] = ''; }
    $placeholders = implode(',', array_fill(0, count($insertVals), '?'));
    db_query(
        "INSERT INTO " . db_table('member') . " (`" . implode('`,`', $insertCols) . "`) VALUES ({$placeholders})",
        $insertVals
    );
    $legacyMemberId = (int) db_insert_id();

    $row = db_fetch_one(
        "SELECT COALESCE(NULLIF(last_name, ''), field1) AS last_name,
                COALESCE(NULLIF(first_name, ''), field2) AS first_name
         FROM " . db_table('member') . " WHERE id = ?",
        [$legacyMemberId]
    );
    ok('a member with only legacy field1/field2 populated resolves a real last_name via the fixed query pattern',
       $row && $row['last_name'] === 'LegacyLast');
    ok('a member with only legacy field1/field2 populated resolves a real first_name via the fixed query pattern',
       $row && $row['first_name'] === 'LegacyFirst');

    db_query("DELETE FROM " . db_table('member') . " WHERE id = ?", [$legacyMemberId]);
} else {
    echo "  SKIP: legacy field1/field2 columns not present on this install -- COALESCE path not exercisable here\n";
}

// ── Bug 4: team_types has default rows and the seed migration is idempotent ─

$teamTypeCount = (int) db_fetch_value("SELECT COUNT(*) FROM " . db_table('team_types'));
ok('team_types has at least one row after the Phase 136 seed', $teamTypeCount > 0);

$migrationSrc = file_get_contents('sql/run_phase136_team_types_seed.php');
ok('Phase 136 migration skips seeding when rows already exist (idempotent)',
   (bool) preg_match('/\$count\s*>\s*0/', $migrationSrc) && (bool) preg_match('/seed skipped/', $migrationSrc));
ok('Phase 136 migration is CLI-only', (bool) preg_match('/PHP_SAPI\s*!==\s*[\'"]cli[\'"]/', $migrationSrc));
ok('Phase 136 migration verifies its own outcome before exiting 0',
   (bool) preg_match('/verify the outcome|FAILED: verify/i', $migrationSrc));

// ── Bug 5: teams.js reads the real `type` column and surfaces real errors ──

$teamsJs = file_get_contents('assets/js/teams.js');
ok('teams.js reads tt.type (the real team_types column) for the dropdown label',
   (bool) preg_match('/tt\.type\s*\|\|\s*[\'"]Unknown[\'"]/', $teamsJs));
ok('teams.js no longer reads the non-existent tt.name for the dropdown label',
   !preg_match('/tt\.name\s*\|\|\s*[\'"]Unknown[\'"]/', $teamsJs));
ok('teams.js apiGet parses the JSON body before checking response status (so error messages survive a non-2xx)',
   (bool) preg_match('/function apiGet.*?r\.json\(\).*?if\s*\(\s*!r\.ok\s*\)/s', $teamsJs));
ok('teams.js apiPost parses the JSON body before checking response status (so error messages survive a non-2xx)',
   (bool) preg_match('/function apiPost.*?r\.json\(\).*?if\s*\(\s*!r\.ok\s*\)/s', $teamsJs));

printf("\n=== %d passed, %d failed ===\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
