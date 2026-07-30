<?php
/**
 * Phase 129 (2026-07-29) — user_roles grant uniqueness.
 *
 * The defect these defend against: `user_roles` carried a UNIQUE key whose
 * last column was NULLable (`org_id`, later `scope_id`). MySQL and MariaDB
 * treat every NULL in a UNIQUE index as distinct, so the key placed no
 * constraint whatsoever on GLOBAL grants — the most common kind. On top of
 * that non-constraint sat `INSERT IGNORE`, in sql/run_00_rbac.php, whose
 * entire duplicate-suppression depends on the key raising an error.
 *
 * It never raised one. Every run of the migration pipeline appended another
 * identical Super Admin row: 13 copies on your-server, 23 on
 * training, 718 on this developer's own database. The same statement also
 * hardcoded `user_id = 1` without checking that user 1 exists, which is how
 * training accumulated 23 Super Admin grants to an account that has never
 * existed there — waiting for the day some new account is created with
 * id 1 and silently inherits them.
 *
 * These tests drive the REAL writers: the seed script is executed as a
 * subprocess exactly as the migration runner executes it, and the
 * constraint is tested by asking the database to accept a duplicate rather
 * than by inspecting DDL. A test that only read information_schema would
 * have passed against the broken key for the whole time it was broken.
 */

require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0; $skipped = 0;
function ok(string $m): void   { global $pass; $pass++; echo "  PASS: $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  FAIL: $m\n"; }
function is_ok($cond, string $m): void { $cond ? ok($m) : bad($m); }
function skip(string $m): void { global $skipped; $skipped++; echo "  SKIP: $m\n"; }

$prefix = $GLOBALS['db_prefix'] ?? '';
$root   = dirname(__DIR__);

echo "\n=== Phase 129 — user_roles grant uniqueness ===\n";

$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) {
    skip('No database available — these tests need one');
    echo "\n=== $pass passed, $fail failed, $skipped skipped ===\n";
    exit(0);
}

$haveTable = false;
try {
    $haveTable = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'user_roles']) > 0;
} catch (Throwable $e) {}
if (!$haveTable) {
    skip('user_roles missing — run php sql/run_00_rbac.php');
    echo "\n=== $pass passed, $fail failed, $skipped skipped ===\n";
    exit(0);
}

$hasScopeKey = (int) db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'scope_key'",
    [$prefix . 'user_roles']) > 0;

// A user id far outside anything a real install allocates, so the fixtures
// below can never collide with a live account.
$TESTUSER = 987654;

/** Remove every fixture row this file creates. */
$cleanup = function () use ($prefix, $TESTUSER) {
    try { db_query("DELETE FROM `{$prefix}user_roles` WHERE user_id = ?", [$TESTUSER]); }
    catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}user` WHERE id = ?", [$TESTUSER]); }
    catch (Throwable $e) {}
};
$cleanup();

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 1. The constraint refuses a duplicate global grant --\n";
//
// This is the property, stated as the database sees it. Asked twice for the
// identical global grant, the second must be rejected. Before Phase 129 both
// INSERTs succeeded, which is the whole bug.

if (!$hasScopeKey) {
    skip('scope_key absent — run php sql/run_rbac_v3_grant_uniqueness.php');
} else {
    db_query("INSERT INTO `{$prefix}user_roles`
              (user_id, role_id, org_id, scope_kind, scope_id)
              VALUES (?, 1, NULL, 'global', NULL)", [$TESTUSER]);
    $rejected = false;
    try {
        db_query("INSERT INTO `{$prefix}user_roles`
                  (user_id, role_id, org_id, scope_kind, scope_id)
                  VALUES (?, 1, NULL, 'global', NULL)", [$TESTUSER]);
    } catch (Throwable $e) { $rejected = true; }
    is_ok($rejected, 'a second identical GLOBAL grant is rejected by uk_user_role_scope');

    $n = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}user_roles` WHERE user_id = ?", [$TESTUSER]);
    is_ok($n === 1, "exactly one grant row survives two attempts (got {$n})");

    // INSERT IGNORE is the form the seed actually used. It must now be a
    // genuine no-op rather than an append.
    db_query("INSERT IGNORE INTO `{$prefix}user_roles`
              (user_id, role_id, org_id, scope_kind, scope_id)
              VALUES (?, 1, NULL, 'global', NULL)", [$TESTUSER]);
    $n = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}user_roles` WHERE user_id = ?", [$TESTUSER]);
    is_ok($n === 1, "INSERT IGNORE of the same global grant adds nothing (got {$n})");

    // Distinct scopes must still be allowed — the constraint has to stop
    // duplicates without preventing legitimate per-org grants.
    db_query("INSERT INTO `{$prefix}user_roles`
              (user_id, role_id, org_id, scope_kind, scope_id)
              VALUES (?, 1, 7, 'org', 7)", [$TESTUSER]);
    $n = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}user_roles` WHERE user_id = ?", [$TESTUSER]);
    is_ok($n === 2, "the same role in a DIFFERENT scope is still allowed (got {$n})");

    $cleanup();
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. Re-running the RBAC seed does not create a second grant --\n";
//
// The headline regression. Executed as a subprocess, the way
// sql/run_migrations.php runs it, so the test exercises the real script
// rather than a re-implementation of what it is believed to do.

$seed = $root . '/sql/run_00_rbac.php';
if (!is_file($seed)) {
    skip('sql/run_00_rbac.php not found');
} else {
    $before = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}user_roles`");
    $php    = PHP_BINARY ?: 'php';
    $out    = (string) shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($seed) . ' 2>&1');
    $mid    = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}user_roles`");
    $out2   = (string) shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($seed) . ' 2>&1');
    $after  = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}user_roles`");

    is_ok($mid === $before,
          "first re-run of run_00_rbac.php adds no grant ({$before} -> {$mid})");
    is_ok($after === $mid,
          "second re-run adds no grant ({$mid} -> {$after})");
    is_ok(stripos($out, 'fatal') === false && stripos($out2, 'fatal') === false,
          'the seed runs clean on a database that already has its rows');

    // The seed must decline to grant to a user that does not exist, and say
    // so, rather than writing a grant nobody holds.
    $user1 = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}user` WHERE id = 1");
    if ($user1 === 0) {
        is_ok(strpos($out2, 'No user #1') !== false,
              'with no user #1 present, the seed skips the grant and says so');
        $phantom = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}user_roles` WHERE user_id = 1");
        is_ok($phantom === 0, "no grant is written for the absent user #1 (got {$phantom})");
    } else {
        skip('user #1 exists on this install — the absent-user branch is covered on training');
    }
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. The migration is idempotent and self-verifying --\n";

$mig = $root . '/sql/run_rbac_v3_grant_uniqueness.php';
if (!is_file($mig)) {
    skip('sql/run_rbac_v3_grant_uniqueness.php not found');
} else {
    $php = PHP_BINARY ?: 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($mig) . ' 2>&1';
    $before = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}user_roles`");
    $out = (string) shell_exec($cmd);
    $after = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}user_roles`");
    is_ok($before === $after,
          "re-running the migration changes no rows ({$before} -> {$after})");
    is_ok(strpos($out, '[FAILED]') === false, 'the migration reports no failures');
    is_ok(strpos($out, 'no duplicates, none dangling') !== false,
          'the migration verifies its own outcome against the database');
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. Live data holds the invariant --\n";
//
// Whatever the history of this install, it must not currently be carrying
// duplicate or dangling grants. Dangling Super Admin rows are the hazard
// worth naming: an id that is re-used inherits them.

$dupeGroups = (int) db_fetch_value(
    "SELECT COUNT(*) FROM (
        SELECT COUNT(*) n FROM `{$prefix}user_roles`
         GROUP BY user_id, role_id, scope_kind, COALESCE(scope_id, -1)
        HAVING n > 1) d");
is_ok($dupeGroups === 0, "no duplicate grant groups in user_roles (found {$dupeGroups})");

$dangling = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}user_roles` ur
       LEFT JOIN `{$prefix}user` u ON u.id = ur.user_id
      WHERE u.id IS NULL");
is_ok($dangling === 0, "no grants reference a missing user (found {$dangling})");

$cleanup();

echo "\n=== $pass passed, $fail failed, $skipped skipped ===\n";
exit($fail > 0 ? 1 : 0);
