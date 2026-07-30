<?php
/**
 * Shared test helper: which user is actually the administrator?
 *
 * Phase 129 (2026-07-29). Eight test files hardcoded `user_id = 1` as "the
 * admin". That is not true on any fresh install and never has been:
 * sql/base_schema.sql pins the `user` table to AUTO_INCREMENT=3 (an
 * artefact of the legacy v3 dump it was made from), so the first account
 * tools/create_admin.php creates lands at id 3. Across the real fleet the
 * administrator is id 3 in CI, id 1 on your-server, and id 39 on
 * training.ticketscad — user 1 does not exist there at all.
 *
 * Those tests passed anyway, for a reason worth recording: sql/run_00_rbac.php
 * unconditionally inserted a Super Admin grant for user_id 1 whether or not
 * such a user existed. So `user 1 holds Super Admin` was true on every
 * install — as a phantom row describing nobody. Eight test files were
 * resting on it, and fixing the seed is what exposed them. A grant to a
 * user that does not exist is not a fixture; it is the bug the tests should
 * have caught.
 *
 * Resolution order:
 *   1. the lowest-id user actually holding the Super Admin role
 *   2. else the lowest-id user that can log in
 *   3. else the lowest-id user
 *   4. else 1, so a test on an empty database still has something to say
 *
 * File name starts with `_` so tools/test_all.php (which globs test_*.php)
 * does not try to run it as a test.
 */

if (!function_exists('test_admin_user_id')) {
    /**
     * @return int user id of the administrator on THIS install
     */
    function test_admin_user_id(): int {
        static $cached = null;
        if ($cached !== null) return $cached;

        $prefix = $GLOBALS['db_prefix'] ?? '';

        // 1. Whoever actually holds Super Admin.
        try {
            $id = db_fetch_value(
                "SELECT ur.user_id
                   FROM `{$prefix}user_roles` ur
                   JOIN `{$prefix}roles` r ON r.id = ur.role_id
                   JOIN `{$prefix}user`  u ON u.id = ur.user_id
                  WHERE (r.name = 'Super Admin' OR r.is_super = 1)
                    AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
                  ORDER BY ur.user_id LIMIT 1");
            if ($id) return $cached = (int) $id;
        } catch (Throwable $e) { /* pre-RBAC schema */ }

        // 2. Any account that can log in.
        try {
            $id = db_fetch_value(
                "SELECT id FROM `{$prefix}user` WHERE can_login = 1 ORDER BY id LIMIT 1");
            if ($id) return $cached = (int) $id;
        } catch (Throwable $e) { /* can_login may not exist */ }

        // 3. Any account at all.
        try {
            $id = db_fetch_value("SELECT id FROM `{$prefix}user` ORDER BY id LIMIT 1");
            if ($id) return $cached = (int) $id;
        } catch (Throwable $e) {}

        return $cached = 1;
    }
}
