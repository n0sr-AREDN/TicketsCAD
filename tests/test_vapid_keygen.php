<?php
/**
 * VAPID keypair generation on hosts whose OpenSSL cannot find its config.
 *
 * GH TicketsCAD#8 / #5 (a beta tester): Settings -> Web Push -> "Generate new key
 * pair" failed on stock Windows PHP with "VAPID keypair generation failed:
 * Unable to create the key" — the library's message, passed through verbatim.
 * True, and it answers nothing. One layer down, openssl_error_string() was
 * already saying exactly what was wrong:
 *
 *     error:07000072:configuration file routines::no such file
 *
 * The Windows PHP distribution never creates
 * C:\Program Files\Common Files\SSL\openssl.cnf, OpenSSL's compiled-in default,
 * so every EC generation with a named curve fails — which is every Web Push
 * key. PHP ships its own copy at <PHP_DIR>\extras\ssl\openssl.cnf and simply
 * does not wire it up.
 *
 * inc/vapid-keygen.php generates the key with an explicit config path when the
 * host cannot find one, so the admin does not have to set OPENSSL_CONF in the
 * two separate places IIS requires. The library stays the first choice.
 *
 * WHAT THIS FILE IS CAREFUL ABOUT
 *
 * A keypair generator is the wrong place to assert on lengths and call it done.
 * Bytes of the right shape that the push service later rejects would fail after
 * deployment, intermittently, in the one feature nobody watches. So the real
 * assertion here is a ROUND TRIP through the library that will consume them:
 * validate the pair, sign an actual VAPID Authorization header with it, and
 * verify that JWT's ES256 signature against the generated public key. If any
 * of that is wrong, this fails now rather than in the field.
 *
 * It also generates repeatedly and checks the padding invariant, because
 * OpenSSL strips leading zero bytes from X, Y and d: roughly one key in 256 is
 * a byte short, so an unpadded implementation works almost every time — the
 * worst failure rate there is to diagnose.
 */

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

// ── 1. The helpers exist and behave ─────────────────────────────────────────
echo "-- Helpers --\n";

test('vapid_generate_keypair() is defined', function_exists('vapid_generate_keypair'));
test('vapid_generate_keypair_direct() is defined', function_exists('vapid_generate_keypair_direct'));
test('vapid_find_openssl_conf() is defined', function_exists('vapid_find_openssl_conf'));
test('vapid_openssl_conf_is_missing() is defined', function_exists('vapid_openssl_conf_is_missing'));

$candidates = vapid_openssl_conf_candidates();
test('candidate list is non-empty', $candidates !== []);
test('candidates include PHP\'s own shipped copy',
    (bool) preg_grep('~extras[/\\\\]ssl[/\\\\]openssl\.cnf$~', $candidates),
    implode(', ', $candidates));

$confMissing = vapid_openssl_conf_is_missing();
echo "       (this host " . ($confMissing ? 'REPRODUCES' : 'does not reproduce')
   . " the reported OpenSSL-config failure)\n";

// ── 2. Generation works here, whichever route it takes ──────────────────────
echo "\n-- Generation --\n";

$keys = null;
try {
    $keys = vapid_generate_keypair();
    test('vapid_generate_keypair() returns a keypair', true);
} catch (Throwable $e) {
    // On a host with neither a working OpenSSL config nor a locatable
    // openssl.cnf there is genuinely nothing to generate with. Report that as
    // a skip rather than a failure, but demand the message be useful.
    echo "[SKIP] generation — " . str_replace("\n", ' ', substr($e->getMessage(), 0, 160)) . "\n";
    test('the failure message names OPENSSL_CONF so the admin can act',
        stripos($e->getMessage(), 'OPENSSL_CONF') !== false
        || stripos($e->getMessage(), 'openssl.cnf') !== false);
}

if ($keys !== null) {
    test('reports which route produced the key',
        in_array($keys['via'] ?? '', ['library', 'openssl-direct'], true),
        var_export($keys['via'] ?? null, true));
    echo "       (route: " . $keys['via'] . ")\n";

    $decode = static function (string $b64u): string {
        return (string) base64_decode(strtr($b64u, '-_', '+/')
            . str_repeat('=', (4 - strlen($b64u) % 4) % 4));
    };

    $pubRaw  = $decode($keys['publicKey']);
    $privRaw = $decode($keys['privateKey']);

    test('public key is a 65-byte uncompressed point (RFC 8292 / SEC1)',
        strlen($pubRaw) === 65, strlen($pubRaw) . ' bytes');
    test('public key starts with the uncompressed-point marker 0x04',
        $pubRaw !== '' && ord($pubRaw[0]) === 0x04);
    test('private key is the 32-byte scalar', strlen($privRaw) === 32, strlen($privRaw) . ' bytes');
    test('neither key contains base64 padding or +/ characters (base64URL)',
        strpbrk($keys['publicKey'] . $keys['privateKey'], '+/=') === false);

    // ── 3. The round trip — the assertion that actually matters ─────────────
    echo "\n-- The library accepts and can sign with them --\n";

    $autoload = $root . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        echo "[SKIP] round trip — vendor/ not installed (run composer install)\n";
    } else {
        require_once $autoload;
        if (!class_exists('\\Minishlink\\WebPush\\VAPID')) {
            echo "[SKIP] round trip — minishlink/web-push not installed\n";
        } else {
            $auth = null;
            try {
                $auth = \Minishlink\WebPush\VAPID::validate([
                    'subject'    => 'mailto:admin@example.com',
                    'publicKey'  => $keys['publicKey'],
                    'privateKey' => $keys['privateKey'],
                ]);
                test('VAPID::validate() accepts the keypair', true);
            } catch (Throwable $e) {
                test('VAPID::validate() accepts the keypair', false, $e->getMessage());
            }

            if ($auth !== null) {
                $headers = null;
                try {
                    // validate() returns DECODED keys; getVapidHeaders() wants
                    // raw bytes, not the base64url strings.
                    $headers = \Minishlink\WebPush\VAPID::getVapidHeaders(
                        'https://fcm.googleapis.com',
                        'mailto:admin@example.com',
                        $auth['publicKey'],
                        $auth['privateKey'],
                        'aes128gcm'
                    );
                    test('a real VAPID Authorization header can be signed with them',
                        isset($headers['Authorization'])
                        && strpos($headers['Authorization'], 'vapid t=') === 0);
                } catch (Throwable $e) {
                    test('a real VAPID Authorization header can be signed with them',
                        false, $e->getMessage());
                }

                // Verify the signature against the public key we produced. This
                // is what proves X and Y really belong to d — a mismatch would
                // sign fine and be rejected by every push service.
                if ($headers !== null && isset($headers['Authorization'])) {
                    $jwt = trim(str_replace('vapid t=', '', explode(',', $headers['Authorization'])[0]));
                    $parts = explode('.', $jwt);
                    if (count($parts) === 3) {
                        $sig = $decode($parts[2]);
                        $r = ltrim(substr($sig, 0, 32), "\0");
                        $s = ltrim(substr($sig, 32), "\0");
                        $derInt = static function (string $v): string {
                            if ($v === '') { $v = "\0"; }
                            if (ord($v[0]) > 0x7f) { $v = "\0" . $v; }
                            return "\x02" . chr(strlen($v)) . $v;
                        };
                        $seq = $derInt($r) . $derInt($s);
                        $der = "\x30" . chr(strlen($seq)) . $seq;

                        // Rebuild a PEM public key from our own X/Y so the
                        // verification uses the published half of the pair.
                        $pem = vapid_test_pem_from_point($pubRaw);
                        $ok  = $pem === null
                            ? -1
                            : openssl_verify($parts[0] . '.' . $parts[1], $der, $pem, OPENSSL_ALGO_SHA256);
                        test('the signed JWT verifies against the generated public key',
                            $ok === 1, 'openssl_verify returned ' . $ok);
                    } else {
                        test('the Authorization header carries a three-part JWT', false);
                    }
                }
            }
        }
    }

    // ── 4. Padding invariant across many keys ───────────────────────────────
    echo "\n-- Padding holds across repeated generation --\n";

    $bad = 0;
    $runs = 24;
    for ($i = 0; $i < $runs; $i++) {
        try {
            $k = vapid_generate_keypair();
        } catch (Throwable $e) {
            break;
        }
        if (strlen($decode($k['publicKey'])) !== 65 || strlen($decode($k['privateKey'])) !== 32) {
            $bad++;
        }
    }
    test("every one of $runs generated keys has the exact wire length", $bad === 0,
        "$bad key(s) were the wrong length — leading zero bytes are not being padded");
}

// ── 5. The advice, when there is nothing we can do ──────────────────────────
echo "\n-- The message left for the administrator --\n";

$advice = vapid_keygen_advice('Unable to create the key', 'no openssl.cnf found');
test('advice restates the library error so nothing is hidden',
    strpos($advice, 'Unable to create the key') !== false);
test('advice is longer than the message it replaces',
    strlen($advice) > 120);
if ($confMissing) {
    test('on an affected host the advice names OPENSSL_CONF',
        strpos($advice, 'OPENSSL_CONF') !== false || strpos($advice, 'openssl.cnf') !== false);
    test('advice points at the Windows/IIS guide',
        strpos($advice, 'INSTALL-WINDOWS-IIS') !== false
        || strpos($advice, 'openssl.cnf') !== false);
} else {
    test('on an unaffected host the advice does not blame OpenSSL config',
        stripos($advice, 'cannot find its configuration') === false);
}

/** Build a PEM SubjectPublicKeyInfo for a P-256 uncompressed point. */
function vapid_test_pem_from_point(string $point): ?string
{
    if (strlen($point) !== 65) {
        return null;
    }
    // SEQUENCE { SEQUENCE { OID ecPublicKey, OID prime256v1 }, BIT STRING point }
    $algo = "\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
    $bits = "\x03" . chr(strlen($point) + 1) . "\x00" . $point;
    $seq  = $algo . $bits;
    $der  = "\x30" . chr(strlen($seq)) . $seq;
    return "-----BEGIN PUBLIC KEY-----\n"
         . chunk_split(base64_encode($der), 64, "\n")
         . "-----END PUBLIC KEY-----\n";
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
