<?php
/**
 * Shared "no empty response body" harness for JSON API endpoints.
 *
 * Problem the beta testers keep hitting (issues #27, #28, #32, and the
 * 2026-07-28 mesh-bridge delete): an endpoint fatals somewhere deep — a bad
 * require, a MySQL "server has gone away", a TypeError that `catch (Exception)`
 * cannot reach — and PHP terminates with an empty response body. The browser
 * then shows:
 *
 *     Unexpected end of JSON input
 *     Failed to execute 'json' on 'Response': Unexpected end of JSON input
 *
 * ... which is unhelpful to both the operator (no clue what's wrong) and the
 * maintainer (no error surfaced to the client).
 *
 * 2026-07-28: the shutdown handler that used to live here has moved into
 * inc/api_guard.php, which is the canonical implementation and is now wired
 * into every API bootstrap (api/auth.php, api/external/v1/_auth.php, and the
 * handful of bearer-token endpoints that bypass auth.php). api_guard adds what
 * this file lacked:
 *
 *   * set_exception_handler() — catches uncaught Error/TypeError, which never
 *     produce the E_ERROR this file's shutdown hook was waiting for when an
 *     exception handler is installed;
 *   * a double-emit check — the old handler echoed JSON even when headers had
 *     already been sent, which could append a second document to a complete
 *     response or corrupt a CSV/SSE body;
 *   * an opaque reference id tying the client's message to the error log.
 *
 * This file is kept as a thin alias so the endpoints that already require it
 * (api/chat.php, api/location.php) keep working and pick up the improvements.
 * New code should require inc/api_guard.php directly.
 *
 * Use:
 *   require_once __DIR__ . '/../inc/json-safe.php';
 *   // ... then the endpoint's normal auth / rbac / business logic.
 *
 * Safe to include multiple times.
 */

require_once __DIR__ . '/api_guard.php';

if (!function_exists('_json_safe_installed')) {
    // display_errors off so a WARN in a downstream include cannot leak HTML
    // into the JSON stream. (Every endpoint does this too; harmless to repeat.)
    ini_set('display_errors', '0');
    error_reporting(E_ALL);

    function _json_safe_installed(): bool { return true; }
}

api_guard_install();
