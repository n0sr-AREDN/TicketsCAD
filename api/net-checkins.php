<?php
/**
 * NewUI v4.0 API — Net-Control Check-Ins (Phase 131)
 *
 * The per-operator check-in list behind the `/net` command and the floating
 * widget on the situational display.
 *
 * GET  ?action=list[&history=N][&order=arrival|priority]
 *          the operator's waiting entries, plus up to N history rows,
 *          plus the resolved config the widget needs
 * GET  ?action=entry&id=N        one entry (drives the new-incident prefill)
 * GET  ?action=incidents[&limit=N]
 *          open incidents sorted by last-updated, for the [a] append picker
 *
 * POST (JSON body, csrf_token required)
 *   action=add       {raw:"1234 tornado / 3344 hail"} | {entries:[{identifier,note}]}
 *   action=update    {id, identifier?, note?}
 *   action=delete    {id}
 *   action=restore   {id}
 *   action=work      {id, ticket_id?}
 *   action=priority  {id, priority}
 *
 * SECURITY — per-operator isolation. The owner is ALWAYS the session user,
 * read here and nowhere else; there is no user-id request field, so the client
 * has nothing to tamper with. Every read filters `user_id`, every mutation is
 * `WHERE id = ? AND user_id = ?`, so another operator's row id matches zero
 * rows and returns "not found" — identical to an id that does not exist. No
 * enumeration oracle, no IDOR.
 *
 * RBAC: action.net_checkin.  CSRF: required on every POST.
 */

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/net-checkins.php';

try {
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        json_error('Not signed in', 401);
    }
    if (!rbac_can('action.net_checkin')) {
        json_error('Insufficient permissions: net-control check-ins', 403);
    }

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        net_handle_get($userId);
    } elseif ($method === 'POST') {
        net_handle_post($userId);
    } else {
        json_error('Method not allowed', 405);
    }
} catch (Throwable $e) {
    error_log('[api/net-checkins] ' . $e->getMessage());
    json_error('Request failed', 500);
} finally {
    ini_set('display_errors', $prevDisplay);
}

// ── GET ───────────────────────────────────────────────────────────────────

function net_handle_get(int $userId): void
{
    $action = (string) ($_GET['action'] ?? 'list');

    if ($action === 'entry') {
        $entry = net_get_entry((int) ($_GET['id'] ?? 0), $userId);
        if (!$entry) json_error('Check-in not found', 404);
        json_response(['entry' => $entry]);
    }

    if ($action === 'incidents') {
        json_response(['incidents' => net_recent_incidents($userId, (int) ($_GET['limit'] ?? 25))]);
    }

    if ($action !== 'list') {
        json_error('Unknown action');
    }

    $cfg = net_config();

    // Housekeeping runs on the read path, scoped to this operator and bounded.
    // Deliberately not a cron job: neither training nor your deployment has a cron
    // daemon, and a /etc/cron.d drop-in there fails completely silently
    // (CLAUDE.md, 2026-07-29). Nothing here needs to happen unattended.
    net_prune($userId, $cfg['retention_days']);

    $history = isset($_GET['history']) && $_GET['history'] !== ''
        ? max(0, (int) $_GET['history'])
        : $cfg['history_count'];

    $order = (string) ($_GET['order'] ?? '');

    json_response([
        'entries'       => net_list($userId, $history, $order),
        'pending_count' => net_pending_count($userId),
        // The widget reads exactly these keys — see net_shape_row() and this
        // map for the actual output contract. Never guess key names.
        'config'        => [
            'history_count' => $cfg['history_count'],
            'autofocus'     => $cfg['autofocus'],
            'order'         => $cfg['order'],
            'separator'     => $cfg['separator'],
        ],
    ]);
}

/**
 * Open incidents, most recently updated first — the [a] append picker.
 *
 * Ordering is owned here rather than borrowed from another endpoint so the
 * widget's contract cannot drift out from under it.
 */
function net_recent_incidents(int $userId, int $limit): array
{
    $limit = max(1, min(100, $limit));
    $prefix = $GLOBALS['db_prefix'] ?? '';

    try {
        $rows = db_fetch_all(
            "SELECT t.`id`, t.`scope`, t.`street`, t.`city`, t.`severity`,
                    t.`updated`, t.`date`, ty.`type` AS type_name
               FROM `{$prefix}ticket` t
          LEFT JOIN `{$prefix}in_types` ty ON ty.`id` = t.`in_types_id`
              WHERE t.`status` = 2
                AND (t.`deleted_at` IS NULL)
           ORDER BY t.`updated` DESC, t.`id` DESC
              LIMIT " . (int) $limit);
    } catch (Exception $e) {
        // `ticket.deleted_at` is NOT in base_schema.sql — it arrives with the
        // wastebasket migration, so an install that has not run it does not
        // have the column. Fall back rather than showing the operator an empty
        // picker in the middle of a net.
        try {
            $rows = db_fetch_all(
                "SELECT t.`id`, t.`scope`, t.`street`, t.`city`, t.`severity`,
                        t.`updated`, t.`date`, ty.`type` AS type_name
                   FROM `{$prefix}ticket` t
              LEFT JOIN `{$prefix}in_types` ty ON ty.`id` = t.`in_types_id`
                  WHERE t.`status` = 2
               ORDER BY t.`updated` DESC, t.`id` DESC
                  LIMIT " . (int) $limit);
        } catch (Exception $e2) {
            error_log('[api/net-checkins] incident picker failed: ' . $e2->getMessage());
            $rows = [];
        }
    }

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'        => (int) $r['id'],
            'scope'     => (string) ($r['scope'] ?? ''),
            'type_name' => (string) ($r['type_name'] ?? ''),
            'street'    => (string) ($r['street'] ?? ''),
            'city'      => (string) ($r['city'] ?? ''),
            'severity'  => (int) ($r['severity'] ?? 0),
            'updated'   => (string) ($r['updated'] ?? $r['date'] ?? ''),
        ];
    }
    return $out;
}

// ── POST ──────────────────────────────────────────────────────────────────

function net_handle_post(int $userId): void
{
    // $_POST is empty for a JSON body — PHP only populates it for form
    // encodings (CLAUDE.md).
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        json_error('Invalid JSON body');
    }
    if (empty($input['csrf_token']) || !csrf_verify((string) $input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }

    $action = (string) ($input['action'] ?? '');
    $id     = (int) ($input['id'] ?? 0);

    switch ($action) {
        case 'add':
            $cfg = net_config();
            if (isset($input['raw'])) {
                // The raw string the operator typed. The server owns the parse.
                $entries = net_parse_checkins(
                    (string) $input['raw'], $cfg['separator'], $cfg['digit_guard']);
            } elseif (isset($input['entries']) && is_array($input['entries'])) {
                $entries = [];
                foreach ($input['entries'] as $e) {
                    if (!is_array($e)) continue;
                    $entries[] = [
                        'identifier' => trim((string) ($e['identifier'] ?? '')),
                        'note'       => trim((string) ($e['note'] ?? '')),
                    ];
                }
            } else {
                json_error('Nothing to add — provide raw or entries');
            }

            if (!$entries) {
                json_error('No check-ins found in that input');
            }

            $res = net_add_entries($entries, $userId);
            if ($res['added'] === 0) {
                json_error('Could not store the check-ins', 500);
            }
            json_response([
                'added'   => $res['added'],
                'ids'     => $res['ids'],
                'entries' => net_list($userId, 0),
            ]);
            // no break — json_response() exits

        case 'update':
            $identifier = array_key_exists('identifier', $input) ? (string) $input['identifier'] : null;
            $note       = array_key_exists('note', $input)       ? (string) $input['note']       : null;
            if ($identifier === null && $note === null) {
                json_error('Nothing to update');
            }
            if (!net_update_entry($id, $userId, $identifier, $note)) {
                json_error('Check-in not found', 404);
            }
            json_response(['entry' => net_get_entry($id, $userId)]);

        case 'delete':
            if (!net_set_status($id, $userId, 'deleted')) {
                json_error('Check-in not found', 404);
            }
            json_response(['entry' => net_get_entry($id, $userId)]);

        case 'restore':
            if (!net_set_status($id, $userId, 'pending')) {
                json_error('Check-in not found', 404);
            }
            json_response(['entry' => net_get_entry($id, $userId)]);

        case 'work':
            $ticketId = (int) ($input['ticket_id'] ?? 0);
            if (!net_set_status($id, $userId, 'worked', $ticketId > 0 ? $ticketId : null)) {
                json_error('Check-in not found', 404);
            }
            json_response(['entry' => net_get_entry($id, $userId)]);

        case 'priority':
            if (!net_set_priority($id, $userId, (int) ($input['priority'] ?? 0))) {
                json_error('Check-in not found', 404);
            }
            json_response(['entry' => net_get_entry($id, $userId)]);

        default:
            json_error('Unknown action');
    }
}
