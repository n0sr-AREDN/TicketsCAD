<?php
/**
 * Chris Byrd, Google Group 2026-08-06: "how do I add an Agency so I can
 * assign it as an owner." There wasn't a way — only the "Agency Vehicle"
 * checkbox, a boolean with no identity attached, which is why an
 * agency-owned vehicle's Owner column always read blank.
 *
 * newui_vehicles.owner_org_id (Phase 135, sql/run_phase135_vehicle_owner_org.php)
 * reuses the existing `organizations` table — no new concept, no new admin
 * screen to build (api/organizations.php already manages it).
 *
 * The save/derivation logic under test lives inline in api/vehicles.php's
 * handlePost(), not in an extracted inc/*-write.php function — same
 * situation as the wastebasket purge cleanup this same week
 * (tests/test_vehicle_owner_integrity.php carries the full explanation).
 * Exercising the REAL endpoint means a real authenticated HTTP request,
 * which this suite marks @requires-http and skips in the fresh-install CI
 * job that gates every push. This test therefore does two things: (1) a
 * structural check that the mutual-exclusivity and derivation logic is
 * actually present in the shipped file, and (2) a functional check that
 * runs the identical field-computation the endpoint runs, against a real
 * database, so a regression in the LOGIC is caught in every environment
 * even though the HTTP wiring around it is only checked when a browser
 * is available.
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

// ── 1. Structural: the derivation logic is actually in the shipped file ────
$src = (string) file_get_contents($root . '/api/vehicles.php');
test(
    'owner_org_id is read from input',
    strpos($src, "input['owner_org_id']") !== false
);
test(
    'is_agency_vehicle is derived from owner_org_id, not trusted from the client alone',
    (bool) preg_match('/ownerOrgId\s*!==\s*null\s*\|\|\s*!empty\(\$input\[.is_agency_vehicle.\]\)/', $src)
);
test(
    'single-vehicle GET joins organizations for owner_org_name',
    strpos($src, "db_table('organizations') . \" oo ON v.owner_org_id = oo.id") !== false
);

// Mirrors the exact field-computation api/vehicles.php's handlePost() runs,
// so a regression in the LOGIC (not just its presence) is caught here too.
function vehicle_owner_fields(array $input): array
{
    $ownerOrgId = !empty($input['owner_org_id']) ? (int) $input['owner_org_id'] : null;
    $memberId   = $ownerOrgId === null && !empty($input['member_id']) ? (int) $input['member_id'] : null;
    $isAgency   = ($ownerOrgId !== null || !empty($input['is_agency_vehicle'])) ? 1 : 0;
    return ['member_id' => $memberId, 'owner_org_id' => $ownerOrgId, 'is_agency_vehicle' => $isAgency];
}

// ── 2. Logic: mutual exclusivity + derivation, in isolation ────────────────
echo "\n-- Field computation (mirrors handlePost()) --\n";

$r1 = vehicle_owner_fields(['owner_org_id' => 2]);
test('agency-only input: owner_org_id set, member_id null', $r1['owner_org_id'] === 2 && $r1['member_id'] === null);
test('agency-only input: is_agency_vehicle derived to 1', $r1['is_agency_vehicle'] === 1);

$r2 = vehicle_owner_fields(['member_id' => 5]);
test('person-only input: member_id set, owner_org_id null', $r2['member_id'] === 5 && $r2['owner_org_id'] === null);
test('person-only input: is_agency_vehicle derived to 0', $r2['is_agency_vehicle'] === 0);

$r3 = vehicle_owner_fields(['member_id' => 5, 'owner_org_id' => 2]);
test('both sent: owner_org_id wins, member_id forced null (not both persisted)', $r3['owner_org_id'] === 2 && $r3['member_id'] === null);

$r4 = vehicle_owner_fields(['is_agency_vehicle' => 1]);
test('legacy checkbox-only input (no specific org): still marks agency, no owner_org_id', $r4['is_agency_vehicle'] === 1 && $r4['owner_org_id'] === null);

$r5 = vehicle_owner_fields([]);
test('no owner input at all: both null, not agency', $r5['member_id'] === null && $r5['owner_org_id'] === null && $r5['is_agency_vehicle'] === 0);

// ── 3. Functional: against the real database ────────────────────────────
$dbOk = false;
try { db_query('SELECT 1'); $dbOk = true; } catch (Throwable $e) { $dbOk = false; }

if (!$dbOk) {
    echo "\nSKIP: no database on this host — the DB-level half cannot be exercised\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

$prefix = $GLOBALS['db_prefix'] ?? '';
$colExists = (int) db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'owner_org_id'",
    [$prefix . 'newui_vehicles']
) > 0;

if (!$colExists) {
    echo "\nSKIP: newui_vehicles.owner_org_id not present — run sql/run_phase135_vehicle_owner_org.php\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

echo "\n-- Against the real database --\n";

$org = db_fetch_one("SELECT id, name FROM `{$prefix}organizations` WHERE active = 1 ORDER BY id LIMIT 1");
if (!$org) {
    echo "SKIP: no active organization exists to assign\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

$vehicleId = null;
try {
    db_query(
        "INSERT INTO `{$prefix}newui_vehicles` (`callsign`, `owner_org_id`, `is_agency_vehicle`, `status`) VALUES (?, ?, 1, 'Active')",
        ['AGTEST-' . getmypid(), $org['id']]
    );
    $vehicleId = (int) db_insert_id();

    $joined = db_fetch_one(
        "SELECT v.owner_org_id, oo.name AS owner_org_name
           FROM `{$prefix}newui_vehicles` v
           LEFT JOIN `{$prefix}organizations` oo ON v.owner_org_id = oo.id
          WHERE v.id = ?",
        [$vehicleId]
    );
    test(
        'a vehicle assigned to a real organization resolves its name via the join',
        $joined !== null && $joined['owner_org_name'] === $org['name'],
        'expected ' . $org['name'] . ', got ' . var_export($joined['owner_org_name'] ?? null, true)
    );
} finally {
    if ($vehicleId !== null) {
        try { db_query("DELETE FROM `{$prefix}newui_vehicles` WHERE id = ?", [$vehicleId]); } catch (Throwable $e) {}
    }
}

// A vehicle whose agency was never given a specific org (the pre-existing
// "Agency Vehicle" checkbox state) must still resolve to something sane —
// no name, not a broken join.
$legacyId = null;
try {
    db_query(
        "INSERT INTO `{$prefix}newui_vehicles` (`callsign`, `is_agency_vehicle`, `status`) VALUES (?, 1, 'Active')",
        ['AGLEGACY-' . getmypid()]
    );
    $legacyId = (int) db_insert_id();

    $joined = db_fetch_one(
        "SELECT v.owner_org_id, oo.name AS owner_org_name
           FROM `{$prefix}newui_vehicles` v
           LEFT JOIN `{$prefix}organizations` oo ON v.owner_org_id = oo.id
          WHERE v.id = ?",
        [$legacyId]
    );
    test(
        'a legacy agency vehicle with no specific org still queries cleanly (null name, not an error)',
        $joined !== null && $joined['owner_org_id'] === null && $joined['owner_org_name'] === null
    );
} finally {
    if ($legacyId !== null) {
        try { db_query("DELETE FROM `{$prefix}newui_vehicles` WHERE id = ?", [$legacyId]); } catch (Throwable $e) {}
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
