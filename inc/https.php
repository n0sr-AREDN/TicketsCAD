<?php
/**
 * NewUI v4 — Canonical HTTPS/TLS detection.
 *
 * Reported privately 2026-08-02 by Ron Jones (GitHub @rjonesbsink).
 *
 * ── Why this file exists ────────────────────────────────────────────
 * Eleven-plus places each re-derived "am I on HTTPS?" inline, and they
 * did not agree. Two ways to get it wrong were live in the tree:
 *
 *   1. `empty($_SERVER['HTTPS'])` as the whole test. IIS sets HTTPS to
 *      the STRING "off" on a plain-HTTP request rather than leaving it
 *      unset, and `empty("off")` is FALSE. So on IIS that reads as
 *      "HTTPS is present" — backwards. This defeated the external
 *      API's TLS gate (api/external/v1/_auth.php): with
 *      external_api_require_tls=1 a plain-HTTP request carrying a valid
 *      bearer token returned 200 and a full incident list instead of
 *      426. Apache/nginx leave HTTPS unset, so they were not affected
 *      by THIS variant.
 *
 *   2. Trusting X-Forwarded-Proto unconditionally. That header is
 *      client-supplied on any request that did not actually pass
 *      through the operator's proxy, so `curl -H 'X-Forwarded-Proto:
 *      https'` over plain HTTP defeated the same gate on EVERY
 *      platform, Apache and nginx included.
 *
 * ── The two functions, and how to choose ────────────────────────────
 * They differ ONLY in whether the forwarded-protocol headers are
 * believed. Choosing wrong is the failure mode this file is meant to
 * prevent, so the rule is short:
 *
 *   is_https()          "Which scheme should I SPEAK to this client?"
 *                       Believes X-Forwarded-Proto from anyone.
 *                       Use for: building URLs (QR codes, feed self
 *                       links, device config), display/status, and
 *                       cookie `secure` flags.
 *                       Safe to spoof-believe: a client who lies about
 *                       its own scheme only affects its own response —
 *                       it gets an https:// URL it asked for, or a
 *                       Secure cookie its own browser then declines to
 *                       send back. Self-harm, not privilege.
 *
 *   is_https_verified() "Is this connection PROVABLY TLS?"
 *                       Believes forwarded headers only when the peer
 *                       (REMOTE_ADDR) is in the trusted_proxies
 *                       allow-list — the same list client_ip() already
 *                       uses for X-Forwarded-For.
 *                       Use for: any control that GRANTS OR REFUSES
 *                       something on the strength of the answer.
 *                       Currently the external API TLS gate.
 *
 * A gate built on is_https() is not a gate: the caller it is meant to
 * stop is exactly the caller who can set the header.
 *
 * ── Evidence, and who can forge it ──────────────────────────────────
 *   $_SERVER['HTTPS']            web server         both believe
 *   $_SERVER['REQUEST_SCHEME']   web server         both believe
 *   $_SERVER['SERVER_PORT']==443 the TCP socket     both believe
 *   X-Forwarded-Proto            CLIENT-CONTROLLED  verified: proxy only
 *   X-Forwarded-SSL              CLIENT-CONTROLLED  verified: proxy only
 *
 * Operators behind Cloudflare, Nginx Proxy Manager, IIS ARR, or any
 * other TLS-terminating proxy must list that proxy in the
 * `trusted_proxies` setting for is_https_verified() to honour its
 * headers. Default is 127.0.0.1,::1, which covers the same-host case.
 * When the gate refuses a request whose header claimed https, it says
 * so explicitly rather than failing silently — see _auth.php.
 *
 * This file has NO dependencies and is safe to require from config.php
 * before the database layer is loaded.
 */

if (!function_exists('is_https')) {

/**
 * Server-generated TLS evidence. Cannot be forged by a client, so both
 * is_https() and is_https_verified() honour it.
 */
function _https_server_evidence(): bool
{
    // Values of $_SERVER['HTTPS'] that mean "not TLS". IIS uses "off";
    // some CGI/FastCGI wrappers pass an empty string or "0".
    static $falsey = ['', 'off', '0', 'false', 'no'];

    // $_SERVER['HTTPS'] — present and not one of the falsey spellings.
    // NB: a bare empty()/!empty() test on this value is the original
    // bug; "off" is a non-empty string.
    if (isset($_SERVER['HTTPS'])) {
        $v = strtolower(trim((string) $_SERVER['HTTPS']));
        if (!in_array($v, $falsey, true)) {
            return true;
        }
    }

    // REQUEST_SCHEME — set by Apache 2.4+ and nginx from the actual
    // connection, not from a request header.
    if (isset($_SERVER['REQUEST_SCHEME'])
        && strtolower(trim((string) $_SERVER['REQUEST_SCHEME'])) === 'https') {
        return true;
    }

    // The socket landed on 443.
    if (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }

    return false;
}

/**
 * Forwarded-protocol headers. CLIENT-CONTROLLED unless the request
 * demonstrably arrived via the operator's proxy.
 */
function _https_forwarded_evidence(): bool
{
    // X-Forwarded-Proto may be a comma-separated chain
    // ("https, http"); the LEFTMOST entry is what the original client
    // spoke to the edge.
    $xfp = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    if ($xfp !== '') {
        $first = strtolower(trim(explode(',', (string) $xfp)[0]));
        if ($first === 'https') return true;
    }

    // X-Forwarded-SSL: on  — IIS ARR and some older proxies.
    $xfs = $_SERVER['HTTP_X_FORWARDED_SSL'] ?? '';
    if ($xfs !== '' && strtolower(trim((string) $xfs)) === 'on') {
        return true;
    }

    return false;
}

/**
 * Is REMOTE_ADDR one of the operator's trusted proxies?
 *
 * Delegates to the allow-list client_ip() already maintains so there is
 * ONE list to configure. Falls back to the same safe default that file
 * uses when the settings table is not reachable (e.g. called from
 * config.php before the DB layer is up) — never to "trust everyone".
 */
function _https_peer_is_trusted_proxy(): bool
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($remote === '') return false;

    // Preferred: the configured trusted_proxies list (supports CIDR).
    // Guard on db_fetch_value too — _client_ip_is_trusted_proxy() only
    // catches Exception, and calling an undefined function raises Error.
    if (function_exists('_client_ip_is_trusted_proxy') && function_exists('db_fetch_value')) {
        try {
            return _client_ip_is_trusted_proxy($remote);
        } catch (Throwable $e) {
            // fall through to the conservative default
        }
    }

    // Conservative default, matching inc/client-ip.php.
    return in_array(strtolower($remote), ['127.0.0.1', '::1'], true);
}

/**
 * Best-effort scheme for URL building, display and cookie flags.
 *
 * Believes X-Forwarded-Proto from any peer. Do NOT use to gate access —
 * use is_https_verified().
 */
function is_https(): bool
{
    return _https_server_evidence() || _https_forwarded_evidence();
}

/**
 * Provable TLS, for security gates.
 *
 * Forwarded headers count only from a trusted proxy.
 */
function is_https_verified(): bool
{
    if (_https_server_evidence()) return true;
    return _https_forwarded_evidence() && _https_peer_is_trusted_proxy();
}

/**
 * Why is_https_verified() said no — so a refusal can explain itself
 * instead of leaving an operator behind a real proxy guessing.
 *
 * @return string 'tls'                genuinely TLS (no refusal)
 *                'untrusted_proxy'    a header claimed https but the
 *                                     peer is not in trusted_proxies
 *                'plaintext'          no TLS evidence at all
 */
function https_verification_failure_reason(): string
{
    if (is_https_verified()) return 'tls';
    if (_https_forwarded_evidence()) return 'untrusted_proxy';
    return 'plaintext';
}

} // end if (!function_exists('is_https'))
