<?php
/**
 * Phase 137 — de-duplicate `teams` rows and add a real UNIQUE constraint on
 * `teams.team`, so `sql/membership.sql`'s "INSERT IGNORE ... so a re-run is
 * safe" claim becomes actually true instead of just asserted.
 *
 * GH: Chris Byrd, Google Group 2026-08-06 — "After the update to 4.2.8 it
 * did fix the teams issue but ... it duplicated all the teams in the list."
 *
 * Root cause: `teams` has a PRIMARY KEY on `id` and NOTHING ELSE — no
 * unique constraint on `team` (the name column). `sql/membership.sql`
 * seeds four starter teams with `INSERT IGNORE INTO teams (team, mission,
 * active) VALUES (...)`, and `tools/install_fresh.php` re-imports that file
 * whenever its tracked content hash changes (by design — see
 * install_fresh.php's own comment: "safe to re-import" because everything
 * in that file "uses CREATE TABLE IF NOT EXISTS + INSERT IGNORE"). That
 * claim holds for tables where INSERT IGNORE actually has a constraint to
 * suppress a violation against. `teams` has none, so a second import
 * doesn't get IGNOREd at all -- it just inserts four more rows with the
 * same names. Confirmed reproduced on this session's own long-lived local
 * dev database (two full sets of the four starter teams, ids 10-13 and
 * 14-17) -- not a fresh-install-only edge case.
 *
 * This migration is defensively GENERIC: it merges ANY team name that has
 * more than one row, not just the four known starter names, because once
 * the UNIQUE KEY is added below, ANY duplicate name (however it got there)
 * would block the ALTER TABLE from ever completing.
 *
 * Merge logic per duplicate-name group:
 *   1. Keep the row with the most `team_members` rows (real usage signal --
 *      don't silently delete the copy someone has actually been using),
 *      tie-broken by lowest `id`.
 *   2. For every OTHER row in the group: copy its team_members to the
 *      keeper via INSERT IGNORE (team_members has its own
 *      UNIQUE KEY (team_id, member_id), so a member already on both
 *      duplicate rows is correctly de-duplicated, not doubled), then
 *      delete that row's team_members and the row itself.
 *   3. Once no duplicate names remain, add UNIQUE KEY on `teams.team`.
 *
 * Idempotent — an install with no duplicates does nothing in step 1-2 and
 * step 3 no-ops if the key already exists. Verifies its own outcome
 * (Phase 128 A9b: a migration that catches its own exception and exits 0
 * is a migration that never ran).
 *
 * Usage: php sql/run_phase137_teams_name_unique.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix     = $GLOBALS['db_prefix'] ?? '';
$teamsTbl   = $prefix . 'teams';
$membersTbl = $prefix . 'team_members';
$fail       = [];

echo "Phase 137 — Teams: de-duplicate + UNIQUE(team)\n";
echo "===============================================\n\n";

// ─────────────────────────────────────────────────────────────────────────
// 1. Find and merge duplicate-name groups
// ─────────────────────────────────────────────────────────────────────────
try {
    $tableExists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$teamsTbl]);
    if ($tableExists === 0) {
        echo "[SKIP] `{$teamsTbl}` does not exist yet — nothing to do\n";
        exit(0);
    }

    $hasTeamMembers = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$membersTbl]) > 0;

    $dupGroups = db_fetch_all(
        "SELECT `team`, COUNT(*) AS cnt
           FROM `{$teamsTbl}`
          GROUP BY `team`
         HAVING COUNT(*) > 1"
    );

    if (!$dupGroups) {
        echo "[OK] no duplicate team names found — nothing to merge\n";
    } else {
        echo "[..] " . count($dupGroups) . " duplicate team name group(s) found\n";
        foreach ($dupGroups as $g) {
            $name = $g['team'];
            $rows = db_fetch_all(
                "SELECT id FROM `{$teamsTbl}` WHERE `team` = ? ORDER BY id",
                [$name]
            );
            $ids = array_column($rows, 'id');

            // Pick the keeper: most team_members rows, tie-break lowest id.
            $keeperId = $ids[0];
            if ($hasTeamMembers) {
                $best = -1;
                foreach ($ids as $id) {
                    $cnt = (int) db_fetch_value(
                        "SELECT COUNT(*) FROM `{$membersTbl}` WHERE team_id = ?", [$id]
                    );
                    if ($cnt > $best) { $best = $cnt; $keeperId = $id; }
                }
            }

            $losers = array_values(array_diff($ids, [$keeperId]));
            echo "     '{$name}': keeping id={$keeperId}, merging " . count($losers)
               . " duplicate(s) (ids " . implode(',', $losers) . ")\n";

            foreach ($losers as $loserId) {
                if ($hasTeamMembers) {
                    // Copy the loser's memberships onto the keeper. INSERT
                    // IGNORE here is CORRECT (not the bug pattern above) --
                    // team_members.UNIQUE KEY(team_id, member_id) is real,
                    // so a member already on both rows is properly
                    // de-duplicated rather than throwing.
                    $cols = array_column(db_fetch_all("SHOW COLUMNS FROM `{$membersTbl}`"), 'Field');
                    $copyCols = array_values(array_diff($cols, ['id', 'team_id']));
                    $colList = implode(',', array_map(fn($c) => "`{$c}`", $copyCols));
                    db_query(
                        "INSERT IGNORE INTO `{$membersTbl}` (`team_id`, {$colList})
                         SELECT ?, {$colList} FROM `{$membersTbl}` WHERE team_id = ?",
                        [$keeperId, $loserId]
                    );
                    db_query("DELETE FROM `{$membersTbl}` WHERE team_id = ?", [$loserId]);
                }
                db_query("DELETE FROM `{$teamsTbl}` WHERE id = ?", [$loserId]);
            }
        }
        echo "[OK] duplicate team names merged\n";
    }
} catch (Exception $e) {
    $fail[] = 'merge: ' . $e->getMessage();
    echo "[FAIL] merge: " . $e->getMessage() . "\n";
}

// ─────────────────────────────────────────────────────────────────────────
// 2. Add the UNIQUE KEY, now that no duplicates remain
// ─────────────────────────────────────────────────────────────────────────
try {
    $idxExists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'uk_teams_team_name'",
        [$teamsTbl]);
    if ($idxExists === 0) {
        db_query("ALTER TABLE `{$teamsTbl}` ADD UNIQUE KEY `uk_teams_team_name` (`team`)");
        echo "[OK] added UNIQUE KEY uk_teams_team_name(team)\n";
    } else {
        echo "[OK] UNIQUE KEY uk_teams_team_name already present\n";
    }
} catch (Exception $e) {
    $fail[] = 'unique key: ' . $e->getMessage();
    echo "[FAIL] unique key: " . $e->getMessage() . "\n";
}

// ─────────────────────────────────────────────────────────────────────────
// 3. Verify the OUTCOME
// ─────────────────────────────────────────────────────────────────────────
try {
    $remainingDups = db_fetch_all(
        "SELECT `team` FROM `{$teamsTbl}` GROUP BY `team` HAVING COUNT(*) > 1"
    );
    if ($remainingDups) {
        $fail[] = 'verify: ' . count($remainingDups) . ' duplicate name group(s) still remain';
    }

    $idxThere = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'uk_teams_team_name'",
        [$teamsTbl]);
    if ($idxThere === 0) $fail[] = 'verify: uk_teams_team_name does not exist';
} catch (Exception $e) {
    $fail[] = 'verify: ' . $e->getMessage();
}

if ($fail) {
    echo "\nFAILED:\n  - " . implode("\n  - ", $fail) . "\n";
    exit(1);
}

echo "\nDone. teams.team is now unique; membership.sql's INSERT IGNORE is genuinely safe to re-run.\n";
exit(0);
