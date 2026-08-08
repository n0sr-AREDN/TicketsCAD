<?php
/**
 * NewUI v4.0 API - Audit Log Viewer
 *
 * GET /api/audit-log.php
 *
 * Query params:
 *   category    - Filter by category (auth, config, personnel, incident, etc.)
 *   activity    - Filter by activity (create, update, delete, login, etc.)
 *   severity    - Filter by min severity (0-5)
 *   user        - Filter by user_name (partial match)
 *   q           - Text search on summary
 *   date_from   - ISO date (YYYY-MM-DD)
 *   date_to     - ISO date (YYYY-MM-DD)
 *   sort        - Column to sort by (default: event_time)
 *   order       - asc/desc (default: desc)
 *   limit       - Page size (default 50, max 200)
 *   offset      - Pagination offset
 *
 * Returns: { entries: [...], total: N, limit: N, offset: N, categories: [...], activities: [...] }
 *
 * GH#37 (Chris Byrd, 2026-08-08): "I would be glad to export the log but I
 * do not see a function to do that." Confirmed there wasn't one -- this
 * endpoint was GET/browse-only. Added:
 *
 * GET /api/audit-log.php?action=export&format=csv|json
 *   Same filter params as above (category/activity/severity/user/q/
 *   date_from/date_to), NO pagination -- streams every matching row (capped
 *   at 50,000, see AUDIT_EXPORT_MAX_ROWS) as a file download. Admin-only:
 *   deliberately stricter than the action.view_audit permission that gates
 *   paginated browsing above, matching the precedent already set by
 *   api/places.php (its action=export also requires the more-privileged
 *   permission, not the read-only one list/detail/search use) -- pulling
 *   the ENTIRE audit trail in one shot is a bulk-extraction capability, not
 *   a browsing convenience, and least-privilege treats them differently.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/audit.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('GET required', 405);
}

// Require admin, or an explicit grant of action.view_audit.
// 2026-07-29 — was `$_SESSION['level'] > 1` only, so a Super Admin whose
// legacy user.level happened to be 4 was refused while action.view_audit
// (which Org Admin holds) was never consulted.
// Phase 128 — the `|| $userLevel <= 1` fallback that briefly replaced it
// is gone too: login now refuses outright on an unmigrated install, so a
// level fallback here could only ever grant access from stale data.
require_once __DIR__ . '/../inc/rbac.php';
if (!is_admin() && !rbac_can('action.view_audit')) {
    json_error('Admin access required', 403);
}

$action = $_GET['action'] ?? 'list';

// GH#37 — export is admin-only, stricter than the view_audit permission
// that gates browsing above. A role holding only action.view_audit (e.g.
// Org Admin) can page through the log but not pull the whole thing at once.
if ($action === 'export' && !is_admin()) {
    json_error('Admin access required for export', 403);
}

// Ensure table exists
audit_ensure_table();

$prefix = $GLOBALS['db_prefix'] ?? '';
$table  = db_table('newui_audit_log');

// ── Parse filters ──
$category  = trim($_GET['category'] ?? '');
$activity  = trim($_GET['activity'] ?? '');
$severity  = $_GET['severity'] ?? '';
$userFilter = trim($_GET['user'] ?? '');
$q         = trim($_GET['q'] ?? '');
$dateFrom  = trim($_GET['date_from'] ?? '');
$dateTo    = trim($_GET['date_to'] ?? '');

// ── Sort ──
$sortMap = [
    'event_time'  => 'event_time',
    'category'    => 'category',
    'activity'    => 'activity',
    'severity'    => 'severity',
    'user_name'   => 'user_name',
    'summary'     => 'summary',
    'target_type' => 'target_type',
];
$sort  = isset($sortMap[$_GET['sort'] ?? '']) ? $sortMap[$_GET['sort']] : 'event_time';
$order = (isset($_GET['order']) && strtolower($_GET['order']) === 'asc') ? 'ASC' : 'DESC';

// ── Pagination ──
$limit  = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

// ── Build WHERE ──
$where  = [];
$params = [];

if ($category !== '') {
    $where[]  = '`category` = ?';
    $params[] = $category;
}
if ($activity !== '') {
    $where[]  = '`activity` = ?';
    $params[] = $activity;
}
if ($severity !== '' && is_numeric($severity)) {
    $where[]  = '`severity` >= ?';
    $params[] = (int) $severity;
}
if ($userFilter !== '') {
    $where[]  = '`user_name` LIKE ?';
    $params[] = '%' . $userFilter . '%';
}
if ($q !== '') {
    $where[]  = '`summary` LIKE ?';
    $params[] = '%' . $q . '%';
}
if ($dateFrom !== '') {
    $where[]  = '`event_time` >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[]  = '`event_time` <= ?';
    $params[] = $dateTo . ' 23:59:59';
}

$whereSQL = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Export (GH#37) ──────────────────────────────────────────────────────
// Reuses the exact same $where/$params the list view built above, so an
// export always matches whatever the admin is currently looking at — no
// separate filter-parsing path to drift out of sync with the browse view.
if ($action === 'export') {
    $format = strtolower((string) ($_GET['format'] ?? 'csv'));
    if (!in_array($format, ['csv', 'json'], true)) {
        json_error('format must be csv or json');
    }

    // A row cap, not true pagination — this is a bulk "give me what I'm
    // looking at" export, not a paged browse. 50,000 rows is generous for
    // any realistic audit log slice and bounds worst-case memory/time the
    // same way places.php's import caps rows for the same reason.
    $AUDIT_EXPORT_MAX_ROWS = 50000;

    try {
        $rows = db_fetch_all(
            "SELECT `id`, `event_time`, `user_id`, `user_name`, `ip_address`,
                    `category`, `activity`, `severity`, `target_type`, `target_id`,
                    `summary`, `details`
             FROM {$table}
             {$whereSQL}
             ORDER BY `event_time` DESC
             LIMIT ?",
            array_merge($params, [$AUDIT_EXPORT_MAX_ROWS])
        );
    } catch (Exception $e) {
        ini_set('display_errors', $prevDisplay);
        json_error('Query error: ' . $e->getMessage(), 500);
    }

    $stamp = date('Ymd-His');
    if ($format === 'json') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="audit-log-' . $stamp . '.json"');
        // details is stored as a JSON string; decode so the export carries
        // real nested JSON rather than a JSON string escaped inside JSON.
        foreach ($rows as $i => $r) {
            if (!empty($r['details'])) {
                $decoded = json_decode($r['details'], true);
                $rows[$i]['details'] = $decoded !== null ? $decoded : $r['details'];
            }
        }
        echo json_encode(['entries' => $rows, 'count' => count($rows)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit-log-' . $stamp . '.csv"');
    $out = fopen('php://output', 'w');
    // BOM so Excel opens the UTF-8 file correctly out of the box (matches
    // api/places.php's export — the one other CSV export in this app).
    fwrite($out, "\xEF\xBB\xBF");
    $cols = ['id', 'event_time', 'user_id', 'user_name', 'ip_address',
             'category', 'activity', 'severity', 'target_type', 'target_id', 'summary', 'details'];
    // PHP 8.4 deprecates the implicit escape param — pass '' explicitly.
    fputcsv($out, $cols, ',', '"', '');
    foreach ($rows as $r) {
        $line = [];
        foreach ($cols as $c) {
            $v = $r[$c] ?? '';
            // details is a JSON string already — keep it as one CSV field
            // rather than exploding it into unpredictable extra columns.
            $line[] = is_array($v) ? json_encode($v) : $v;
        }
        fputcsv($out, $line, ',', '"', '');
    }
    fclose($out);
    exit;
}

// ── Count total ──
try {
    $total = (int) db_fetch_value(
        "SELECT COUNT(*) FROM {$table} {$whereSQL}",
        $params
    );
} catch (Exception $e) {
    ini_set('display_errors', $prevDisplay);
    json_error('Query error: ' . $e->getMessage(), 500);
}

// ── Fetch entries ──
try {
    $entries = db_fetch_all(
        "SELECT `id`, `event_time`, `user_id`, `user_name`, `ip_address`,
                `category`, `activity`, `severity`, `target_type`, `target_id`,
                `summary`, `details`
         FROM {$table}
         {$whereSQL}
         ORDER BY `{$sort}` {$order}
         LIMIT ? OFFSET ?",
        array_merge($params, [$limit, $offset])
    );
} catch (Exception $e) {
    ini_set('display_errors', $prevDisplay);
    json_error('Query error: ' . $e->getMessage(), 500);
}

// Parse JSON details field
for ($i = 0; $i < count($entries); $i++) {
    if (!empty($entries[$i]['details'])) {
        $entries[$i]['details'] = json_decode($entries[$i]['details'], true);
    }
    $entries[$i]['severity'] = (int) $entries[$i]['severity'];
}

// GH #86 — attach the configured case/incident number to ticket targets so the
// audit viewer shows BOTH the case number AND the raw DB id (a beta tester: "reference
// both for troubleshooting"). One batched lookup — no N+1. Non-fatal.
try {
    $auditPrefix = $GLOBALS['db_prefix'] ?? '';
    $ticketIds = [];
    foreach ($entries as $e) {
        if (in_array(($e['target_type'] ?? ''), ['ticket', 'incident'], true) && (int) ($e['target_id'] ?? 0) > 0) {
            $ticketIds[(int) $e['target_id']] = true;
        }
    }
    if (!empty($ticketIds)) {
        $ids = array_keys($ticketIds);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $numById = [];
        foreach (db_fetch_all("SELECT `id`, `incident_number` FROM `{$auditPrefix}ticket` WHERE `id` IN ($ph)", $ids) as $t) {
            $n = trim((string) ($t['incident_number'] ?? ''));
            if ($n !== '') $numById[(int) $t['id']] = $n;
        }
        for ($i = 0; $i < count($entries); $i++) {
            $tid = (int) ($entries[$i]['target_id'] ?? 0);
            if (in_array(($entries[$i]['target_type'] ?? ''), ['ticket', 'incident'], true) && isset($numById[$tid])) {
                $entries[$i]['target_incident_number'] = $numById[$tid];
            }
        }
    }
} catch (Exception $e) { /* non-fatal — viewer falls back to the raw DB id */ }

// ── Fetch distinct categories and activities for filter dropdowns ──
$categories = [];
$activities = [];
try {
    $catRows = db_fetch_all("SELECT DISTINCT `category` FROM {$table} ORDER BY `category`");
    foreach ($catRows as $r) { $categories[] = $r['category']; }

    $actRows = db_fetch_all("SELECT DISTINCT `activity` FROM {$table} ORDER BY `activity`");
    foreach ($actRows as $r) { $activities[] = $r['activity']; }
} catch (Exception $e) {
    // non-fatal
}

ini_set('display_errors', $prevDisplay);
json_response([
    'entries'    => $entries,
    'total'      => $total,
    'limit'      => $limit,
    'offset'     => $offset,
    'categories' => $categories,
    'activities' => $activities,
]);
