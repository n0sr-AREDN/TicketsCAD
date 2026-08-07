<?php
/**
 * Team-list duplication diagnostic (GH: Chris Byrd, Google Group 2026-08-06).
 *
 * Read-only. Chris reported that after updating to v4.2.8 (which seeded
 * team_types and fixed the Type dropdown), the Teams list showed every team
 * duplicated. Not reproducible against your-server.example.com or
 * your-server on the same v4.2.8 code -- teams/team_types row counts
 * and the exact api/teams.php list query both come back clean on both. This
 * turns "duplicated" into concrete numbers so the actual mechanism (real
 * duplicate rows vs. a client-side rendering issue vs. something else) can
 * be pinned down without needing shell access to the reporter's database.
 *
 *   php tools/teams_duplicate_diagnose.php
 *
 * Run from the install root (or anywhere -- it chdir's to its parent's parent).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

chdir(dirname(__DIR__));
require_once 'config.php';
require_once 'inc/db.php';
$prefix = $GLOBALS['db_prefix'] ?? '';

function line($s = '') { echo $s . "\n"; }
function ok($s)   { line("  [OK]   $s"); }
function bad($s)  { line("  [FAIL] $s"); }
function warn($s) { line("  [WARN] $s"); }

line("=== TicketsCAD Team-list duplication diagnostic ===");
line();

// ── Stage 1: raw row counts ──
line("Stage 1 — raw table counts");
$teamsCount = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}teams`");
$typesCount = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}team_types`");
line("  teams row count:      {$teamsCount}");
line("  team_types row count: {$typesCount}");
if ($typesCount > 9) {
    warn("team_types has more than the 9 Phase 136 seeded defaults -- if these look like");
    warn("duplicate TYPE names (not teams themselves), the seed migration may have run");
    warn("more than once. Listing them below.");
}
line();

// ── Stage 2: literal duplicate rows (same name, multiple ids) ──
line("Stage 2 — duplicate NAMES within teams");
$dupTeams = db_fetch_all(
    "SELECT `team`, COUNT(*) AS cnt, GROUP_CONCAT(id ORDER BY id) AS ids
     FROM `{$prefix}teams` GROUP BY `team` HAVING COUNT(*) > 1"
);
if ($dupTeams) {
    bad(count($dupTeams) . " team name(s) have more than one row -- this IS real duplication in the teams table:");
    foreach ($dupTeams as $d) {
        line("         '{$d['team']}' -> " . $d['cnt'] . " rows, ids: " . $d['ids']);
    }
} else {
    ok("no team name appears on more than one row -- the teams table itself has no duplicate rows");
}
line();

$dupTypes = db_fetch_all(
    "SELECT `type`, COUNT(*) AS cnt, GROUP_CONCAT(id ORDER BY id) AS ids
     FROM `{$prefix}team_types` GROUP BY `type` HAVING COUNT(*) > 1"
);
if ($dupTypes) {
    bad(count($dupTypes) . " team TYPE name(s) have more than one row (the Phase 136 seed re-ran):");
    foreach ($dupTypes as $d) {
        line("         '{$d['type']}' -> " . $d['cnt'] . " rows, ids: " . $d['ids']);
    }
} else {
    ok("no team_types name appears on more than one row");
}
line();

// ── Stage 3: the same LEFT JOIN api/teams.php's list endpoint runs ──
//
// Deliberately WITHOUT org_query_filter()'s WHERE fragment -- that filters
// by the CALLER's session/org visibility, which has nothing to do with
// duplication and would report a false "fewer rows" result every time this
// runs from the CLI (no session = no visible orgs = every row filtered
// out). This stage exists to catch JOIN-based multiplication specifically.
line("Stage 3 — the teams<->team_types LEFT JOIN, run directly (org-scope filtering excluded --");
line("           that's a separate, unrelated visibility concern, not a duplication one)");
$apiRows = db_fetch_all(
    "SELECT t.id, t.`team` AS name, tt.type AS type_name
     FROM `{$prefix}teams` t
     LEFT JOIN `{$prefix}team_types` tt ON t.ttypes_id = tt.id
     ORDER BY t.`team`"
);
line("  join query returned " . count($apiRows) . " row(s) (raw teams table has {$teamsCount})");
if (count($apiRows) > $teamsCount) {
    bad("the join returns MORE rows than the raw table -- the LEFT JOIN to team_types is");
    bad("multiplying rows for at least one team. This would mean a ttypes_id value matches more");
    bad("than one team_types row, which should be impossible since team_types.id is a primary key.");
} elseif (count($apiRows) < $teamsCount) {
    bad("the join returns FEWER rows than the raw table -- unexpected for a LEFT JOIN; something");
    bad("other than what this script checks is filtering rows out.");
} else {
    ok("join query row count matches the raw table exactly -- no duplication from this join");
}
line();

// ── Stage 4: migration tracker -- did the team_types seed run more than once? ──
line("Stage 4 — migration history for the team_types seed");
try {
    $migRows = db_fetch_all(
        "SELECT script_name, applied_at FROM `{$prefix}_migrations`
          WHERE script_name LIKE '%team_types%' OR script_name LIKE '%teams_name_unique%'
          ORDER BY applied_at"
    );
    if (!$migRows) {
        warn("no _migrations row found for the team_types seed or the teams-dedupe fix -- one or");
        warn("both may not have run yet on this install.");
    } else {
        foreach ($migRows as $m) {
            ok("{$m['script_name']} recorded as applied: {$m['applied_at']}");
        }
        // A dedicated migration script only ever gets ONE tracker row per
        // (script_name, script_hash) pair by construction (UNIQUE KEY on
        // the tracker itself) -- if content never changed, seeing more than
        // one row here would mean the file's hash changed between runs,
        // which is normal across an upgrade and not itself a problem.
    }
} catch (Exception $e) {
    warn("could not read _migrations table: " . $e->getMessage());
}
line();

line("=== Summary ===");
line("Send this whole output back -- the combination of stage 2 (real duplicate rows or");
line("not) and stage 3 (query-level duplication or not) will show exactly where the");
line("'duplicated' teams are actually coming from: the database, the query, or the page.");
line("If BOTH stages come back clean, the duplication is most likely happening in the");
line("browser (a stale cached teams.js served alongside the new API response) -- in that");
line("case, a hard refresh (Ctrl+Shift+R / Cmd+Shift+R) to bypass the cache is the next");
line("thing to try before we look further.");
