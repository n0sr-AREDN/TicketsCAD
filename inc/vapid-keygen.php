<?php
/**
 * VAPID (Web Push) keypair generation that works on stock Windows PHP.
 *
 * GH TicketsCAD#8 / #5 (a beta tester): on a stock Windows PHP install,
 * Settings -> Web Push Notifications -> "Generate new key pair" fails with
 *
 *     VAPID keypair generation failed: Unable to create the key
 *
 * — true, and useless. The cause is two layers down and has nothing to do with
 * Web Push. Minishlink\WebPush\VAPID::createVapidKeys() ends at
 * openssl_pkey_new(['curve_name' => 'prime256v1', …]), and any EC generation
 * with a NAMED CURVE needs OpenSSL's config file. OpenSSL looks for it at its
 * compiled-in default, C:\Program Files\Common Files\SSL\openssl.cnf, which the
 * Windows PHP distribution never creates. PHP ships its own copy at
 * <PHP_DIR>\extras\ssl\openssl.cnf; it is simply never wired up. Ask OpenSSL
 * what actually went wrong and it says so:
 *
 *     error:07000072:configuration file routines::no such file
 *
 * This is not specific to TicketsCAD, or to this library — it affects any PHP
 * code on Windows calling openssl_pkey_new() with a named curve.
 *
 * WHY THIS FILE EXISTS RATHER THAN A BETTER ERROR MESSAGE
 *
 * The issue asked for detection. Detection alone would still leave the admin to
 * set OPENSSL_CONF in TWO places — the machine environment for CLI, and again
 * inside IIS FastCGI's own environmentVariables collection, which REPLACES the
 * inherited environment rather than merging with it, so a machine-wide setx
 * fixes the CLI tools and leaves the web UI failing exactly as before. That
 * second step is the one people miss, and it costs a day.
 *
 * We can just not need it. inc/field-encrypt.php has located openssl.cnf this
 * way since the RSA field-encryption work; PHP's openssl functions accept an
 * explicit 'config' path. The library's call cannot be given one, so this
 * generates the P-256 keypair directly and encodes it to the VAPID wire format
 * itself — which is a dozen lines, all of it defined by RFC 8292 and SEC1.
 *
 * The library remains the FIRST choice. This runs only when it fails, so hosts
 * where OpenSSL is configured normally are unaffected and keep using upstream's
 * code path.
 *
 * VERIFIED, not assumed. On a host reproducing the reported failure
 * (createVapidKeys() -> "Unable to create the key"), a keypair from this file:
 *   - is accepted by VAPID::validate();
 *   - signs a real VAPID Authorization header via VAPID::getVapidHeaders();
 *   - and that JWT's ES256 signature verifies against the generated public key.
 * Producing key-shaped bytes the library rejects, or accepts and cannot sign
 * with, would be worse than failing loudly — so the round trip is what was
 * checked, not the byte lengths.
 *
 * Wire format (RFC 8292 §3.2, SEC1 §2.3.3):
 *   public  — base64url of the UNCOMPRESSED point: 0x04 || X || Y  (65 bytes)
 *   private — base64url of the raw scalar d                        (32 bytes)
 * X, Y and d are left-padded to 32 bytes: OpenSSL returns them with leading
 * zero bytes stripped, so roughly one key in 256 is short by a byte and would
 * otherwise produce an invalid key that works almost every time — the worst
 * possible failure rate to debug.
 */

declare(strict_types=1);

/**
 * Candidate openssl.cnf locations, most specific first.
 *
 * Mirrors fe_generate_keypair() in inc/field-encrypt.php. An explicitly set
 * OPENSSL_CONF wins: if an admin has already followed the Windows/IIS guide,
 * honour their choice rather than second-guessing it.
 *
 * PHP_BINARY IS THE WRONG DIRECTORY UNDER A REAL WEB SERVER. It is the
 * calling SAPI's own executable — found empirically 2026-08-06 by actually
 * clicking "Generate New Keypair" in a browser against Apache, not just
 * running the CLI test suite: under apache2handler on this XAMPP install,
 * PHP_BINARY is `C:\xampp\8.2.4\apache\bin\httpd.exe`, so dirname(PHP_BINARY)
 * pointed at Apache's OWN bin directory and none of the candidates below it
 * ever existed. Every test that had verified this fallback ran under CLI
 * PHP, where PHP_BINARY happens to genuinely be inside PHP's own directory —
 * so the fallback this function exists to provide had never actually worked
 * through a real request, on either this function or field-encrypt.php's
 * copy of it, since GH#8 shipped.
 *
 * php_ini_loaded_file() does not have this problem: every SAPI loads A
 * php.ini from somewhere inside PHP's own directory, CLI included, so
 * dirname() of it is reliable regardless of which web server is asking.
 * The PHP_BINARY-derived paths stay as a fallback rather than being
 * removed, for a setup where php.ini genuinely cannot be located.
 *
 * @return array<int, string>
 */
function vapid_openssl_conf_candidates(): array
{
    $out = [];

    $iniFile = php_ini_loaded_file();
    $phpDirs = [];
    if (is_string($iniFile) && $iniFile !== '') {
        $phpDirs[] = dirname($iniFile);
    }
    $phpDirs[] = dirname(PHP_BINARY);

    $env = [
        getenv('OPENSSL_CONF') ?: null,
        getenv('SSLEAY_CONF') ?: null,
    ];
    foreach ($env as $p) {
        if (is_string($p) && $p !== '') {
            $out[] = $p;
        }
    }
    foreach ($phpDirs as $phpDir) {
        foreach ([
            $phpDir . '/extras/ssl/openssl.cnf',
            $phpDir . '/../apache/conf/openssl.cnf',
            $phpDir . '/extras/openssl.cnf',
        ] as $p) {
            if (!in_array($p, $out, true)) {
                $out[] = $p;
            }
        }
    }
    return $out;
}

/**
 * The first openssl.cnf that exists, or null.
 */
function vapid_find_openssl_conf(): ?string
{
    foreach (vapid_openssl_conf_candidates() as $p) {
        if (@is_file($p)) {
            return $p;
        }
    }
    return null;
}

/**
 * Does this host's OpenSSL fail specifically because it cannot find its config?
 *
 * Used to decide whether to say "set OPENSSL_CONF" or something more generic —
 * telling an admin to fix their OpenSSL config when that is not the problem
 * sends them down the wrong road as surely as saying nothing.
 */
function vapid_openssl_conf_is_missing(): bool
{
    // Drain any stale entries so we read OUR error, not one left by an earlier
    // unrelated call in the same request.
    while (openssl_error_string() !== false) {
        // discard
    }
    $res = @openssl_pkey_new([
        'curve_name'       => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);
    if ($res !== false) {
        return false;
    }
    $err = '';
    while (($line = openssl_error_string()) !== false) {
        $err .= $line . ' ';
    }
    // OpenSSL 3 reports this as a configuration-file routine failure; older
    // builds surface the same condition through the BIO layer as it fails to
    // open the file. Match on either rather than on one build's exact text.
    return stripos($err, 'configuration file') !== false
        || stripos($err, 'no such file') !== false
        || stripos($err, 'BIO routines') !== false;
}

/**
 * Generate a VAPID P-256 keypair without relying on OpenSSL's default config.
 *
 * @return array{publicKey:string, privateKey:string, config:string}
 * @throws RuntimeException with an actionable message.
 */
function vapid_generate_keypair_direct(): array
{
    $cnf = vapid_find_openssl_conf();
    if ($cnf === null) {
        throw new RuntimeException(
            'Could not find an openssl.cnf to generate the key with. Looked in: '
            . implode(', ', vapid_openssl_conf_candidates())
        );
    }

    while (openssl_error_string() !== false) {
        // drain
    }

    $res = @openssl_pkey_new([
        'curve_name'       => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'config'           => $cnf,
    ]);
    if ($res === false) {
        $err = '';
        while (($line = openssl_error_string()) !== false) {
            $err .= $line . ' ';
        }
        throw new RuntimeException(
            'openssl_pkey_new() failed even with an explicit config (' . $cnf . '): '
            . trim($err)
        );
    }

    $details = openssl_pkey_get_details($res);
    if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'], $details['ec']['d'])) {
        throw new RuntimeException(
            'The generated key has no EC components — this PHP build may be too old '
            . '(openssl_pkey_get_details() has returned them since PHP 7.1).'
        );
    }

    // Left-pad to the curve's 32-byte field size. See the note above: OpenSSL
    // strips leading zero bytes, so skipping this yields a key that is valid
    // ~255 times out of 256.
    $pad = static function (string $bin): string {
        return str_pad($bin, 32, "\0", STR_PAD_LEFT);
    };
    $b64u = static function (string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    };

    $public  = $b64u("\x04" . $pad($details['ec']['x']) . $pad($details['ec']['y']));
    $private = $b64u($pad($details['ec']['d']));

    return ['publicKey' => $public, 'privateKey' => $private, 'config' => $cnf];
}

/**
 * Generate a VAPID keypair, preferring the library and falling back to a
 * config-explicit generation when the host's OpenSSL cannot find its own.
 *
 * @return array{publicKey:string, privateKey:string, via:string, config:?string}
 * @throws RuntimeException carrying advice the admin can act on.
 */
function vapid_generate_keypair(): array
{
    $libraryError = null;

    if (class_exists('\\Minishlink\\WebPush\\VAPID')) {
        try {
            $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
            if (!empty($keys['publicKey']) && !empty($keys['privateKey'])) {
                return [
                    'publicKey'  => $keys['publicKey'],
                    'privateKey' => $keys['privateKey'],
                    'via'        => 'library',
                    'config'     => null,
                ];
            }
            $libraryError = 'the library returned an empty keypair';
        } catch (Throwable $e) {
            $libraryError = $e->getMessage();
        }
    } else {
        $libraryError = 'minishlink/web-push is not installed';
    }

    // Fall back only for the failure we understand. Anything else should
    // surface as itself rather than be papered over by a second attempt.
    try {
        $keys = vapid_generate_keypair_direct();
        return [
            'publicKey'  => $keys['publicKey'],
            'privateKey' => $keys['privateKey'],
            'via'        => 'openssl-direct',
            'config'     => $keys['config'],
        ];
    } catch (Throwable $fallback) {
        throw new RuntimeException(vapid_keygen_advice($libraryError, $fallback->getMessage()));
    }
}

/**
 * Turn two opaque failures into something an administrator can act on.
 *
 * The principle is the one already applied to MySQL error 1054, where
 * db_query() turns "unknown column" into a reply naming the column and the
 * repair command: when the system knows the answer, it should say the answer
 * rather than restate the question.
 */
function vapid_keygen_advice(?string $libraryError, string $fallbackError): string
{
    $msg = 'VAPID keypair generation failed: ' . ($libraryError ?: 'unknown error') . '.';

    if (vapid_openssl_conf_is_missing()) {
        $found = vapid_find_openssl_conf();
        $msg .= "\n\nThis host's OpenSSL cannot find its configuration file, so it cannot "
              . 'generate any key that names a curve — which is every Web Push key. '
              . 'This is not a problem with TicketsCAD or with Web Push.';
        if ($found !== null) {
            $msg .= "\n\nA usable openssl.cnf IS present at:\n    " . $found
                  . "\nbut generation still failed, so something else is also wrong. "
                  . 'The OpenSSL error above is the thing to chase.';
        } else {
            $msg .= "\n\nPHP ships a copy of the file it needs but never wires it up. Set "
                  . "OPENSSL_CONF to it — and on IIS set it in BOTH places, because "
                  . "FastCGI defines its own environmentVariables collection that REPLACES "
                  . "the inherited environment instead of merging with it, so a machine-wide "
                  . "setx fixes the command-line tools and leaves the web UI failing "
                  . "identically:\n"
                  . "    setx OPENSSL_CONF \"C:\\PHP84\\extras\\ssl\\openssl.cnf\" /M\n"
                  . "    appcmd set config -section:system.webServer/fastCgi \\\n"
                  . "      \"/+[fullPath='C:\\PHP84\\php-cgi.exe'].environmentVariables."
                  . "[name='OPENSSL_CONF',value='C:\\PHP84\\extras\\ssl\\openssl.cnf']\" \\\n"
                  . "      /commit:apphost\n"
                  . "Then recycle the application pool. Full walkthrough: "
                  . 'docs/INSTALL-WINDOWS-IIS.md';
        }
        $msg .= "\n\nLooked for openssl.cnf in: " . implode(', ', vapid_openssl_conf_candidates());
    } else {
        $msg .= "\n\nOpenSSL on this host CAN generate an EC key, so its configuration is not "
              . 'the problem. The direct attempt reported: ' . $fallbackError;
    }

    return $msg;
}

/**
 * GH#31: does this host actually resolve the PER-MESSAGE encryption key,
 * not just the one-time VAPID keypair?
 *
 * vapid_generate_keypair() succeeding proves nothing about whether an actual
 * push send will work — that showed up in production as "Keypair configured"
 * followed by every send failing, because the library generates a fresh EC
 * key for every message via a call this file's fallback never touched.
 * tools/patch_vendor_webpush.php fixes that call site directly; this
 * function proves the fix is actually in effect by invoking the REAL
 * vendored method — not a parallel reimplementation of what we assume it
 * does, which could silently drift from the patched code over time.
 *
 * Requires the vendor autoloader to already be loaded; returns a clear
 * failure rather than fatal if it is not.
 *
 * @return array{ok:bool, error:?string}
 */
function vapid_encryption_selftest(): array
{
    if (!class_exists('\\Minishlink\\WebPush\\Encryption')) {
        return ['ok' => false, 'error' => 'minishlink/web-push is not installed'];
    }

    try {
        $method = new ReflectionMethod('\\Minishlink\\WebPush\\Encryption', 'createLocalKeyObject');
        $method->setAccessible(true);
        $method->invoke(null);
        return ['ok' => true, 'error' => null];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Turn a failed vapid_encryption_selftest() into something an administrator
 * can act on — the same principle as vapid_keygen_advice(), applied to a
 * different symptom: here the keypair itself is fine, so the message must
 * say plainly that KEYPAIR SUCCESS DOES NOT MEAN SENDS WILL WORK, or an
 * admin who already saw "Keypair configured" has no reason to read further.
 */
function vapid_send_capability_advice(string $selftestError): string
{
    $msg = "Your VAPID keypair is valid, but a live test of the per-message "
         . "encryption step failed, which means push notifications will NOT "
         . "actually send on this host. This is a different problem from "
         . "keypair generation, and generating a new keypair will not fix it.";

    if (vapid_openssl_conf_is_missing()) {
        $found = vapid_find_openssl_conf();
        if ($found !== null) {
            $msg .= "\n\nThe automatic fix (GH#31) looks for openssl.cnf at: " . $found
                  . "\nand should be using it. If sends are still failing, the vendor "
                  . "library may not have been patched — run:\n"
                  . "    php tools/patch_vendor_webpush.php\n"
                  . "and confirm it reports 'applied', not an error. If it errors, "
                  . "minishlink/web-push has likely changed and needs a look — see "
                  . "that script's own comments.";
        } else {
            $msg .= "\n\nNo openssl.cnf could be found anywhere this host was told to "
                  . "look: " . implode(', ', vapid_openssl_conf_candidates()) . "\n"
                  . "Set OPENSSL_CONF — and on IIS, set it in BOTH the machine "
                  . "environment and IIS FastCGI's own environmentVariables "
                  . "collection, which replaces rather than merges the inherited "
                  . "environment. Full walkthrough: docs/INSTALL-WINDOWS-IIS.md";
        }
    } else {
        $msg .= "\n\nThis host's OpenSSL can otherwise generate an EC key, so its "
              . "configuration is not the general problem. The self-test reported: "
              . $selftestError;
    }

    return $msg;
}
