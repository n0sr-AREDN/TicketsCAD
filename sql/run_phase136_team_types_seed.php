<?php
/**
 * Phase 136 — Seed `team_types`, which has been empty since v3.44 and has
 * no admin UI to populate it (unlike its sibling `member_types`, which
 * api/personnel-config.php manages). Chris Byrd, Google Group 2026-08-06:
 * "When creating a new team the Type pull down is blank" — confirmed the
 * table has genuinely never had a seed row anywhere in this project's
 * history, including the legacy v3.44 DB_FULL.sql dump.
 *
 * This unblocks the immediate report (an admin needs SOME type to pick).
 * It does not add a management UI for team_types the way member_types has
 * one — that's a real, separate gap, tracked in specs/handoff.md rather
 * than folded into this fix.
 *
 * Legacy NOT-NULL columns without defaults (`comment`, `by`, `from`, `on`)
 * follow the same fill convention as sql/run_teams_seed.php's starter rows.
 *
 * Idempotent — seeds ONLY when the table has zero rows, so an admin who
 * has already added their own types (however they did it) is untouched.
 *
 * Usage: php sql/run_phase136_team_types_seed.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$table  = $prefix . 'team_types';

echo "Phase 136 — Team Types: default seed\n";
echo "=====================================\n\n";

try {
    $tableExists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$table]);
    if ($tableExists === 0) {
        echo "[SKIP] `{$table}` does not exist yet\n";
        exit(0);
    }

    $count = (int) db_fetch_value("SELECT COUNT(*) FROM `{$table}`");
    if ($count > 0) {
        echo "[OK] {$table} already has {$count} row(s) — seed skipped\n";
        exit(0);
    }

    $defaultTypes = [
        ['Command',              'Incident command / general staff'],
        ['Fire',                 'Structural and wildland fire response'],
        ['EMS / Medical',        'Emergency medical services'],
        ['Search & Rescue',      'Ground, water, or wilderness search and rescue'],
        ['Law Enforcement',      'Police, sheriff, campus security'],
        ['Communications',       'RACES/ARES, dispatch, and radio operations'],
        ['CERT',                 'Community Emergency Response Team'],
        ['Logistics',            'Supply, equipment, and resource support'],
        ['General',              'Not otherwise categorized'],
    ];

    $seeded = 0;
    foreach ($defaultTypes as [$type, $comment]) {
        db_query(
            "INSERT INTO `{$table}` (`type`, `comment`, `by`, `from`, `on`) VALUES (?, ?, 0, 'install', NOW())",
            [$type, $comment]
        );
        $seeded++;
    }
    echo "[OK] seeded {$seeded} default team types\n";
} catch (Exception $e) {
    echo "[FAIL] team_types seed: " . $e->getMessage() . "\n";
    exit(1);
}

// Verify the outcome — Phase 128 A9b: a step that catches its own
// exception and exits 0 is a step that never ran.
try {
    $finalCount = (int) db_fetch_value("SELECT COUNT(*) FROM `{$table}`");
    if ($finalCount === 0) {
        echo "\nFAILED: verify: {$table} still has 0 rows\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "\nFAILED: verify: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nDone. {$table} has rows.\n";
exit(0);
