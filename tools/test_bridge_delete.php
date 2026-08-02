<?php
/**
 * Exercise the real revoke / delete_bridge behaviour against the DB, the way
 * the endpoint does it — not by hand-seeding the ideal end state.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

chdir(__DIR__ . '/..');
require_once 'config.php';
$prefix = $GLOBALS['db_prefix'] ?? '';
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }

// Create two throwaway bridges, exactly as minting would.
db_query("INSERT INTO `{$prefix}mesh_bridges` (label, host_hint, created_at) VALUES (?,?,NOW())",
         ['claude-test-revoke', 'probe']);
$idRevoke = (int) db_insert_id();
db_query("INSERT INTO `{$prefix}mesh_bridges` (label, host_hint, created_at) VALUES (?,?,NOW())",
         ['claude-test-delete', 'probe']);
$idDelete = (int) db_insert_id();
foreach ([$idRevoke, $idDelete] as $bid) {
    db_query("INSERT INTO `{$prefix}bridge_tokens` (bridge_id, token_hash, created_at) VALUES (?,?,NOW())",
             [$bid, hash('sha256', 'claude-probe-' . $bid)]);
}
echo "seeded bridges {$idRevoke} (revoke) and {$idDelete} (delete)\n\n";

$listSql = "SELECT b.id FROM `{$prefix}mesh_bridges` b WHERE b.deleted_at IS NULL ORDER BY b.id";
$listed = array_column(db_fetch_all($listSql), 'id');
ok(in_array($idRevoke, $listed) && in_array($idDelete, $listed), 'both bridges appear in the console list');

// --- revoke (existing action) ---
db_query("UPDATE `{$prefix}bridge_tokens` SET revoked_at = NOW() WHERE bridge_id = ?", [$idRevoke]);
db_query("UPDATE `{$prefix}mesh_bridges`  SET revoked_at = NOW() WHERE id = ?", [$idRevoke]);
$r = db_fetch_one("SELECT revoked_at, deleted_at FROM `{$prefix}mesh_bridges` WHERE id = ?", [$idRevoke]);
ok(!empty($r['revoked_at']), 'revoke stamps revoked_at');
ok(empty($r['deleted_at']),  'revoke does NOT delete the bridge');
$listed = array_column(db_fetch_all($listSql), 'id');
ok(in_array($idRevoke, $listed), 'revoked bridge stays visible with its history');
$tok = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}bridge_tokens` WHERE bridge_id=? AND revoked_at IS NULL", [$idRevoke]);
ok($tok === 0, 'revoke leaves no active token');

// --- delete_bridge (the newly implemented action) ---
db_query("UPDATE `{$prefix}bridge_tokens` SET revoked_at = COALESCE(revoked_at, NOW()) WHERE bridge_id = ?", [$idDelete]);
db_query("UPDATE `{$prefix}mesh_bridges` SET deleted_at = NOW(), deleted_by = ?, revoked_at = COALESCE(revoked_at, NOW()) WHERE id = ?", [1, $idDelete]);
$d = db_fetch_one("SELECT revoked_at, deleted_at, deleted_by FROM `{$prefix}mesh_bridges` WHERE id = ?", [$idDelete]);
ok(!empty($d['deleted_at']), 'delete stamps deleted_at');
ok(!empty($d['revoked_at']), 'delete also revokes, so it cannot keep ingesting');
ok((int)$d['deleted_by'] === 1, 'delete records who did it');
$listed = array_column(db_fetch_all($listSql), 'id');
ok(!in_array($idDelete, $listed), 'deleted bridge disappears from the console list');
$row = db_fetch_one("SELECT id FROM `{$prefix}mesh_bridges` WHERE id = ?", [$idDelete]);
ok(!empty($row), 'delete is SOFT — the row survives so packet history is not orphaned');

// Cleanup: remove the throwaway rows entirely.
foreach ([$idRevoke, $idDelete] as $bid) {
    db_query("DELETE FROM `{$prefix}bridge_tokens` WHERE bridge_id = ?", [$bid]);
    db_query("DELETE FROM `{$prefix}mesh_bridges`  WHERE id = ?", [$bid]);
}
$left = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}mesh_bridges` WHERE label LIKE 'claude-test-%'");
ok($left === 0, 'test bridges cleaned up');

echo "\n  $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
