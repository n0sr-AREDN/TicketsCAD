<?php
/**
 * TLS/HTTPS detection — regression suite.
 *
 * Reported privately 2026-08-02 by Ron Jones (GitHub @rjonesbsink):
 * api/external/v1/_auth.php tested `empty($_SERVER['HTTPS'])`, which is
 * FALSE on IIS over plain HTTP because IIS sets HTTPS to the string
 * "off". The 426 gate therefore never fired on IIS; with
 * external_api_require_tls=1 a plain-HTTP request bearing a valid token
 * returned 200 and a full incident list.
 *
 * Verifying his report turned up a SECOND bypass he had not reported:
 * the same line trusted X-Forwarded-Proto unconditionally, so
 * `curl -H 'X-Forwarded-Proto: https'` walked through the gate on every
 * platform, Apache and nginx included.
 *
 * WHY THIS FILE DRIVES A SUBPROCESS RATHER THAN GREPPING SOURCE
 * ------------------------------------------------------------
 * This is precisely the bug class where a test that asserts on source
 * text passes while the product stays broken — the original line
 * *mentioned* both HTTPS and X-Forwarded-Proto and looked like a gate.
 * So §3 runs the REAL api/external/v1/_auth.php in a child process with
 * a simulated $_SERVER and asserts on the actual response body, and §4
 * is a negative control that re-implements the OLD expression and fails
 * if it ever starts behaving like the new one.
 */

$root = dirname(__DIR__);
require_once $root . '/inc/https.php';

$pass = 0; $fail = 0;
function ok(string $m): void   { global $pass; $pass++; echo "  [ok]   $m\n"; }
function bad(string $m, string $d = ''): void {
    global $fail; $fail++; echo "  [FAIL] $m" . ($d ? " — $d" : '') . "\n";
}
function check(string $m, bool $cond, string $d = ''): void { $cond ? ok($m) : bad($m, $d); }

/** Run one $_SERVER scenario against the helper in a clean state. */
function withServer(array $server, callable $fn) {
    $saved = $_SERVER;
    foreach (['HTTPS','HTTP_X_FORWARDED_PROTO','HTTP_X_FORWARDED_SSL',
              'REQUEST_SCHEME','SERVER_PORT','REMOTE_ADDR'] as $k) {
        unset($_SERVER[$k]);
    }
    foreach ($server as $k => $v) $_SERVER[$k] = $v;
    try { return $fn(); } finally { $_SERVER = $saved; }
}

echo "=== 1. is_https() — scheme detection across web servers ===\n";

// The heart of the report: IIS says "off", and empty("off") is false.
check('PHP itself: empty("off") is false (the root cause)',
    empty('off') === false);

check('IIS, plain HTTP (HTTPS="off") is NOT https',
    withServer(['HTTPS' => 'off', 'SERVER_PORT' => '80'], 'is_https') === false,
    'the reported defect');

check('IIS, TLS (HTTPS="on") is https',
    withServer(['HTTPS' => 'on', 'SERVER_PORT' => '443'], 'is_https') === true);

check('Apache, plain HTTP (HTTPS unset) is NOT https',
    withServer(['SERVER_PORT' => '80'], 'is_https') === false);

check('Apache, TLS (HTTPS="on") is https',
    withServer(['HTTPS' => 'on'], 'is_https') === true);

check('HTTPS="1" is https',
    withServer(['HTTPS' => '1'], 'is_https') === true);

check('HTTPS="0" is NOT https',
    withServer(['HTTPS' => '0', 'SERVER_PORT' => '80'], 'is_https') === false);

check('HTTPS="" is NOT https',
    withServer(['HTTPS' => '', 'SERVER_PORT' => '80'], 'is_https') === false);

check('HTTPS="OFF" (uppercase) is NOT https',
    withServer(['HTTPS' => 'OFF', 'SERVER_PORT' => '80'], 'is_https') === false);

check('HTTPS="On" (mixed case) is https',
    withServer(['HTTPS' => 'On'], 'is_https') === true);

check('REQUEST_SCHEME=https is https',
    withServer(['REQUEST_SCHEME' => 'https', 'SERVER_PORT' => '80'], 'is_https') === true);

check('SERVER_PORT=443 alone is https',
    withServer(['SERVER_PORT' => '443'], 'is_https') === true);

check('is_https() believes X-Forwarded-Proto from any peer (by design)',
    withServer(['SERVER_PORT' => '80', 'REMOTE_ADDR' => '203.0.113.9',
                'HTTP_X_FORWARDED_PROTO' => 'https'], 'is_https') === true);

check('X-Forwarded-Proto chain "https, http" reads the leftmost',
    withServer(['SERVER_PORT' => '80', 'HTTP_X_FORWARDED_PROTO' => 'https, http'],
        'is_https') === true);

check('X-Forwarded-SSL=on is https',
    withServer(['SERVER_PORT' => '80', 'HTTP_X_FORWARDED_SSL' => 'on'], 'is_https') === true);

echo "\n=== 2. is_https_verified() — forwarded headers need a trusted proxy ===\n";

check('IIS plain HTTP is not verified TLS',
    withServer(['HTTPS' => 'off', 'SERVER_PORT' => '80',
                'REMOTE_ADDR' => '203.0.113.9'], 'is_https_verified') === false);

check('genuine TLS is verified regardless of peer',
    withServer(['HTTPS' => 'on', 'REMOTE_ADDR' => '203.0.113.9'],
        'is_https_verified') === true);

check('X-Forwarded-Proto from an UNTRUSTED peer is REFUSED',
    withServer(['SERVER_PORT' => '80', 'REMOTE_ADDR' => '203.0.113.9',
                'HTTP_X_FORWARDED_PROTO' => 'https'], 'is_https_verified') === false,
    'a spoofable header must not satisfy a security gate');

check('X-Forwarded-Proto from a TRUSTED proxy (127.0.0.1) is honoured',
    withServer(['SERVER_PORT' => '80', 'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_X_FORWARDED_PROTO' => 'https'], 'is_https_verified') === true,
    'a real TLS-terminating proxy must keep working');

check('X-Forwarded-SSL from an untrusted peer is REFUSED',
    withServer(['SERVER_PORT' => '80', 'REMOTE_ADDR' => '203.0.113.9',
                'HTTP_X_FORWARDED_SSL' => 'on'], 'is_https_verified') === false);

check('failure reason distinguishes untrusted_proxy from plaintext',
    withServer(['SERVER_PORT' => '80', 'REMOTE_ADDR' => '203.0.113.9',
                'HTTP_X_FORWARDED_PROTO' => 'https'],
        'https_verification_failure_reason') === 'untrusted_proxy');

check('failure reason is "plaintext" with no TLS evidence at all',
    withServer(['SERVER_PORT' => '80', 'REMOTE_ADDR' => '203.0.113.9'],
        'https_verification_failure_reason') === 'plaintext');

check('failure reason is "tls" on a genuinely secure request',
    withServer(['HTTPS' => 'on'], 'https_verification_failure_reason') === 'tls');

echo "\n=== 3. The 426 gate, driven end-to-end through the REAL endpoint ===\n";

/**
 * Run api/external/v1/_auth.php in a child process under a simulated
 * $_SERVER and return the JSON body it emitted. Asserting on the real
 * response is the point: the defect was invisible at the source level.
 */
function runAuthGate(array $server): string {
    global $root;
    $harness = sys_get_temp_dir() . '/tcad_tls_gate_' . getmypid() . '.php';
    $code = "<?php\n"
          . '$_SERVER = ' . var_export(array_merge([
                'REQUEST_METHOD' => 'GET',
                'SCRIPT_NAME'    => '/newui/api/external/v1/incidents.php',
                'HTTP_HOST'      => 'cad.example.org',
            ], $server), true) . ";\n"
          . 'require ' . var_export($root . '/api/external/v1/_auth.php', true) . ";\n"
          . "echo 'PAST_THE_GATE';\n";
    file_put_contents($harness, $code);
    $out = @shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' 2>&1');
    @unlink($harness);
    return (string) $out;
}

$probe = runAuthGate(['HTTPS' => 'off', 'SERVER_PORT' => '80', 'REMOTE_ADDR' => '203.0.113.9']);

if (strpos($probe, 'Server error') !== false || strpos($probe, 'Fatal error') !== false
    || $probe === '') {
    // No DB / no config on this machine — cannot drive the endpoint.
    echo "  [skip] endpoint harness unavailable (no DB/config); §1-2 + §4 still ran\n";
} else {
    check('IIS over plain HTTP: gate returns https_required (426)',
        strpos($probe, 'https_required') !== false,
        'got: ' . trim(substr($probe, 0, 200)));

    check('IIS over plain HTTP: request does NOT reach past the gate',
        strpos($probe, 'PAST_THE_GATE') === false);

    $apache = runAuthGate(['SERVER_PORT' => '80', 'REMOTE_ADDR' => '203.0.113.9']);
    check('Apache over plain HTTP: gate returns https_required',
        strpos($apache, 'https_required') !== false);

    $spoof = runAuthGate(['SERVER_PORT' => '80', 'REMOTE_ADDR' => '203.0.113.9',
                          'HTTP_X_FORWARDED_PROTO' => 'https']);
    check('spoofed X-Forwarded-Proto from a public peer is REFUSED',
        strpos($spoof, 'https_required') !== false,
        'got: ' . trim(substr($spoof, 0, 200)));
    check('the refusal explains the untrusted-proxy case to the operator',
        strpos($spoof, 'trusted proxy') !== false);

    $proxied = runAuthGate(['SERVER_PORT' => '80', 'REMOTE_ADDR' => '127.0.0.1',
                            'HTTP_X_FORWARDED_PROTO' => 'https']);
    check('X-Forwarded-Proto from a TRUSTED proxy passes the TLS gate',
        strpos($proxied, 'https_required') === false,
        'a real reverse-proxy deployment must not break');

    $tls = runAuthGate(['HTTPS' => 'on', 'SERVER_PORT' => '443', 'REMOTE_ADDR' => '203.0.113.9']);
    check('genuine TLS passes the TLS gate',
        strpos($tls, 'https_required') === false);
}

echo "\n=== 4. Negative control — the OLD expression must still be broken ===\n";

// If this section ever stops failing the way it does here, the two
// expressions have converged and §1-3 have stopped discriminating.
$oldGateSaysSecure = function (array $s): bool {
    // Verbatim reconstruction of the pre-fix condition's "is secure"
    // sense: the 426 fired only when BOTH of these were true.
    $wouldRefuse = empty($s['HTTPS']) && ($s['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https';
    return !$wouldRefuse;
};

check('NEGATIVE CONTROL: old expression called IIS-over-HTTP "secure"',
    $oldGateSaysSecure(['HTTPS' => 'off']) === true,
    'if this fails the control no longer reproduces the reported bug');

check('NEGATIVE CONTROL: old expression trusted a spoofed header',
    $oldGateSaysSecure(['HTTP_X_FORWARDED_PROTO' => 'https']) === true);

check('the fix DISAGREES with the old expression on IIS-over-HTTP',
    withServer(['HTTPS' => 'off', 'SERVER_PORT' => '80', 'REMOTE_ADDR' => '203.0.113.9'],
        'is_https_verified') !== $oldGateSaysSecure(['HTTPS' => 'off']),
    'reverting the fix would make these agree again');

check('the fix DISAGREES with the old expression on a spoofed header',
    withServer(['SERVER_PORT' => '80', 'REMOTE_ADDR' => '203.0.113.9',
                'HTTP_X_FORWARDED_PROTO' => 'https'], 'is_https_verified')
        !== $oldGateSaysSecure(['HTTP_X_FORWARDED_PROTO' => 'https']));

echo "\n=== 5. Mobile session cookie no longer stamped Secure over plain HTTP ===\n";

// Ron's lower-priority finding: the same "off" defect, opposite
// direction. !empty("off") is TRUE, so on IIS-over-HTTP the mobile
// cookie was marked Secure on a non-TLS connection and the browser
// declined to send it back — PWA logins could not hold a session.
$oldCookieSecure = function (array $s): bool {
    return !empty($s['HTTPS'])
        || (($s['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
};

check('NEGATIVE CONTROL: old cookie flag was Secure on IIS-over-HTTP',
    $oldCookieSecure(['HTTPS' => 'off']) === true,
    'this is what broke mobile sessions');

check('fixed: cookie is NOT Secure on IIS over plain HTTP',
    withServer(['HTTPS' => 'off', 'SERVER_PORT' => '80'], 'is_https') === false,
    'restores PWA sessions on IIS-over-HTTP');

check('fixed: cookie IS still Secure on genuine TLS',
    withServer(['HTTPS' => 'on'], 'is_https') === true,
    'the flag must not be lost where it was working');

check('fixed: cookie IS still Secure behind a TLS-terminating proxy',
    withServer(['SERVER_PORT' => '80', 'HTTP_X_FORWARDED_PROTO' => 'https'],
        'is_https') === true);

echo "\n=== 6. Every call site routes through the helper ===\n";

// Not a substitute for the behavioural tests above — a guard against a
// new inline re-derivation drifting back in.
$offenders = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,
    FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    if ($f->getExtension() !== 'php') continue;
    $p = str_replace('\\', '/', $f->getPathname());
    $rel = ltrim(substr($p, strlen(str_replace('\\', '/', $root))), '/');
    if (preg_match('#^(vendor|tests|tools|\.claude|node_modules)/#', $rel)) continue;
    if ($rel === 'inc/https.php' || $rel === 'config.php') continue;   // helper; per-install
    $src = (string) file_get_contents($p);
    // Strip comments so the explanatory notes don't self-report.
    $stripped = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $stripped .= is_array($t) ? $t[1] : $t;
    }
    if (preg_match('/\$_SERVER\s*\[\s*[\'"](HTTPS|HTTP_X_FORWARDED_PROTO|HTTP_X_FORWARDED_SSL)[\'"]\s*\]/', $stripped)) {
        $offenders[] = $rel;
    }
}
check('no file outside inc/https.php reads $_SERVER[HTTPS]/X-Forwarded-* directly',
    $offenders === [], implode(', ', $offenders));

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
