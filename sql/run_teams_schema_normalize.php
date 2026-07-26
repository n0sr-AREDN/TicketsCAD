<?php
/**
 * Phase 123 (2026-07-25) — normalize the `teams` table onto ONE schema.
 *
 * WHAT WENT WRONG. `teams` had two competing CREATE TABLE definitions:
 *
 *   sql/base_schema.sql  (canonical, legacy, what the v3.44 app and every real
 *                        install use):  team, sub-group, ttypes_id, mission,
 *                        leader, leader_dpty, formed, by, from, on
 *   sql/membership.sql   (invented later without inspecting the real table):
 *                        name, description, team_type, leader_id, deputy_id, active
 *
 * Both used CREATE TABLE IF NOT EXISTS, so which schema you got depended on
 * script execution order. Worse, on installs where the invented columns were
 * ALTER'd onto the real table, BOTH existed and different code paths wrote to
 * different halves — producing teams with a `team_type` but a blank `team`
 * name (observed on a live install: 4 named teams, 4 nameless ones).
 *
 * CANONICAL, decided 2026-07-25: the legacy columns win, for every field that
 * has a legacy equivalent. They hold the real data, the legacy app uses them,
 * and the v3.44 → v4 upgrade path depends on them.
 *
 *   team        <- name            (the team's name)
 *   mission     <- description
 *   ttypes_id   <- team_type       (int FK vs free text; see note below)
 *   leader      <- leader_id
 *   leader_dpty <- deputy_id
 *
 * `active` is KEPT: it has no legacy twin, so it is a genuine addition rather
 * than a duplicate, and code legitimately filters on it.
 *
 * This migration is idempotent and non-destructive to DATA: it backfills the
 * canonical column from its duplicate before dropping the duplicate, and it
 * never deletes rows. Rows that end up with an empty name are REPORTED, not
 * removed — a human should decide what those are.
 *
 * Run:  php sql/run_teams_schema_normalize.php
 */

declare(strict_types=1);
require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$T = "`{$prefix}teams`";

function say(string $s): void { echo "  $s\n"; }
echo "Phase 123 — normalize `teams` onto the canonical schema\n";
echo "======================================================\n";

// ── 0. Table must exist ────────────────────────────────────────────────────
try {
    $exists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", ["{$prefix}teams"]);
} catch (Throwable $e) { $exists = 0; }
if (!$exists) {
    say('teams table not present — nothing to normalize (install_fresh will create it).');
    exit(0);
}

$cols = [];
foreach (db_fetch_all("SHOW COLUMNS FROM {$T}") as $c) { $cols[$c['Field']] = $c; }
$has = static fn(string $c): bool => isset($cols[$c]);

// ── 1. Ensure the canonical columns exist ──────────────────────────────────
// An install created solely from the invented definition has none of these.
$canonical = [
    'team'        => "ADD COLUMN `team` varchar(48) NOT NULL DEFAULT '' COMMENT 'team name'",
    'sub-group'   => "ADD COLUMN `sub-group` varchar(48) NOT NULL DEFAULT ''",
    'ttypes_id'   => "ADD COLUMN `ttypes_id` int(7) NOT NULL DEFAULT 0",
    'mission'     => "ADD COLUMN `mission` varchar(48) NOT NULL DEFAULT ''",
    'leader'      => "ADD COLUMN `leader` int(4) NOT NULL DEFAULT 0",
    'leader_dpty' => "ADD COLUMN `leader_dpty` int(4) NOT NULL DEFAULT 0",
    'formed'      => "ADD COLUMN `formed` date DEFAULT NULL",
];
foreach ($canonical as $col => $ddl) {
    if ($has($col)) { continue; }
    try { db_query("ALTER TABLE {$T} {$ddl}"); say("added missing canonical column `{$col}`"); $cols[$col] = true; }
    catch (Throwable $e) { say("WARN could not add `{$col}`: " . $e->getMessage()); }
}
// `active` is additive (no legacy twin) — guarantee it so `WHERE active=1` works.
if (!$has('active')) {
    try { db_query("ALTER TABLE {$T} ADD COLUMN `active` tinyint(1) NOT NULL DEFAULT 1"); say('added `active`'); }
    catch (Throwable $e) { say('WARN could not add `active`: ' . $e->getMessage()); }
}

// refresh
$cols = [];
foreach (db_fetch_all("SHOW COLUMNS FROM {$T}") as $c) { $cols[$c['Field']] = $c; }
$has = static fn(string $c): bool => isset($cols[$c]);

// ── 2. Backfill canonical FROM the duplicate, before dropping anything ─────
// Only fills where the canonical value is empty, so real data always wins.
$backfill = [
    ['team',        'name',        "string"],
    ['mission',     'description', "string"],
    ['leader',      'leader_id',   "int"],
    ['leader_dpty', 'deputy_id',   "int"],
];
foreach ($backfill as [$dst, $src, $kind]) {
    if (!$has($dst) || !$has($src)) continue;
    try {
        $sql = $kind === 'int'
            ? "UPDATE {$T} SET `{$dst}` = `{$src}`
                WHERE (`{$dst}` IS NULL OR `{$dst}` = 0) AND `{$src}` IS NOT NULL AND `{$src}` <> 0"
            : "UPDATE {$T} SET `{$dst}` = `{$src}`
                WHERE (`{$dst}` IS NULL OR `{$dst}` = '') AND `{$src}` IS NOT NULL AND `{$src}` <> ''";
        db_query($sql);
        $n = (int) db_fetch_value("SELECT ROW_COUNT()");
        say("backfilled `{$dst}` from `{$src}`" . ($n > 0 ? " ({$n} row(s))" : ' (nothing to move)'));
    } catch (Throwable $e) { say("WARN backfill {$dst}<-{$src}: " . $e->getMessage()); }
}

// team_type (free text) -> ttypes_id (FK) cannot be converted mechanically.
// Report it rather than guess: silently inventing FK ids is how we got here.
if ($has('team_type') && $has('ttypes_id')) {
    try {
        $orphans = db_fetch_all(
            "SELECT DISTINCT `team_type` FROM {$T}
              WHERE `team_type` IS NOT NULL AND `team_type` <> '' AND (`ttypes_id` IS NULL OR `ttypes_id` = 0)");
        if ($orphans) {
            say('NOTE: these free-text team types have no numeric team-type id and were NOT guessed:');
            foreach ($orphans as $o) say('        - ' . $o['team_type']);
            say('      Set each team\'s type from the Teams screen after this runs.');
        }
    } catch (Throwable $e) { /* non-fatal */ }
}

// ── 3. Report rows that have no name at all (do NOT delete them) ───────────
try {
    $blank = (int) db_fetch_value("SELECT COUNT(*) FROM {$T} WHERE `team` IS NULL OR `team` = ''");
    if ($blank > 0) {
        say("NOTE: {$blank} team row(s) have a blank name — created by the old dual-write paths.");
        say('      They are kept, not deleted. Name or remove them from the Teams screen.');
    }
} catch (Throwable $e) { /* non-fatal */ }

// ── 3b. Ensure EVERY column the writer needs exists ────────────────────────
// inc/team-write.php INSERTs the full set below. A table rebuilt from a partial
// hand-written definition satisfies the read path but fails the INSERT with a
// 400 — which is exactly what happened to a beta tester on 2026-07-25. Adding a
// column is safe; the point is that the table must be able to accept a save.
$required = [
    'by'                 => "ADD COLUMN `by` int(7) NOT NULL DEFAULT 0",
    'from'               => "ADD COLUMN `from` varchar(16) NOT NULL DEFAULT ''",
    'on'                 => "ADD COLUMN `on` datetime DEFAULT NULL",
    'created_at'         => "ADD COLUMN `created_at` datetime DEFAULT NULL",
    'updated_at'         => "ADD COLUMN `updated_at` datetime DEFAULT NULL",
    'nims_resource_type' => "ADD COLUMN `nims_resource_type` varchar(64) NOT NULL DEFAULT ''",
    'nims_typing_level'  => "ADD COLUMN `nims_typing_level` varchar(16) NOT NULL DEFAULT ''",
    'rtlt_code'          => "ADD COLUMN `rtlt_code` varchar(32) NOT NULL DEFAULT ''",
];
foreach ($required as $col => $ddl) {
    if ($has($col)) continue;
    try { db_query("ALTER TABLE {$T} {$ddl}"); say("added required column `{$col}` (needed to SAVE a team)"); $cols[$col] = true; }
    catch (Throwable $e) { say("WARN could not add `{$col}`: " . $e->getMessage()); }
}
$cols = [];
foreach (db_fetch_all("SHOW COLUMNS FROM {$T}") as $c) { $cols[$c['Field']] = $c; }
$has = static fn(string $c): bool => isset($cols[$c]);

// ── 4. Drop the STORED duplicate columns ───────────────────────────────────
// A GENERATED column (e.g. `name` AS (`team`) VIRTUAL, added by
// seed_scheduling_data.php) is NOT a duplicate — it is a read-only alias that
// is always in sync by definition, and it is how older `t.name` code keeps
// working. Dropping it would also fight that script forever, each re-adding
// what the other removed. Only drop columns that store independent data.
foreach (['name', 'description', 'team_type', 'leader_id', 'deputy_id'] as $dup) {
    if (!$has($dup)) continue;
    $extra = strtoupper((string) ($cols[$dup]['Extra'] ?? ''));
    if (strpos($extra, 'GENERATED') !== false) {
        say("kept `{$dup}` — it is a GENERATED alias, always in sync (not a duplicate)");
        continue;
    }
    try { db_query("ALTER TABLE {$T} DROP COLUMN `{$dup}`"); say("dropped duplicate column `{$dup}`"); }
    catch (Throwable $e) { say("WARN could not drop `{$dup}`: " . $e->getMessage()); }
}

// ── 5. Report the final shape ──────────────────────────────────────────────
$final = [];
foreach (db_fetch_all("SHOW COLUMNS FROM {$T}") as $c) { $final[] = $c['Field']; }
echo "\n  Canonical `teams` columns now: " . implode(', ', $final) . "\n";
echo "\nPhase 123 complete. Re-running is safe.\n";
exit(0);
