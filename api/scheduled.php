<?php
/**
 * NewUI v4.0 API - Scheduled Ticket Count
 *
 * GET /api/scheduled.php
 *
 * Returns count of future scheduled tickets (beyond booking window).
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$booking_hrs = (int) (get_variable('booking_hrs') ?: 24);

// User group filtering — admins (level 0,1) see all
$user_groups = $_SESSION['user_groups'] ?? [];
$is_admin = is_admin();
$group_filter = '';
$params = [$booking_hrs];
if (!$is_admin && !empty($user_groups)) {
    $placeholders = implode(',', array_fill(0, count($user_groups), '?'));
    $group_filter = " AND `a`.`group` IN ({$placeholders})";
    $params = array_merge($params, $user_groups);
} elseif (!$is_admin) {
    $group_filter = " AND 1=0";
}

// Soft-delete sweep (issue #25 follow-up) — a soft-deleted scheduled
// incident must not count toward the dashboard's scheduled-count widget.
$sql = "SELECT COUNT(DISTINCT `t`.`id`) AS `cnt`
FROM `{$prefix}ticket` `t`
LEFT JOIN `{$prefix}allocates` `a` ON `t`.`id` = `a`.`resource_id` AND `a`.`type` = 1
WHERE `t`.`status` = 3
  AND `t`.`booked_date` >= DATE_ADD(NOW(), INTERVAL ? HOUR)
  AND (`t`.`deleted_at` IS NULL OR `t`.`deleted_at` = '0000-00-00 00:00:00')
  {$group_filter}";

$count = (int) db_fetch_value($sql, $params);

json_response([
    'scheduled_count' => $count,
]);
