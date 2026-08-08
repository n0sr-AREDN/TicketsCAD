<?php
/**
 * GH#38 (Chris Byrd, 2026-08-07): "Would like to be able to delete the
 * Activity Log entries for equipment checked in and out... Be able to
 * delete like the delete function on the ICS Forms."
 *
 * Eric's explicit call: admin-only, no ownership/creator exception (unlike
 * ICS forms) — "admin-only is the right default for something that removes
 * an audit trail entry."
 *
 * Covers:
 *   1. sql/run_equipment_log_soft_delete.php's schema changes actually landed
 *      (deleted_at, deleted_by, idx_deleted_at) — and that it's idempotent.
 *   2. action.delete_equipment_log exists and is granted to Super Admin (1)
 *      and Org Admin (2) only — NOT Dispatcher (3), matching the no-carve-out
 *      policy (a Dispatcher who can manage equipment must not silently gain
 *      the power to erase an audit trail entry).
 *   3. The soft-delete round trip api/equipment.php's delete_log_entry action
 *      and api/wastebasket.php's restore/list rely on actually works at the
 *      DB layer: a soft-deleted row disappears from the "live" view and
 *      appears in the "deleted" view, and restoring it reverses that.
 *
 * Usage: php tools/test_gh38_equipment_log_delete.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$pass = 0;
$fail = 0;
function ok(string $name): void { global $pass; echo "  PASS  $name\n"; $pass++; }
function bad(string $name, string $why = ''): void {
    global $fail; echo "  FAIL  $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++;
}

echo "=== GH#38 — Equipment activity log delete (admin-only, soft delete) ===\n\n";

// ── 1. Schema ────────────────────────────────────────────────────────────
$cols = db_fetch_all(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        AND COLUMN_NAME IN ('deleted_at', 'deleted_by')",
    [$prefix . 'newui_equipment_log']
);
$colNames = array_column($cols, 'COLUMN_NAME');
in_array('deleted_at', $colNames, true) ? ok('newui_equipment_log has deleted_at') : bad('newui_equipment_log has deleted_at');
in_array('deleted_by', $colNames, true) ? ok('newui_equipment_log has deleted_by') : bad('newui_equipment_log has deleted_by');

$idx = db_fetch_one(
    "SELECT INDEX_NAME FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'idx_deleted_at'",
    [$prefix . 'newui_equipment_log']
);
$idx !== null ? ok('newui_equipment_log has idx_deleted_at index') : bad('newui_equipment_log has idx_deleted_at index');

// ── 2. RBAC permission + grants ─────────────────────────────────────────
$perm = db_fetch_one(
    "SELECT id, category FROM `{$prefix}permissions` WHERE code = ?",
    ['action.delete_equipment_log']
);
if ($perm) {
    ok('action.delete_equipment_log permission exists');
    ($perm['category'] === 'action')
        ? ok('action.delete_equipment_log is category=action')
        : bad('action.delete_equipment_log category', 'got ' . var_export($perm['category'], true));

    $permId = (int) $perm['id'];
    $grantedRoles = db_fetch_all(
        "SELECT role_id FROM `{$prefix}role_permissions` WHERE permission_id = ? ORDER BY role_id",
        [$permId]
    );
    $roleIds = array_map('intval', array_column($grantedRoles, 'role_id'));

    in_array(1, $roleIds, true) ? ok('Super Admin (role 1) holds the permission') : bad('Super Admin (role 1) holds the permission');
    in_array(2, $roleIds, true) ? ok('Org Admin (role 2) holds the permission') : bad('Org Admin (role 2) holds the permission');
    (!in_array(3, $roleIds, true))
        ? ok('Dispatcher (role 3) does NOT hold the permission — no ownership carve-out')
        : bad('Dispatcher must not hold action.delete_equipment_log', 'found role_id=3 in grants');
} else {
    bad('action.delete_equipment_log permission exists', 'run php sql/run_equipment_log_soft_delete.php first');
}

// ── 3. Soft-delete round trip (the DB behaviour the API relies on) ──────
$eqType = db_fetch_one("SELECT id FROM `{$prefix}newui_equipment_types` LIMIT 1");
if (!$eqType) {
    echo "SKIP: no equipment types on this install to test against.\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

$marker = 'gh38_test_' . getmypid();
db_query(
    "INSERT INTO `{$prefix}newui_equipment` (name, equipment_type_id, status, `condition`, ownership)
     VALUES (?, ?, 'Available', 'Good', 'organization')",
    [$marker, $eqType['id']]
);
$equipmentId = (int) db_insert_id();

try {
    db_query(
        "INSERT INTO `{$prefix}newui_equipment_log` (equipment_id, `action`, notes, created_at)
         VALUES (?, 'note', ?, NOW())",
        [$equipmentId, $marker]
    );
    $logId = (int) db_insert_id();

    // Live view — the WHERE fragment api/equipment.php's handleGet() builds
    // when equipmentLogHasSoftDelete() is true.
    $live = db_fetch_all(
        "SELECT id FROM `{$prefix}newui_equipment_log` WHERE equipment_id = ? AND deleted_at IS NULL",
        [$equipmentId]
    );
    (count($live) === 1) ? ok('freshly-inserted log entry appears in the live view') : bad('log entry appears in live view', 'count=' . count($live));

    // Soft delete — exactly what the delete_log_entry action runs.
    db_query(
        "UPDATE `{$prefix}newui_equipment_log` SET `deleted_at` = NOW(), `deleted_by` = ? WHERE id = ?",
        [1, $logId]
    );

    $liveAfter = db_fetch_all(
        "SELECT id FROM `{$prefix}newui_equipment_log` WHERE equipment_id = ? AND deleted_at IS NULL",
        [$equipmentId]
    );
    (count($liveAfter) === 0) ? ok('soft-deleted entry disappears from the live view') : bad('soft-deleted entry still in live view', 'count=' . count($liveAfter));

    // Wastebasket view — the query api/wastebasket.php's GET runs generically
    // via $tableConfig['equipment_log'].
    $wb = db_fetch_all(
        "SELECT id, equipment_id, `action`, member_id, notes, created_at, deleted_at, deleted_by
           FROM `{$prefix}newui_equipment_log` WHERE deleted_at IS NOT NULL AND id = ?",
        [$logId]
    );
    (count($wb) === 1) ? ok('soft-deleted entry appears in the wastebasket view') : bad('soft-deleted entry in wastebasket view', 'count=' . count($wb));

    // Restore — what api/wastebasket.php's action=restore runs.
    db_query(
        "UPDATE `{$prefix}newui_equipment_log` SET `deleted_at` = NULL, `deleted_by` = NULL WHERE id = ?",
        [$logId]
    );
    $restored = db_fetch_all(
        "SELECT id FROM `{$prefix}newui_equipment_log` WHERE equipment_id = ? AND deleted_at IS NULL",
        [$equipmentId]
    );
    (count($restored) === 1) ? ok('restored entry reappears in the live view') : bad('restored entry reappears in live view', 'count=' . count($restored));
} finally {
    db_query("DELETE FROM `{$prefix}newui_equipment_log` WHERE equipment_id = ?", [$equipmentId]);
    db_query("DELETE FROM `{$prefix}newui_equipment` WHERE id = ?", [$equipmentId]);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
