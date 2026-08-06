<?php
/**
 * NewUI v4.0 API — Audit Log Retention & Purge (Phase 133, 2026-08-03)
 *
 * GET  ?action=status          — current setting, CJIS-floor warning, eligible
 *                                 row count, last purge, archive directory
 *                                 exposure, scheduled-job state
 * GET  ?action=eligible_count&days=N — recompute the count for a CANDIDATE
 *                                 value before the admin saves it
 * POST action=save_setting     — change audit_log_retention_days
 * POST action=purge_now        — trigger a manual purge
 *
 * save_setting and purge_now are gated on action.manage_audit_retention (or
 * is_admin()) — a NEW, dedicated permission, deliberately NOT routed through
 * the generic api/config-admin.php?section=settings upsert. That endpoint
 * gates every settings write on action.manage_config alone, which would make
 * a purpose-built permission for this feature meaningless. See
 * specs/phase-133-audit-log-retention/plan.md §4/§6.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit-retention.php';
require_once __DIR__ . '/../inc/scheduled-jobs.php';

ini_set('display_errors', '0');

function _artn_can_manage(): bool
{
    return is_admin() || (function_exists('rbac_can') && rbac_can('action.manage_audit_retention'));
}

function _artn_can_view(): bool
{
    return _artn_can_manage()
        || (function_exists('rbac_can') && rbac_can('action.view_audit'));
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$postInput = null;
if ($method === 'POST') {
    $postInput = json_decode(file_get_contents('php://input'), true) ?? [];
    if (empty($action)) {
        $action = $postInput['action'] ?? '';
    }
}

// ═══════════════════════════════════════════════════════════════
//  Status
// ═══════════════════════════════════════════════════════════════
if ($action === 'status' && $method === 'GET') {
    if (!_artn_can_view()) {
        json_error('Admin access required', 403);
    }
    try {
        $status = audit_retention_status();
        $jobs   = sched_jobs_status();
        $job    = null;
        foreach ($jobs['jobs'] as $j) {
            if ($j['job'] === 'audit_log_purge') { $job = $j; break; }
        }
        json_response(['status' => $status, 'scheduled_job' => $job]);
    } catch (Throwable $e) {
        error_log('[audit-retention] status failed: ' . $e->getMessage());
        json_error('Could not read audit retention status', 500);
    }
}

// ═══════════════════════════════════════════════════════════════
//  Eligible count for a candidate value (not yet saved)
// ═══════════════════════════════════════════════════════════════
if ($action === 'eligible_count' && $method === 'GET') {
    if (!_artn_can_view()) {
        json_error('Admin access required', 403);
    }
    $days = max(0, (int) ($_GET['days'] ?? 0));
    try {
        json_response([
            'days'            => $days,
            'eligible_count'  => audit_retention_eligible_count($days),
            'below_cjis_floor' => audit_retention_below_cjis_floor($days),
            'cjis_warning'    => audit_retention_below_cjis_floor_warning($days),
        ]);
    } catch (Throwable $e) {
        error_log('[audit-retention] eligible_count failed: ' . $e->getMessage());
        json_error('Could not compute eligible count', 500);
    }
}

// ═══════════════════════════════════════════════════════════════
//  Save the retention-days setting
// ═══════════════════════════════════════════════════════════════
if ($action === 'save_setting' && $method === 'POST') {
    if (!_artn_can_manage()) {
        json_error('action.manage_audit_retention required', 403);
    }
    $input = $postInput ?: [];
    if (!csrf_verify($input['csrf_token'] ?? '')) {
        json_error('Invalid CSRF token', 403);
    }
    if (!isset($input['days']) || !is_numeric($input['days']) || (int) $input['days'] < 0) {
        json_error('days must be a non-negative integer', 400);
    }
    $days = (int) $input['days'];

    try {
        audit_retention_setting_set('audit_log_retention_days', (string) $days);
    } catch (Throwable $e) {
        error_log('[audit-retention] save_setting failed: ' . $e->getMessage());
        json_error('Could not save setting', 500);
    }

    $userId = $_SESSION['user_id'] ?? null;
    try {
        require_once __DIR__ . '/../inc/audit.php';
        audit_log('admin', 'config_change', 'setting', 'audit_log_retention_days',
            "Changed audit log retention to {$days} day(s)" . ($days === 0 ? ' (disabled)' : ''),
            ['key' => 'audit_log_retention_days', 'new_value' => $days], AUDIT_MEDIUM);
    } catch (Throwable $e) { /* audit failure must never break the save */ }

    json_response([
        'success'          => true,
        'days'             => $days,
        'below_cjis_floor' => audit_retention_below_cjis_floor($days),
        'cjis_warning'     => audit_retention_below_cjis_floor_warning($days),
        'eligible_count'   => audit_retention_eligible_count($days),
        'status'           => audit_retention_status(),
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  Manual purge
// ═══════════════════════════════════════════════════════════════
if ($action === 'purge_now' && $method === 'POST') {
    if (!_artn_can_manage()) {
        json_error('action.manage_audit_retention required', 403);
    }
    $input = $postInput ?: [];
    if (!csrf_verify($input['csrf_token'] ?? '')) {
        json_error('Invalid CSRF token', 403);
    }

    set_time_limit(300);

    // Only 'triggered_by'/'triggered_by_user_id' are ever set from this
    // endpoint — the rest of $opts (including the test-only
    // '_capability_pdo' hook) is never reachable from request input.
    try {
        $r = audit_purge_run([
            'triggered_by'          => 'manual',
            'triggered_by_user_id'  => $_SESSION['user_id'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('[audit-retention] manual purge failed: ' . $e->getMessage());
        json_error('Purge failed: ' . $e->getMessage(), 500);
    }

    json_response([
        'success' => (bool) $r['ok'],
        'skipped' => !empty($r['skipped']),
        'purged'  => (int) $r['purged'],
        'detail'  => $r['detail'],
        'archive' => $r['archive'],
        'status'  => audit_retention_status(),
    ]);
}

json_error('Unknown action', 400);
