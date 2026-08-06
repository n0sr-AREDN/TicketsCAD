<?php
/**
 * Heal migration: RBAC permission `action.manage_audit_retention`
 * (Phase 133, 2026-08-03)
 *
 * sql/run_phase133_audit_retention.php grants this permission to Super
 * Admin (role 1) ONLY — audit-log retention/purge is scoped identically to
 * action.manage_config, per its own docblock. But sql/run_00_rbac.php's and
 * sql/rbac.sql's broad Org Admin / Dispatcher grants
 * (`INSERT IGNORE ... SELECT id FROM permissions WHERE code NOT IN (...)`)
 * are re-runnable, and this permission's row can end up SELECTed by one of
 * those broad grants on an install where that grant ran (or re-ran) between
 * the permission being created and its exclusion-list entry landing — the
 * same "broad RBAC grants in re-runnable seeds sweep up later permissions"
 * pattern documented in the project's own history (bulk_delete_members,
 * console.design, action.intercom_unlock all hit this before; see
 * sql/run_bulk_delete_member_perm.php for the reference heal). Both
 * exclusion lists already name this permission going forward — this
 * migration only repairs data that predates that, or that a stray re-run
 * reintroduced.
 *
 * Safety: idempotent. Re-asserts the Super Admin grant and revokes any
 * stray grant to every OTHER seeded default role (2-5) on every run, so a
 * partially-applied or regressed state self-heals. Custom roles (id > 6)
 * are never touched — an admin may have granted those deliberately via the
 * Roles UI.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    $permId = (int) db_fetch_value(
        "SELECT id FROM `{$prefix}permissions` WHERE `code` = ?",
        ['action.manage_audit_retention']
    );

    if ($permId > 0) {
        $superExists = db_fetch_one("SELECT id FROM `{$prefix}roles` WHERE id = 1");
        if ($superExists) {
            db_query(
                "INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`) VALUES (1, ?)",
                [$permId]
            );
        }

        // Revoke from every other seeded default role (2 = Org Admin,
        // 3 = Dispatcher, 4 = Operator, 5 = Read-Only). Only role 1 should
        // ever hold this by default.
        $stmt = db_query(
            "DELETE FROM `{$prefix}role_permissions` WHERE `role_id` IN (2, 3, 4, 5) AND `permission_id` = ?",
            [$permId]
        );
        $revoked = $stmt ? $stmt->rowCount() : 0;

        echo "[OK] RBAC permission action.manage_audit_retention healed (Super Admin only"
            . ($revoked > 0 ? ", revoked $revoked stray default-role grant(s)" : '') . ")\n";
    } else {
        echo "[--] action.manage_audit_retention not yet seeded — nothing to heal "
            . "(run sql/run_phase133_audit_retention.php first)\n";
    }
} catch (Exception $e) {
    // permissions/role_permissions tables might not exist yet (RBAC not installed).
    echo "[WARN] action.manage_audit_retention heal: " . $e->getMessage() . "\n";
}
