<?php
/**
 * Endpoint probe for tests/test_soft_delete_sweep.php — the issue #25
 * follow-up sweep's regression coverage for read sites BEYOND the two
 * fixed in commit 1502157 (which tests/_gh25_endpoint_probe.php already
 * covers).
 *
 * Same discipline as that probe and its docblock: driven through the REAL
 * endpoint files in a subprocess (each finishes via json_response(), which
 * exits, so one call = one process), not a hand-copied query. See
 * tests/_gh25_endpoint_probe.php for the full rationale.
 *
 * Usage:  php tests/_soft_delete_sweep_probe.php <mode> [args...]
 *   detail   <id>            api/incident-detail.php?id=<id>
 *   list                     api/incident-list.php (default filters)
 *   search   <term>          api/incident-search.php?q=<term>
 *   callboard_board          api/incidents.php?func=0 (dispatch board — the
 *                            already-fixed board, reused here as a control)
 *   callboard_wall           api/callboard.php (the WALL-DISPLAY board —
 *                            a separate endpoint, same class of bug)
 *   stats                    api/statistics.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/tests/_test_admin.php';

$mode = $argv[1] ?? '';

/** Attach an authenticated desktop session for the install's real admin. */
function sds_attach_session(): void {
    $dir = sys_get_temp_dir() . '/newui_sds_sess';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    session_save_path($dir);
    $sid = 'sds' . bin2hex(random_bytes(8));
    session_id($sid);
    session_start();
    $_SESSION['user_id']  = test_admin_user_id();
    $_SESSION['username'] = 'sds-probe';
    session_write_close();

    $_COOKIE[session_name()] = $sid;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'sds-probe';
}

switch ($mode) {
    case 'detail':
        sds_attach_session();
        $_GET = ['id' => (string) ($argv[2] ?? '0')];
        include $root . '/api/incident-detail.php';
        break;

    case 'list':
        sds_attach_session();
        $_GET = ['limit' => '200'];
        include $root . '/api/incident-list.php';
        break;

    case 'search':
        sds_attach_session();
        $_GET = ['q' => (string) ($argv[2] ?? '')];
        include $root . '/api/incident-search.php';
        break;

    case 'callboard_board':
        sds_attach_session();
        $_GET = ['func' => '0'];
        include $root . '/api/incidents.php';
        break;

    case 'callboard_wall':
        sds_attach_session();
        $_GET = [];
        include $root . '/api/callboard.php';
        break;

    case 'stats':
        sds_attach_session();
        $_GET = [];
        include $root . '/api/statistics.php';
        break;

    default:
        fwrite(STDERR, "unknown probe mode: {$mode}\n");
        exit(2);
}
