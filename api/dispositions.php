<?php
/**
 * NewUI v4.0 API - Incident Dispositions (Phase 132 Step 3, GH #16)
 *
 * Admin CRUD for the ticket_disposition lookup table + the
 * disposition_required_on_close enforcement toggle, for the
 * Settings -> Incident Dispositions panel (settings.php#incident-dispositions).
 * specs/phase-132-incident-disposition/{spec.md,plan.md,tasks.md}.
 *
 * Deliberately a STANDALONE endpoint, not a new section on
 * api/config-admin.php: that file gates its ENTIRE section dispatch on
 * one top-level `action.manage_config` check (see its header), which
 * would make managing dispositions require full system-config access —
 * defeating the point of the dedicated `action.manage_dispositions`
 * permission Step 1 built (sql/run_phase132_disposition.php)
 * specifically so this could someday be delegated independently.
 *
 * Selecting a disposition ON an incident (api/incident-update.php's
 * set_disposition action, Step 2) needs NO permission — this endpoint
 * is ONLY for managing the admin list (plan.md §8). Gated identically
 * on GET and POST: a page gate and an API gate naming different
 * permissions is Phase 128's your deployment Org Admin bug (CLAUDE.md).
 *
 * GET  (no action, or ?action=list)
 *      -> { dispositions: [...] (active AND retired), disposition_required_on_close: '0'|'1' }
 * POST action=save             { id?, status_val, description?, code (create only),
 *                                 discipline?, org_id?, sort_order?, requires_comment? }
 * POST action=retire            { id }
 * POST action=reactivate        { id }
 * POST action=set_enforcement   { value: '0'|'1' }
 *
 * Business logic lives in inc/disposition-admin.php so
 * tests/test_phase132_settings_panel.php can drive it directly — same
 * split as inc/incident-write.php / api/incident-update.php (Step 2).
 * This file owns only auth/RBAC/CSRF and JSON response shaping.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/disposition-admin.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

// Managing the list is admin-only. rbac_can() already grants Super
// Admin unconditionally (super-admin short-circuit); no separate
// is_admin() fallback needed — see sql/run_phase132_disposition.php's
// docblock for why this permission is Super Admin ONLY by default.
if (!rbac_can('action.manage_dispositions')) {
    ini_set('display_errors', $prevDisplay);
    json_error('Insufficient permissions: manage incident dispositions', 403);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    try {
        json_response(disposition_list_internal());
    } catch (Exception $e) {
        ini_set('display_errors', $prevDisplay);
        json_error('Failed to load dispositions: ' . $e->getMessage(), 500);
    }
} elseif ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        ini_set('display_errors', $prevDisplay);
        json_error('Invalid JSON body');
    }

    $token = $input['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!csrf_verify((string) $token)) {
        ini_set('display_errors', $prevDisplay);
        json_error('Invalid CSRF token', 403);
    }

    $action = $input['action'] ?? '';
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    switch ($action) {
        case 'save':
            $result = disposition_save_internal($input, $userId);
            if (empty($result['success'])) {
                json_error($result['error'] ?? 'Save failed.', 400);
            }
            json_response(['success' => true, 'id' => $result['id']]);
            break;

        case 'retire':
        case 'reactivate':
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                json_error('Missing disposition id');
            }
            $result = disposition_set_active_internal($id, $action === 'reactivate', $userId);
            if (empty($result['success'])) {
                json_error($result['error'] ?? 'Update failed.', 400);
            }
            json_response(['success' => true, 'id' => $result['id'], 'active' => $result['active']]);
            break;

        case 'set_enforcement':
            $value = (string) ($input['value'] ?? '0');
            $result = disposition_set_enforcement_internal($value, $userId);
            if (empty($result['success'])) {
                json_error($result['error'] ?? 'Save failed.', 400);
            }
            json_response(['success' => true, 'value' => $result['value']]);
            break;

        default:
            json_error('Unknown action: ' . $action . '. Valid actions: save, retire, reactivate, set_enforcement');
    }
} else {
    json_error('Method not allowed', 405);
}

ini_set('display_errors', $prevDisplay);
