<?php
/**
 * Endpoint probe for tests/test_gh25_soft_deleted_incidents.php.
 *
 * Runs ONE endpoint, in its own process, and prints exactly what that
 * endpoint wrote. A separate process per call is not fastidiousness:
 * every endpoint here finishes through json_response(), which exits, so
 * two calls cannot share an interpreter.
 *
 * The point of driving the real files rather than re-running a copy of
 * their SQL is that the board query is assembled as
 * "SELECT … {$where} {$group_filter} …" — the soft-delete term lives in
 * a different PHP string from the SELECT. A test holding its own copy of
 * the query would assert against a statement that exists nowhere, and
 * would keep passing if the endpoint's real WHERE lost the term. This
 * is the same discipline CLAUDE.md records for `assigns.rec_facility_id`
 * and `un_status.extra_data_target`: exercise the production path, not a
 * hand-built stand-in.
 *
 * Session auth is satisfied without touching anybody's credentials — a
 * session file is written directly and its id handed over in $_COOKIE.
 * `_sm_tracked` is deliberately NOT set, because sm_is_session_valid()
 * treats an absent active_sessions row as valid only for a session that
 * was never tracked (see inc/session-manager.php, Phase 73aa).
 *
 * File name starts with `_` so tools/test_all.php, which globs
 * test_*.php, does not try to run it as a test.
 *
 * Usage:  php tests/_gh25_endpoint_probe.php <mode> [args...]
 *   board                       api/incidents.php?func=0
 *   search   <term>             api/incidents.php?search=<term>
 *   waste                       api/wastebasket.php?type=ticket
 *   ext_list   <bearer>         api/external/v1/incidents.php
 *   ext_detail <bearer> <id>    api/external/v1/incidents.php?id=<id>
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/tests/_test_admin.php';

$mode = $argv[1] ?? '';

/** Attach an authenticated desktop session for the install's real admin. */
function gh25_attach_session(): void {
    $dir = sys_get_temp_dir() . '/newui_gh25_sess';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    session_save_path($dir);
    $sid = 'gh25' . bin2hex(random_bytes(8));
    session_id($sid);
    session_start();
    $_SESSION['user_id']  = test_admin_user_id();
    $_SESSION['username'] = 'gh25-probe';
    session_write_close();

    $_COOKIE[session_name()] = $sid;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'gh25-probe';
}

switch ($mode) {
    case 'board':
        gh25_attach_session();
        $_GET = ['func' => '0'];
        include $root . '/api/incidents.php';
        break;

    case 'search':
        gh25_attach_session();
        $_GET = ['search' => (string) ($argv[2] ?? '')];
        include $root . '/api/incidents.php';
        break;

    case 'waste':
        gh25_attach_session();
        $_GET = ['type' => 'ticket'];
        include $root . '/api/wastebasket.php';
        break;

    case 'ext_list':
        $_SERVER['REQUEST_METHOD']    = 'GET';
        $_SERVER['REMOTE_ADDR']       = '127.0.0.1';
        // The External API refuses plaintext by default
        // (external_api_require_tls). Present as TLS so the probe
        // reaches the QUERY rather than being turned away at the edge —
        // an https_required refusal is an error too, and would have let
        // "the deleted incident was not returned" pass for a reason that
        // has nothing to do with the fix.
        $_SERVER['HTTPS']             = 'on';
        $_SERVER['REQUEST_SCHEME']    = 'https';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string) ($argv[2] ?? '');
        $_GET = ['limit' => '200'];
        include $root . '/api/external/v1/incidents.php';
        break;

    case 'ext_detail':
        $_SERVER['REQUEST_METHOD']    = 'GET';
        $_SERVER['REMOTE_ADDR']       = '127.0.0.1';
        // The External API refuses plaintext by default
        // (external_api_require_tls). Present as TLS so the probe
        // reaches the QUERY rather than being turned away at the edge —
        // an https_required refusal is an error too, and would have let
        // "the deleted incident was not returned" pass for a reason that
        // has nothing to do with the fix.
        $_SERVER['HTTPS']             = 'on';
        $_SERVER['REQUEST_SCHEME']    = 'https';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string) ($argv[2] ?? '');
        $_GET = ['id' => (string) ($argv[3] ?? '0')];
        include $root . '/api/external/v1/incidents.php';
        break;

    default:
        fwrite(STDERR, "unknown probe mode: {$mode}\n");
        exit(2);
}
