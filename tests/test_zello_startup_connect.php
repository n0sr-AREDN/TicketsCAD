<?php
/**
 * Issue #114 (Eric 2026-08-03): the Zello proxy should hold a persistent
 * upstream connection instead of waiting for the first dispatcher to open
 * the console — "we should have a proper archive of communications so a
 * dispatcher can login and see the historical transmissions, and ... we can
 * broadcast weather alerts as soon as we're aware of them."
 *
 * Before this, ZelloProxyApp only called its private connectUpstream() from
 * two places: the browser's `connect` command, and handleAuth() the moment a
 * client authenticates. Anything that happened before the first login of the
 * day was never received at all — not archived-and-hidden, genuinely never
 * received, because nothing was connected to receive it.
 *
 * The fix is ZelloProxyApp::connectOnStartup(), a public wrapper the daemon
 * entrypoint (zello-proxy.php) calls once via $loop->futureTick() right
 * before $loop->run(). This file asserts:
 *   1. connectOnStartup() is public and reachable without a browser client.
 *   2. With no Zello credentials configured, it no-ops safely (matches
 *      connectUpstream()'s existing credential guard — same behaviour as
 *      today, just reachable without a login triggering the attempt).
 *   3. connectOnStartup()'s body delegates to the existing, unmodified
 *      connectUpstream() — checked at the SOURCE level (tokenized, not
 *      grepped — see the note by the assertion) rather than by calling it
 *      with fake credentials. connectUpstream() constructs a real
 *      WebSocketConnector and calls ->connect() on it; on this Windows dev
 *      box that hung the test process for over two minutes even against an
 *      unroutable "wss://zello.invalid/ws" URL and even without ever
 *      calling $loop->run() — confirmed live, the php.exe process had to be
 *      killed. Exercising that path in a unit test is the same class of
 *      footgun as the Windows proc_open pipe deadlocks fixed this session
 *      (see tests/test_proc_open_pipe_deadlock.php's docblock): don't
 *      invoke it here. connectUpstream()'s own guard/connect logic is
 *      pre-existing and unchanged by this issue — only the NEW entry point
 *      (connectOnStartup) needs coverage, and delegation is provable without
 *      touching the network.
 *   4. zello-proxy.php's bootstrap actually calls connectOnStartup() —
 *      wiring the daemon entrypoint to the method is the whole point; a
 *      test that only checks the method exists would miss a regression
 *      where the call got deleted from the entrypoint.
 *
 * Run: /c/xampp/8.2.4/php/php.exe tools/test_all.php   (or this file directly)
 */

require __DIR__ . '/../config.php';

if (!function_exists('plog')) {
    function plog($msg) { /* silent in tests */ }
}

// Ratchet lives in composer's vendor/ — not present on a fresh checkout
// or CI unless `composer install` ran. Skip cleanly instead of fataling.
if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
    echo "SKIP: vendor/autoload.php not found (run 'composer install' to enable Zello proxy tests)\n";
    echo "=== 0 passed, 0 failed ===\n";
    exit(0);
}
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../proxy/ZelloUpstream.php';
require __DIR__ . '/../proxy/ZelloProxyApp.php';

use NewUI\Proxy\ZelloProxyApp;

echo "=== Zello proxy startup-connect tests (issue #114) ===\n\n";
$pass = 0;
$fail = 0;

function t_ok($label, $cond, $hint = '') {
    global $pass, $fail;
    if ($cond) { echo "  [PASS] $label\n"; $pass++; }
    else       { echo "  [FAIL] $label" . ($hint !== '' ? " — $hint" : '') . "\n"; $fail++; }
}

$prefix = $GLOBALS['db_prefix'] ?? '';
$pdo    = function_exists('db') ? db() : null;
t_ok('test has a PDO handle for the proxy', $pdo instanceof PDO);

if (!($pdo instanceof PDO)) {
    echo "\n=== $pass passed, $fail failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

$loop = \React\EventLoop\Loop::get();

// ── 1 & 2. No credentials configured — must no-op, not throw ──────────────
$appNoCreds = new ZelloProxyApp($loop, ['zello_dispatch_channel' => 'DefaultCh'], $pdo, $prefix);
$ref        = new ReflectionClass($appNoCreds);

$method = $ref->hasMethod('connectOnStartup') ? $ref->getMethod('connectOnStartup') : null;
t_ok('connectOnStartup() exists', $method !== null);
t_ok('connectOnStartup() is public', $method !== null && $method->isPublic());

if ($method !== null) {
    $threw = false;
    try {
        $appNoCreds->connectOnStartup();
    } catch (\Throwable $e) {
        $threw = true;
    }
    t_ok('calling it with no Zello credentials does not throw', !$threw);

    $upstreamProp = $ref->getProperty('upstream');
    $upstreamProp->setAccessible(true);
    t_ok('…and does not create an upstream connection object (credential guard held)',
        $upstreamProp->getValue($appNoCreds) === null);
}

// ── 3. connectOnStartup() delegates to connectUpstream() — source-level ───
// Tokenize rather than grep: a substring match on "connectUpstream" would
// also match inside a comment, a different method's name, or this test's
// own docblock if it were ever copy-pasted into the source file. Walking
// the actual token stream for the method body is what the project's other
// source-level gates do (schema_audit, doc_navigation_labels) and it's the
// same reason here — prove the CALL exists, not that the string appears.
$src    = file_get_contents(__DIR__ . '/../proxy/ZelloProxyApp.php');
$tokens = token_get_all($src);
$body   = '';
$inMethod = false;
$depth    = 0;
foreach ($tokens as $tok) {
    if (is_array($tok) && $tok[0] === T_STRING && $tok[1] === 'connectOnStartup') {
        $inMethod = true;
        continue;
    }
    if ($inMethod) {
        $text = is_array($tok) ? $tok[1] : $tok;
        if ($text === '{') { $depth++; if ($depth === 1) continue; }
        if ($text === '}') { $depth--; if ($depth === 0) break; }
        if ($depth >= 1) { $body .= is_array($tok) ? $tok[1] : $tok; }
    }
}
t_ok('connectOnStartup()\'s body calls connectUpstream() (tokenized source check)',
    (bool) preg_match('/\$this\s*->\s*connectUpstream\s*\(\s*\)/', $body),
    'body was: ' . trim(preg_replace('/\s+/', ' ', $body)));

// ── 4. The daemon entrypoint actually calls it ─────────────────────────────
$entrypoint = file_get_contents(__DIR__ . '/../proxy/zello-proxy.php');
t_ok('zello-proxy.php calls $proxyApp->connectOnStartup()',
    $entrypoint !== false && strpos($entrypoint, 'connectOnStartup()') !== false,
    'the wiring from daemon startup to the method is missing');
t_ok('…scheduled via the loop (futureTick), not called before construction',
    $entrypoint !== false && preg_match('/futureTick\s*\(function[^{]*\{[^}]*connectOnStartup/s', $entrypoint) === 1);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
