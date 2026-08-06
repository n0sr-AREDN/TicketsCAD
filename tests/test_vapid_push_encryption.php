<?php
/**
 * GH#31 — the per-message encryption key, not just the one-time VAPID keypair.
 *
 * inc/vapid-keygen.php (GH#8) fixed VAPID KEYPAIR generation on a host whose
 * OpenSSL cannot find its own config. It did not fix the SEPARATE key
 * minishlink/web-push generates for every push message, in vendored code
 * that never saw the fallback — an install could show "Keypair configured"
 * and still fail every send, forever, because sending never touched
 * inc/vapid-keygen.php at all.
 *
 * tools/patch_vendor_webpush.php closes that gap by wiring the vendored call
 * to the SAME config resolution. This file proves that fix survives a host
 * where it matters, rather than trusting that patching the right lines means
 * the right thing happens at runtime — the two are not the same claim, and
 * conflating them is exactly how GH#31 shipped in the first place.
 *
 * SECOND, MORE FUNDAMENTAL BUG FOUND WHILE VERIFYING THE FIRST (2026-08-06):
 * every candidate path this fallback searches was built from
 * dirname(PHP_BINARY) — which is the CALLING SAPI'S OWN EXECUTABLE, not
 * PHP's directory. Every test of this fallback, from GH#8 onward, had run
 * under CLI PHP, where PHP_BINARY happens to genuinely sit inside PHP's own
 * directory. Clicking "Generate New Keypair" in an actual browser against
 * Apache — not running the CLI suite — surfaced that under apache2handler,
 * PHP_BINARY is Apache's OWN httpd.exe, so dirname(PHP_BINARY) pointed at
 * Apache's bin directory and none of the candidates under it had ever
 * existed. The fallback this whole file exists to verify had never actually
 * worked through a real request. inc/field-encrypt.php (RSA field
 * encryption) carried an identical copy of the same mistake. Both now
 * resolve via php_ini_loaded_file() first, which every SAPI populates from
 * inside PHP's own directory. This is the test-suite record of why a CLI
 * assertion that a fallback "works" is not proof it works for the SAPI that
 * actually serves users — see docs/CI-ENVIRONMENT.md and the root-cause
 * playbook: verify by round trip, in the environment that matters, not by
 * whichever process happened to be convenient to test in.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

$pass = 0;
$fail = 0;

function test(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "[PASS] $name\n";
    } else {
        $fail++;
        echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

$lib = $root . '/inc/vapid-keygen.php';
if (!is_file($lib)) {
    echo "SKIP: inc/vapid-keygen.php not present\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}
require_once $lib;

if (!extension_loaded('openssl')) {
    echo "SKIP: ext/openssl is not loaded on this host\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

$autoload = $root . '/vendor/autoload.php';
$vendorPresent = is_file($autoload);

/**
 * Ask a FRESH PHP process to evaluate $code and print one JSON line prefixed
 * with a marker, optionally with a custom OS-level environment and/or a
 * custom php.ini. Mirrors tests/test_backup_dir_platform.php's
 * bdp_ask_fresh_process(): argv array (never a shell string — this project
 * gates against shelling out), returns null rather than failing when a
 * subprocess cannot be started at all so the caller skips instead of failing
 * on an unrelated environment limitation.
 *
 * @param array<string,string> $env Extra/overriding OS-level environment
 *   variables for the child. Must be set BEFORE the child's PHP starts —
 *   putenv() from inside a running PHP process does not affect this host's
 *   OpenSSL config resolution (verified directly; see the docblock above).
 */
function vpe_ask_fresh_process(string $code, array $env = [], ?string $iniPath = null): ?string
{
    if (!function_exists('proc_open')) { return null; }
    $bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : null;
    if ($bin === null || !@is_file($bin)) { return null; }

    $tmp = sys_get_temp_dir() . '/tcad-vpe-' . getmypid() . '-' . mt_rand() . '.php';
    if (@file_put_contents($tmp, $code) === false) { return null; }

    // -n (skip the default php.ini) only when a specific one is supplied —
    // scenario 3 below needs the REAL php.ini so ext/openssl is actually
    // loaded; only scenario 4 (proving resolution follows php.ini rather
    // than PHP_BINARY) wants a minimal, controlled ini instead.
    $argv = [$bin];
    if ($iniPath !== null) {
        $argv[] = '-n';
        $argv[] = '-c';
        $argv[] = $iniPath;
    }
    $argv[] = $tmp;

    $fullEnv = array_merge(
        array_filter([
            'SystemRoot' => getenv('SystemRoot') ?: null,
            'PATH'       => getenv('PATH') ?: null,
        ], fn($v) => $v !== null),
        $env
    );

    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($argv, $desc, $pipes, null, $fullEnv);
    if (!is_resource($proc)) { @unlink($tmp); return null; }
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    @unlink($tmp);

    $at = strpos($out, '<<<VPE>>>');
    return $at === false ? null : substr($out, $at + 9);
}

// ── 1. The self-test helper exists and passes on THIS host ─────────────────
echo "-- vapid_encryption_selftest() --\n";

test('vapid_encryption_selftest() is defined', function_exists('vapid_encryption_selftest'));
test('vapid_send_capability_advice() is defined', function_exists('vapid_send_capability_advice'));

if ($vendorPresent) {
    require_once $autoload;
    $r = vapid_encryption_selftest();
    test(
        'live self-test succeeds on this host (patched vendor + resolvable openssl.cnf)',
        $r['ok'] === true,
        (string) ($r['error'] ?? '')
    );
} else {
    echo "SKIP: vendor/autoload.php not present — the vendor-patch tests below cannot run\n";
}

// ── 2. The composer-patch marker is actually in the installed vendor file ──
echo "\n-- Vendor patch (tools/patch_vendor_webpush.php) --\n";

$vendorTarget = $root . '/vendor/minishlink/web-push/src/Encryption.php';
if (is_file($vendorTarget)) {
    $src = (string) file_get_contents($vendorTarget);
    test(
        'Encryption.php carries the GH#31 patch marker',
        strpos($src, "TicketsCAD GH#31") !== false
    );
} else {
    echo "SKIP: vendor/minishlink/web-push not installed\n";
}

// ── 3. Proof, not assumption: the patched vendor call survives a host whose
//      OPENSSL_CONF is broken at the OS level — the exact condition GH#31
//      reported. putenv() from inside THIS process would prove nothing (see
//      the docblock); the env must be wrong before the child's PHP starts.
echo "\n-- Survives a broken OPENSSL_CONF (subprocess-isolated) --\n";

if ($vendorPresent && is_file($vendorTarget) && strpos((string) file_get_contents($vendorTarget), "TicketsCAD GH#31") !== false) {
    $code = '<?php '
          . 'require_once ' . var_export($autoload, true) . '; '
          . 'require_once ' . var_export($lib, true) . '; '
          . '$r = vapid_encryption_selftest(); '
          . 'echo "<<<VPE>>>" . json_encode($r);';

    $out = vpe_ask_fresh_process($code, ['OPENSSL_CONF' => 'C:\\definitely\\does\\not\\exist.cnf']);
    if ($out === null) {
        echo "SKIP: could not start an isolated subprocess\n";
    } else {
        $r = json_decode($out, true);
        test(
            'per-message key generation succeeds even with a broken OS-level OPENSSL_CONF',
            is_array($r) && ($r['ok'] ?? false) === true,
            is_array($r) ? (string) ($r['error'] ?? '') : "unparseable output: $out"
        );
    }
} else {
    echo "SKIP: vendor patch not present — nothing to prove survives a broken environment\n";
}

// ── 4. Proof the resolution follows php_ini_loaded_file(), not PHP_BINARY —
//      the second, more fundamental bug found while verifying the first.
//      A custom -c ini file gives this process a php_ini_loaded_file() that
//      points somewhere PHP_BINARY does not, exactly mirroring how Apache's
//      PHP_BINARY (httpd.exe) diverges from where its php.ini actually is.
echo "\n-- Config resolution follows php.ini, not the calling SAPI's binary --\n";

$fakeDir = sys_get_temp_dir() . '/tcad-vpe-fakephp-' . getmypid() . '-' . mt_rand();
$realCnf = null;
foreach (vapid_openssl_conf_candidates() as $c) {
    if (@is_file($c)) { $realCnf = $c; break; }
}

if ($realCnf === null) {
    echo "SKIP: this host has no discoverable openssl.cnf to seed the fake directory with\n";
} else {
    @mkdir($fakeDir . '/extras/ssl', 0777, true);
    $fakeCnf = $fakeDir . '/extras/ssl/openssl.cnf';
    $fakeIni = $fakeDir . '/fake.ini';
    if (@copy($realCnf, $fakeCnf) && @file_put_contents($fakeIni, '') !== false) {
        $code = '<?php '
              . 'require_once ' . var_export($lib, true) . '; '
              . '$found = vapid_find_openssl_conf(); '
              . 'echo "<<<VPE>>>" . json_encode(['
              . '"ini" => php_ini_loaded_file(), '
              . '"bin" => PHP_BINARY, '
              . '"found" => $found'
              . ']);';

        $out = vpe_ask_fresh_process(
            $code,
            ['OPENSSL_CONF' => 'C:\\definitely\\does\\not\\exist.cnf'],
            $fakeIni
        );
        if ($out === null) {
            echo "SKIP: could not start an isolated subprocess\n";
        } else {
            $r = json_decode($out, true);
            $found = is_array($r) ? ($r['found'] ?? null) : null;
            $bin   = is_array($r) ? ($r['bin'] ?? '') : '';
            test(
                'PHP_BINARY does NOT point inside the fake php.ini directory (sanity check the test itself)',
                is_string($bin) && stripos($bin, 'tcad-vpe-fakephp') === false
            );
            test(
                'resolution follows the fake php.ini\'s directory, not PHP_BINARY\'s',
                is_string($found) && strpos($found, 'tcad-vpe-fakephp') !== false,
                'found=' . var_export($found, true) . ' raw=' . $out
            );
        }
        @unlink($fakeCnf);
        @unlink($fakeIni);
        @rmdir($fakeDir . '/extras/ssl');
        @rmdir($fakeDir . '/extras');
        @rmdir($fakeDir);
    } else {
        echo "SKIP: could not set up the fake php.ini directory\n";
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
