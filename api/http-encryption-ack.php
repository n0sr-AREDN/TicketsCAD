<?php
/**
 * Phase 118 (2026-07-24) — record an administrator's acknowledgment that this
 * install is operating without HTTPS. Quiets the navbar reminder banner for
 * http_enc_ttl_days() (7) days, per-admin. See inc/http-encryption-notice.php.
 *
 * POST /api/http-encryption-ack.php   body: {csrf_token}
 *   → {success:true, next_reminder_days:7}   on success
 * Admin-only, CSRF-protected, audit-logged.
 */

require_once __DIR__ . '/../config.php';

ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';                       // session auth + json_* + csrf_verify
require_once __DIR__ . '/../inc/rbac.php';                // is_admin()
require_once __DIR__ . '/../inc/audit.php';               // audit_log()
require_once __DIR__ . '/../inc/client-ip.php';           // client_ip()
require_once __DIR__ . '/../inc/http-encryption-notice.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST required', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
if (!csrf_verify($input['csrf_token'] ?? '')) {
    json_error('Invalid CSRF token', 403);
}

if (!(function_exists('is_admin') && is_admin())) {
    json_error('Administrator only', 403);
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    json_error('No user in session', 401);
}

$ip = function_exists('client_ip') ? client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
$ok = http_enc_record_ack($userId, (string) $ip);

if (!$ok) {
    json_error('Could not record acknowledgment', 500);
}

audit_log(
    'security',
    'acknowledge',
    'http_encryption',
    $userId,
    'Administrator acknowledged operating without HTTPS encryption',
    ['next_reminder_days' => http_enc_ttl_days()]
);

json_response(['success' => true, 'next_reminder_days' => http_enc_ttl_days()]);
