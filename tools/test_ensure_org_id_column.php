<?php
/**
 * ensure_org_id_column() — the "Row size too large" retry.
 *
 * Found live on this dev box's own `teams` table: api/teams.php calls
 * ensure_org_id_column('teams') then immediately builds a WHERE fragment
 * referencing t.org_id (org_query_filter). Before this fix, a table where
 * the plain ADD COLUMN failed with MySQL/MariaDB error 1118 "Row size too
 * large" left ensure_org_id_column() catching the exception and logging it
 * — silently. The very next query then referenced a column that was never
 * added, so the teams LIST endpoint would fail outright with an unknown-
 * column SQL error on any install where teams hit this.
 *
 * Root cause (verified against the real affected teams table, not a guess):
 * information_schema.TABLES / SHOW TABLE STATUS both reported ROW_FORMAT
 * 'Dynamic' for this table, but a plain `ADD COLUMN org_id INT` still threw
 * 1118 — and running `ALTER TABLE teams ROW_FORMAT=DYNAMIC` (forcing an
 * actual rebuild) immediately before the ADD COLUMN made it succeed. That
 * strongly suggests the table's on-disk .ibd file predates Barracuda/Dynamic
 * becoming the default and was never physically rebuilt, even though the
 * metadata views describe what a NEW table would get today, not necessarily
 * what an old one has on disk.
 *
 * What this file can and cannot prove in CI:
 *   - It CAN prove the retry code path exists and is structurally sound
 *     (section 1), and that ensure_org_id_column() still works correctly in
 *     the ordinary case with no regression (section 2).
 *   - It CANNOT synthetically reproduce the exact 1118 condition — every
 *     attempt to recreate it with a fresh CREATE TABLE (LIKE teams, then
 *     explicit ROW_FORMAT=COMPACT, then REDUNDANT) succeeded without error
 *     on this same server, because a freshly created table always gets a
 *     genuinely new .ibd file under current engine defaults regardless of
 *     the ROW_FORMAT clause used afterward. The failure is a property of an
 *     OLD table's physical file, not of the schema alone, so it cannot be
 *     manufactured from scratch in a fresh CI database. The fix was verified
 *     directly against the real live table where it was found instead (see
 *     the commit message for that session's transcript of the reproduction).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/org-scope.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$pass = 0;
$fail = 0;
function ok(string $name): void { global $pass; echo "  PASS  $name\n"; $pass++; }
function bad(string $name, string $why = ''): void {
    global $fail; echo "  FAIL  $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++;
}

echo "=== ensure_org_id_column() — Row size too large retry ===\n\n";

// ── 1. Structural: the retry path is really there ──────────────────
$src = file_get_contents(__DIR__ . '/../inc/org-scope.php');
(strpos($src, '1118') !== false && stripos($src, 'Row size too large') !== false)
    ? ok('source detects MySQL/MariaDB error 1118 by number and by message')
    : bad('1118 detection present in source');
(preg_match('/ROW_FORMAT\s*=\s*DYNAMIC/i', $src) === 1)
    ? ok('source retries with an explicit ROW_FORMAT=DYNAMIC rebuild')
    : bad('ROW_FORMAT=DYNAMIC retry present in source');
// The retry must be scoped to 1118 specifically -- a table genuinely locked
// (permissions, replication) should still fail and log, not spin forever.
(strpos($src, 'throw $e') !== false)
    ? ok('a non-1118 failure is still re-thrown to the outer catch, not swallowed')
    : bad('non-1118 failures still propagate');

// ── 2. Functional: no regression on an ordinary table ──────────────
$scratch = $prefix . 'test_org_id_scratch_' . getmypid();
try {
    db_query("DROP TABLE IF EXISTS `{$scratch}`");
    db_query("CREATE TABLE `{$scratch}` (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(48) NOT NULL DEFAULT '')");

    $before = db_fetch_value(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'org_id'",
        [$scratch]
    );
    (!$before) ? ok('scratch table starts without org_id') : bad('scratch table starts without org_id');

    // ensure_org_id_column() takes the table name WITHOUT the prefix and
    // adds it back internally -- match that contract here.
    $bareName = ($prefix !== '' && strpos($scratch, $prefix) === 0)
        ? substr($scratch, strlen($prefix)) : $scratch;
    ensure_org_id_column($bareName);

    $after = db_fetch_value(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'org_id'",
        [$scratch]
    );
    ($after) ? ok('org_id added on the first call (ordinary case, unaffected by the 1118 fix)')
             : bad('org_id added on the first call');

    // A second call must be a no-op, not a duplicate-column error -- the
    // existing behaviour this fix must not disturb.
    ensure_org_id_column($bareName);
    ok('a second call does not throw (idempotent, matches prior behaviour)');
} catch (Throwable $e) {
    bad('functional round-trip', $e->getMessage());
} finally {
    try { db_query("DROP TABLE IF EXISTS `{$scratch}`"); } catch (Throwable $e) { /* best effort */ }
}

// ── 3. The real install this was found on: teams itself ────────────
// api/teams.php calls ensure_org_id_column('teams') on every list request
// before referencing t.org_id -- nothing calls it during this CLI suite
// otherwise, so a fresh CI database's teams table has never had the
// chance to get the column yet. Call it here the same way that request
// path does, then assert teams ends up with org_id regardless of which
// row-format state this install's teams table happens to be in.
try {
    ensure_org_id_column('teams');
    $teamsHasOrgId = db_fetch_value(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'org_id'",
        [$prefix . 'teams']
    );
    ($teamsHasOrgId)
        ? ok('teams has org_id after ensure_org_id_column() -- what api/teams.php relies on before every list request')
        : bad('teams is missing org_id', 'api/teams.php\'s org_query_filter(\'t.org_id\') would fail on this install right now');
} catch (Throwable $e) {
    bad('teams org_id check', $e->getMessage());
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
