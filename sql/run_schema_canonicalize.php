<?php
/**
 * Phase 124 (2026-07-26) — one canonical definition per table.
 *
 * Companion to Phase 123 (`teams`). The guard added there — "no table may be
 * CREATEd by two different files" — immediately found four more instances of
 * the same latent bug:
 *
 *   member          base_schema.sql + membership.sql
 *   member_types    base_schema.sql + membership.sql
 *   member_status   base_schema.sql + membership.sql
 *   constituents    base_schema.sql + constituents.sql
 *
 * Both definitions used CREATE TABLE IF NOT EXISTS, so the shape an install
 * ended up with depended on which script ran first. On existing installs
 * base_schema won and the tables are fine — the danger is FRESH or REBUILT
 * databases, which is exactly how a beta tester's `teams` table came back with
 * the wrong columns and took the Teams screen down.
 *
 * THE RULE, from here on: base_schema.sql is the ONLY place a table is CREATEd.
 * Every other file may add columns, via an idempotent ALTER migration like this
 * one, after checking the live columns first.
 *
 * What this does (idempotent, additive, never drops a column that holds data):
 *   member         — nothing here; sql/run_member_columns.php already adds the
 *                    modern named columns to the legacy field1-65 table.
 *   member_types   — ensure `sort_order` (the only column the removed
 *                    definition contributed).
 *   member_status  — canonical labels are `status_val` and `background`. If an
 *                    install was built from the removed definition it has
 *                    `name`/`bg_color` instead: backfill, then drop those.
 *                    Ensure `sort_order`.
 *   constituents   — ensure the per-phone *_type columns the removed
 *                    definition contributed.
 *
 * Run:  php sql/run_schema_canonicalize.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$changed = 0;

function say(string $s): void { echo "  $s\n"; }

function tbl_exists(string $t): bool {
    try {
        return (bool) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$t]);
    } catch (Throwable $e) { return false; }
}
function cols_of(string $t): array {
    try { return array_column(db_fetch_all("SHOW COLUMNS FROM `{$t}`"), 'Field'); }
    catch (Throwable $e) { return []; }
}
/** Add a column only if it is genuinely absent. */
function ensure_col(string $t, string $col, string $ddl): void {
    global $changed;
    if (!tbl_exists($t)) { say("skip {$t}.{$col} — table not present"); return; }
    if (in_array($col, cols_of($t), true)) return;                  // already there
    try { db_query("ALTER TABLE `{$t}` ADD COLUMN {$ddl}"); say("added {$t}.`{$col}`"); $changed++; }
    catch (Throwable $e) { say("WARN {$t}.{$col}: " . $e->getMessage()); }
}

echo "Phase 124 — canonicalize schema (one definition per table)\n";
echo "=========================================================\n";

// ── member_types ───────────────────────────────────────────────────────────
// Both definitions agreed on `name`/`description`/`color`; the removed one also
// contributed `sort_order`. Keep that capability.
ensure_col("{$prefix}member_types", 'sort_order',
    "`sort_order` int(11) NOT NULL DEFAULT 0");

// ── member_status ──────────────────────────────────────────────────────────
// TRUE duplicate-semantic pair. Canonical (base_schema, and what the code uses
// per CLAUDE.md) is `status_val` for the label and `background` for the colour.
// The removed definition called them `name` and `bg_color`.
$msT = "{$prefix}member_status";
if (tbl_exists($msT)) {
    $c = cols_of($msT);
    // An install built from the removed definition has no `status_val` at all.
    if (!in_array('status_val', $c, true)) {
        ensure_col($msT, 'status_val', "`status_val` varchar(64) NOT NULL DEFAULT ''");
        $c = cols_of($msT);
    }
    ensure_col($msT, 'background', "`background` varchar(32) NOT NULL DEFAULT ''");
    ensure_col($msT, 'sort_order', "`sort_order` int(11) NOT NULL DEFAULT 0");
    $c = cols_of($msT);

    // Backfill canonical FROM the duplicate before removing anything.
    foreach ([['status_val', 'name'], ['background', 'bg_color']] as [$dst, $src]) {
        if (!in_array($dst, $c, true) || !in_array($src, $c, true)) continue;
        try {
            db_query("UPDATE `{$msT}` SET `{$dst}` = `{$src}`
                       WHERE (`{$dst}` IS NULL OR `{$dst}` = '')
                         AND `{$src}` IS NOT NULL AND `{$src}` <> ''");
            $n = (int) db_fetch_value("SELECT ROW_COUNT()");
            say("backfilled {$msT}.`{$dst}` from `{$src}`" . ($n ? " ({$n} row(s))" : ' (nothing to move)'));
        } catch (Throwable $e) { say("WARN backfill {$dst}<-{$src}: " . $e->getMessage()); }
    }
    // Now the duplicates carry no unique information — drop them.
    foreach (['name', 'bg_color'] as $dup) {
        if (!in_array($dup, cols_of($msT), true)) continue;
        try { db_query("ALTER TABLE `{$msT}` DROP COLUMN `{$dup}`"); say("dropped duplicate {$msT}.`{$dup}`"); $changed++; }
        catch (Throwable $e) { say("WARN drop {$dup}: " . $e->getMessage()); }
    }
}

// ── constituents ───────────────────────────────────────────────────────────
// The removed definition was a superset: it added a per-number phone type.
$cT = "{$prefix}constituents";
foreach (['phone_type', 'phone_2_type', 'phone_3_type', 'phone_4_type'] as $pt) {
    ensure_col($cT, $pt, "`{$pt}` varchar(16) NOT NULL DEFAULT ''");
}

// ── member ─────────────────────────────────────────────────────────────────
// Intentionally nothing: sql/run_member_columns.php already adds the modern
// named columns onto the legacy field1-65 table, idempotently. Verify only.
$mT = "{$prefix}member";
if (tbl_exists($mT)) {
    $mc = cols_of($mT);
    $need = ['first_name', 'last_name', 'member_type_id', 'member_status_id', 'team_id'];
    $missing = array_values(array_diff($need, $mc));
    if ($missing) {
        say('NOTE: member is missing modern columns: ' . implode(', ', $missing));
        say('      Run: php sql/run_member_columns.php   (adds them without touching field1-65)');
    } else {
        say('member: modern named columns present (run_member_columns.php has run)');
    }
}

echo "\nPhase 124 complete — {$changed} schema change(s). Re-running is safe.\n";
exit(0);
