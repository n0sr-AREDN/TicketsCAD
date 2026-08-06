<?php
/**
 * NewUI v4.0 API — Incident disposition PICKER (Phase 132 Step 4, GH #16)
 *
 * Read-only list of ACTIVE dispositions to OFFER when setting/changing an
 * incident's disposition, filtered by the incident's type discipline and
 * org — with the HARD INVARIANT (plan.md §1) that the list is never
 * truncated to empty: an incident type with no discipline tag, or one
 * that matches no active disposition, falls back to the FULL active
 * list. See disposition_options_for_ticket_internal()'s docblock in
 * inc/disposition-admin.php for the full contract.
 *
 * DELIBERATELY SEPARATE from api/dispositions.php (Step 3's admin CRUD,
 * gated on action.manage_dispositions): plan.md §8 — selecting a
 * disposition when closing/editing an incident needs NO special
 * permission, only ordinary incident access (same IDOR + org-scope gate
 * api/incident-detail.php uses). Mirrors the existing api/un-statuses.php
 * / api/facilities.php pattern — a lightweight reference-list endpoint
 * fetched independently by the incident-detail JS, rather than folded
 * into incident-detail.php's own GET payload. (incident-detail.php's GET
 * DOES carry the incident's own disposition_id/disposition_label for
 * display — this endpoint is the OPTIONS list, same split as
 * api/un-statuses.php vs. an assignment's own status_id/status_name.)
 *
 * GET /api/dispositions-picker.php?ticket_id=123
 *   -> { dispositions: [...active, discipline+org filtered, with
 *        fallback...], current_id, current_label, current_retired,
 *        disposition_required_on_close }
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/disposition-admin.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$ticketId = (int) ($_GET['ticket_id'] ?? 0);
if ($ticketId <= 0) {
    ini_set('display_errors', $prevDisplay);
    json_error('Invalid ticket_id');
}

// Soft-delete sweep (issue #25 follow-up, same pattern as
// api/incident-update.php's existence guard) — a soft-deleted incident
// must not serve disposition data (or confirm its own existence) here
// either. Neither user_can_access_entity() nor org_can_see_ticket() below
// filter deleted_at themselves (both are permission checks, not content
// reads — see tools/soft_delete_audit_exceptions.txt's Category A entry
// for inc/org-scope.php:311), so this endpoint needs its own explicit
// check, same as every other content-serving incident endpoint.
$prefix = $GLOBALS['db_prefix'] ?? '';
try {
    $exists = db_fetch_value(
        "SELECT `id` FROM `{$prefix}ticket` WHERE `id` = ?
           AND (`deleted_at` IS NULL OR `deleted_at` = '0000-00-00 00:00:00')",
        [$ticketId]
    );
} catch (Exception $e) {
    ini_set('display_errors', $prevDisplay);
    json_error('Database error: ' . $e->getMessage(), 500);
}
if (!$exists) {
    ini_set('display_errors', $prevDisplay);
    json_error('Incident not found', 404);
}

// Same IDOR + org-scope gate as api/incident-detail.php (Constitution
// rule #27: 404, not 403 — don't confirm existence of a ticket the
// session can't see, even though this endpoint exposes no incident PII
// itself).
if (!user_can_access_entity('incident', $ticketId)) {
    ini_set('display_errors', $prevDisplay);
    json_error('Incident not found', 404);
}
require_once __DIR__ . '/../inc/org-scope.php';
if (!org_can_see_ticket($ticketId)) {
    ini_set('display_errors', $prevDisplay);
    json_error('Incident not found', 404);
}

try {
    json_response(disposition_options_for_ticket_internal($ticketId));
} catch (Exception $e) {
    ini_set('display_errors', $prevDisplay);
    json_error('Failed to load dispositions: ' . $e->getMessage(), 500);
}

ini_set('display_errors', $prevDisplay);
