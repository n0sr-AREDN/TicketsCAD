<?php
/**
 * Phase 135 — Assign an Agency (not just a person) as a vehicle's owner.
 *
 * Chris Byrd, Google Group 2026-08-06: "how do I add an Agency so I can
 * assign it as an owner" — there wasn't a way. newui_vehicles already had
 * `is_agency_vehicle` (a boolean) and `org_id` (multi-tenant VISIBILITY
 * scope, unrelated to ownership — see inc/org-scope.php), but nothing that
 * names WHICH agency owns a vehicle. This adds `owner_org_id`, a plain
 * reference into the existing `organizations` table (already used for
 * member-org membership; no new concept introduced).
 *
 * Deliberately a SEPARATE column from `org_id`. Conflating "which org can
 * see this row" with "which org owns this vehicle" would be wrong the
 * first time an install has a mutual-aid loaner: visible to org A, owned
 * by org B. `member_id` (personal owner) is untouched; a vehicle now has
 * three owner states — a person, an agency, or neither — decided by which
 * of `member_id` / `owner_org_id` is set. api/vehicles.php derives
 * `is_agency_vehicle` from `owner_org_id` server-side rather than trusting
 * it stay in sync with client input alone.
 *
 * No FK constraint, matching every other org_id-style reference in this
 * schema (this project uses application-level guards, not FKs, throughout
 * — see the vehicle-owner-integrity fix from the same week for exactly
 * what an unguarded reference costs when the referenced row disappears;
 * api/wastebasket.php's org purge path, if orgs ever become purgeable,
 * will need the same treatment newui_vehicles.member_id just got).
 *
 * Idempotent — safe to re-run. Verifies its own outcome (Phase 128 A9b:
 * a migration step that catches its own exception and exits 0 is a step
 * that never ran).
 *
 * Usage: php sql/run_phase135_vehicle_owner_org.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

$prefix  = $GLOBALS['db_prefix'] ?? '';
$table   = $prefix . 'newui_vehicles';
$fail    = [];

echo "Phase 135 — Vehicle Owner: Agency Assignment\n";
echo "=============================================\n\n";

// ─────────────────────────────────────────────────────────────────────────
// 1. newui_vehicles.owner_org_id + index
// ─────────────────────────────────────────────────────────────────────────
try {
    $tableExists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$table]);
    if ($tableExists === 0) {
        // Fresh install hasn't run sql/run_vehicles.php yet — nothing to
        // alter. Not a failure; that script's CREATE TABLE (once run)
        // already includes owner_org_id (see sql/vehicles.sql).
        echo "[SKIP] `{$table}` does not exist yet — nothing to alter\n";
        exit(0);
    }

    $colExists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'owner_org_id'",
        [$table]);
    if ($colExists === 0) {
        db_query("ALTER TABLE `{$table}` ADD COLUMN `owner_org_id` INT NULL DEFAULT NULL "
                . "COMMENT 'organizations.id — the agency that owns this vehicle, if any (distinct from org_id, the visibility scope)' "
                . "AFTER `member_id`");
        echo "[OK] added {$table}.owner_org_id\n";
    } else {
        echo "[OK] {$table}.owner_org_id already present\n";
    }

    $idxExists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'idx_vehicle_owner_org'",
        [$table]);
    if ($idxExists === 0) {
        db_query("ALTER TABLE `{$table}` ADD INDEX `idx_vehicle_owner_org` (`owner_org_id`)");
        echo "[OK] added index idx_vehicle_owner_org\n";
    } else {
        echo "[OK] index idx_vehicle_owner_org already present\n";
    }
} catch (Exception $e) {
    $fail[] = 'owner_org_id: ' . $e->getMessage();
    echo "[FAIL] owner_org_id: " . $e->getMessage() . "\n";
}

// ─────────────────────────────────────────────────────────────────────────
// 2. Verify the OUTCOME
// ─────────────────────────────────────────────────────────────────────────
try {
    $colThere = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'owner_org_id'",
        [$table]);
    if ($colThere === 0) $fail[] = "verify: {$table}.owner_org_id does not exist";

    $idxThere = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'idx_vehicle_owner_org'",
        [$table]);
    if ($idxThere === 0) $fail[] = "verify: idx_vehicle_owner_org does not exist";
} catch (Exception $e) {
    $fail[] = 'verify: ' . $e->getMessage();
}

if ($fail) {
    echo "\nFAILED:\n  - " . implode("\n  - ", $fail) . "\n";
    exit(1);
}

echo "\nDone. newui_vehicles.owner_org_id installed.\n";
exit(0);
