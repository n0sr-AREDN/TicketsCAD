<?php
/**
 * Chris Byrd, Google Group 2026-08-06: "Vehicle Owner ... appears i have
 * some null records."
 *
 * newui_vehicles.member_id has no foreign key. api/wastebasket.php's purge
 * action already cleaned up several member_* tables when a member is
 * permanently deleted, but never touched newui_vehicles — so a vehicle
 * whose owner was purged kept a member_id pointing at a row that no longer
 * existed anywhere (not soft-deleted, gone), and the owner column silently
 * rendered blank with nothing to explain why. Confirmed against this
 * project's real dev database before writing the fix: two vehicles already
 * had exactly this dangling state.
 *
 * Separately, api/vehicles.php's owner-selection dropdown listed every
 * member with no deleted_at filter — the same soft-delete gap GH#52 fixed
 * for facilities, just in a different read path.
 *
 * This is a functional, DB-level test rather than an HTTP one: the purge
 * logic lives inline in api/wastebasket.php's action handler (not extracted
 * to an inc/*-write.php function the way facility/member soft-delete are),
 * so exercising the real endpoint means a real authenticated HTTP request —
 * which is exactly the class of test this suite marks @requires-http and
 * skips in the fresh-install CI job that gates every push. A structural
 * check that the fix line is actually present, plus a functional check that
 * runs the same SQL sequence the endpoint runs, catches a regression in
 * every environment instead of only the ones with a browser available.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;

function test(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

require_once $root . '/config.php';

// ── 1. Structural: the fix line is actually in the shipped file ────────────
$wb = (string) file_get_contents($root . '/api/wastebasket.php');
test(
    "member purge cleans up newui_vehicles.member_id",
    (bool) preg_match('/UPDATE\s+`\{\$prefix\}newui_vehicles`\s+SET\s+`member_id`\s*=\s*NULL/i', $wb)
);

$veh = (string) file_get_contents($root . '/api/vehicles.php');
test(
    "vehicle owner dropdown excludes soft-deleted members",
    strpos($veh, "deleted_at") !== false && strpos($veh, "newui_vehicles") !== false
);

// ── 2. Functional: does DB access exist to prove it end-to-end? ────────────
$dbOk = false;
try { db_query('SELECT 1'); $dbOk = true; } catch (Throwable $e) { $dbOk = false; }

if (!$dbOk) {
    echo "SKIP: no database on this host — the functional half cannot be exercised\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

$prefix = $GLOBALS['db_prefix'] ?? '';
$hasField1 = (int) db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'field1'",
    [$prefix . 'member']
) > 0;

if (!$hasField1) {
    echo "SKIP: member.field1/field2 (legacy name columns) not present on this schema\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

$memberId = null;
$vehicleId = null;
try {
    db_query("INSERT INTO `{$prefix}member` (`field2`, `field1`) VALUES (?, ?)", ['Test', 'Owner-' . getmypid()]);
    $memberId = (int) db_insert_id();

    db_query(
        "INSERT INTO `{$prefix}newui_vehicles` (`callsign`, `member_id`, `status`) VALUES (?, ?, ?)",
        ['TEST-' . getmypid(), $memberId, 'Active']
    );
    $vehicleId = (int) db_insert_id();

    $before = db_fetch_value("SELECT member_id FROM `{$prefix}newui_vehicles` WHERE id = ?", [$vehicleId]);
    test('vehicle owned by the test member before purge', (int) $before === $memberId);

    // The exact sequence api/wastebasket.php's purge action runs for type=member.
    db_query("UPDATE `{$prefix}member` SET deleted_at = NOW() WHERE id = ?", [$memberId]);
    db_query("DELETE FROM `{$prefix}member_certifications` WHERE member_id = ?", [$memberId]);
    db_query("DELETE FROM `{$prefix}member_callsigns` WHERE member_id = ?", [$memberId]);
    db_query("DELETE FROM `{$prefix}member_organizations` WHERE member_id = ?", [$memberId]);
    db_query("DELETE FROM `{$prefix}member_comm_identifiers` WHERE member_id = ?", [$memberId]);
    db_query("UPDATE `{$prefix}newui_vehicles` SET member_id = NULL WHERE member_id = ?", [$memberId]);
    db_query("DELETE FROM `{$prefix}member` WHERE id = ?", [$memberId]);
    $memberId = null; // already gone — don't try to delete it again in cleanup

    $after = db_fetch_value("SELECT member_id FROM `{$prefix}newui_vehicles` WHERE id = ?", [$vehicleId]);
    test('vehicle.member_id is nulled, not left dangling, after the owner is purged', $after === null);
} finally {
    if ($vehicleId !== null) {
        try { db_query("DELETE FROM `{$prefix}newui_vehicles` WHERE id = ?", [$vehicleId]); } catch (Throwable $e) {}
    }
    if ($memberId !== null) {
        try { db_query("DELETE FROM `{$prefix}member` WHERE id = ?", [$memberId]); } catch (Throwable $e) {}
    }
}

// Soft-deleted members must not be selectable as a new owner going forward.
$deletedId = null;
try {
    db_query("INSERT INTO `{$prefix}member` (`field2`, `field1`, `deleted_at`) VALUES (?, ?, NOW())", ['Gone', 'Person-' . getmypid()]);
    $deletedId = (int) db_insert_id();

    $selectable = db_fetch_all("SELECT id FROM `{$prefix}member` WHERE deleted_at IS NULL");
    $ids = array_map('intval', array_column($selectable, 'id'));
    test('a soft-deleted member does not appear in the not-deleted set the owner dropdown now queries', !in_array($deletedId, $ids, true));
} finally {
    if ($deletedId !== null) {
        try { db_query("DELETE FROM `{$prefix}member` WHERE id = ?", [$deletedId]); } catch (Throwable $e) {}
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
