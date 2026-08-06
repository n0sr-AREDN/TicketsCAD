<?php
/**
 * NewUI v4.0 - RSA Field Encryption
 *
 * Encrypts sensitive form fields (passwords, patient data, medical info)
 * when the page is served over HTTP so data is not sent in cleartext.
 *
 * Encryption:
 *   Algorithm: RSA-OAEP with SHA-1 hash (both client and server)
 *   Key size:  2048-bit
 *   Client:    Web Crypto API  (crypto.subtle.encrypt, RSA-OAEP, SHA-1)
 *   Server:    PHP OpenSSL     (openssl_private_decrypt, OPENSSL_PKCS1_OAEP_PADDING)
 *
 * Note: PHP's OPENSSL_PKCS1_OAEP_PADDING always uses SHA-1 for the OAEP
 * hash function. The `digest_alg => 'sha256'` in openssl_pkey_new() controls
 * the digest for CSR/signing operations, NOT the OAEP encryption padding.
 * Both sides intentionally use SHA-1 for OAEP to ensure interoperability.
 *
 * Key storage: see the block below — a sibling of the install directory on
 * POSIX, %ProgramData%\TicketsCAD\keys on Windows.
 */

require_once __DIR__ . '/https.php';   // is_https(), is_https_verified()
require_once __DIR__ . '/served-dir.php';

// This file has always needed NEWUI_ROOT at include time, and every caller has
// always loaded config.php first. The guard is here because inc/tfa.php now
// requires this file (so that one place decides where keys live), which widens
// the set of include paths that reach it — and the same fallback is already the
// convention in inc/file-write.php, inc/geocode.php and inc/tile-proxy.php.
if (!defined('NEWUI_ROOT')) {
    define('NEWUI_ROOT', dirname(__DIR__));
}

// ── Where the key files live ────────────────────────────────────────────────
//
// This directory holds:
//
//   private.pem   the RSA private key that decrypts field-encrypted form data
//   public.pem    its public half
//   tfa.key       the AES key that decrypts every enrolled TOTP secret and
//                 every backup code
//   archive/      superseded copies of the above, kept by fe_archive_keys()
//
// ── WHAT WAS WRONG (GHSA-3jmh-c6f6-64jc, 2026-08-03) ───────────────────────
//
// Until 4.2.4 this was, unconditionally:
//
//     define('FE_KEYS_DIR', NEWUI_ROOT . '/../keys');
//
// with the stated intent (docs/UPDATE-CHECKLIST.md) "one level ABOVE the
// install directory, on purpose … so the private key is not HTTP-reachable".
//
// That holds on a POSIX layout: /var/www/newui gives /var/www, which no server
// publishes. It INVERTS on a stock Windows one. IIS sites are subdirectories of
// a SERVED C:\inetpub\wwwroot, so C:\inetpub\wwwroot\TicketsV4\..\keys is
// C:\inetpub\wwwroot\keys — inside Default Web Site's root, bound to *:80.
// XAMPP is the same shape (C:\xampp\htdocs\newui → C:\xampp\htdocs, the
// DocumentRoot). @rjonesbsink proved the directory was being served:
//
//     GET http://localhost/keys/_probe.txt   ->  200  "control-file"
//     GET http://localhost/keys/private.pem  ->  404.3 (MIME type restriction)
//
// The .pem refusal is an ACCIDENT OF FILE NAMING, not a control. IIS has no
// MIME mapping for .pem so it is incidentally refused; add one for any
// unrelated reason and the key is served. Any mapped extension in that
// directory IS served, which is what the .txt shows. And on Apache — same
// layout, same "one level up" reasoning — .pem is served as plain text, with
// no MIME allow-list to fall back on. The directory had no web.config and no
// .htaccess.
//
// This is the third time the same assumption has shipped; the shared reasoning
// and the detection live in inc/served-dir.php.
//
// ── AND WHY THE FIX IS NOT SIMPLY "MOVE IT" ────────────────────────────────
//
// BACKUP_DIR could be relocated outright (commit 5b88fbb) because a missed
// archive is an inconvenience. A missed KEY is not:
//
//   * private.pem decrypts field-encrypted data. Lose the path and that data
//     is unreadable.
//   * tfa.key decrypts enrolled 2FA secrets. Lose the path and EVERY 2FA user
//     is locked out of the system, at once, with no self-service recovery.
//
// So the new default governs where keys are CREATED, and reading still finds
// keys wherever they already are. FE_KEYS_DIR_LEGACY is the historical
// location; if it holds key material, that is where this install keeps working,
// in place, with no operator action — and the Status page says so, loudly,
// naming the path, rather than anything being moved for you. A half-completed
// key move is worse than the exposure it was meant to fix.
//
//   POSIX    <parent of install>/keys          (unchanged — correct there, and
//                                               identical to the old value)
//   Windows  %ProgramData%\TicketsCAD\keys     (never a site root under IIS,
//                                               XAMPP or nginx; the same base
//                                               the backup fix chose, so an
//                                               operator has one place to look)
//
// An operator who wants a different directory defines FE_KEYS_DIR in
// config.php, which overrides everything here — that define is guarded for
// exactly that reason, and was not before.

/**
 * The default keys directory for an application root, per platform.
 *
 * The platform is a parameter, not a read of DIRECTORY_SEPARATOR, so both
 * answers are assertable from one machine. A test that can only see its own
 * platform's answer is how the Windows case shipped twice.
 */
function fe_default_keys_dir_for(string $appRoot, ?bool $windows = null): string
{
    if ($windows === null) {
        $windows = (DIRECTORY_SEPARATOR === '\\');
    }
    if (!$windows) {
        return served_dir_parent_of($appRoot, false) . '/keys';
    }
    return served_dir_program_data() . '\\TicketsCAD\\keys';
}

/**
 * Where every version up to 4.2.3 put the keys: a sibling of the install
 * directory, on every platform. Still the default on POSIX; on Windows it is
 * the directory that turned out to be published.
 */
function fe_legacy_keys_dir_for(string $appRoot, ?bool $windows = null): string
{
    if ($windows === null) {
        $windows = (DIRECTORY_SEPARATOR === '\\');
    }
    return served_dir_parent_of($appRoot, $windows) . ($windows ? '\\keys' : '/keys');
}

/**
 * Does this directory hold key material an install is depending on?
 *
 * The three files that decrypt something. An empty directory — the state a
 * fresh install or the Docker entrypoint leaves behind — is deliberately NOT
 * key material: there is nothing to lose by creating the next key elsewhere.
 */
function fe_dir_holds_keys(string $dir): bool
{
    $base = rtrim(str_replace('\\', '/', $dir), '/');
    foreach (['private.pem', 'public.pem', 'tfa.key'] as $f) {
        if (@is_file($base . '/' . $f)) {
            return true;
        }
    }
    return false;
}

/**
 * Choose between the historical location and the new default.
 *
 * Takes both paths rather than an application root and a platform flag, so the
 * decision can be driven with two REAL directories on any machine — including
 * the Linux CI box, where the two would otherwise be the same path and the only
 * branch that matters would never be exercised.
 *
 * The rule is deliberately asymmetric. If the legacy directory holds keys, it
 * wins, even when the new default holds keys too:
 *
 *   * If an operator copied the keys to the new location and left the
 *     originals, both directories hold the same bytes and either answer works —
 *     but the Status page keeps naming the exposed copy until it is gone, which
 *     is the outcome we want.
 *   * If some other key had been created in the new location, preferring it
 *     would decrypt nothing and lock out every 2FA user. Preferring the legacy
 *     one cannot have that failure mode.
 *
 * An install therefore keeps working across the upgrade with no operator
 * action, which is the entire requirement.
 */
function fe_keys_dir_resolve(string $legacy, string $default): string
{
    $n = function (string $p): string { return rtrim(str_replace('\\', '/', $p), '/'); };
    if ($n($legacy) === $n($default)) {
        return $default;                       // POSIX: the same directory
    }
    return fe_dir_holds_keys($legacy) ? $legacy : $default;
}

/** The keys directory this install should use, before any config.php override. */
function fe_keys_dir_for(string $appRoot, ?bool $windows = null): string
{
    return fe_keys_dir_resolve(
        fe_legacy_keys_dir_for($appRoot, $windows),
        fe_default_keys_dir_for($appRoot, $windows)
    );
}

// Guarded, so config.php can set it. Unguarded until 4.2.4, which meant the
// documented escape hatch ("put your keys somewhere else") did not exist: the
// define here always won and PHP cannot redefine a constant.
if (!defined('FE_KEYS_DIR')) {
    define('FE_KEYS_DIR', fe_keys_dir_for(NEWUI_ROOT));
}
// The built-in locations, reported by the health check whichever one is in use
// and whatever FE_KEYS_DIR was overridden to.
if (!defined('FE_KEYS_DIR_DEFAULT')) {
    define('FE_KEYS_DIR_DEFAULT', fe_default_keys_dir_for(NEWUI_ROOT));
}
if (!defined('FE_KEYS_DIR_LEGACY')) {
    define('FE_KEYS_DIR_LEGACY', fe_legacy_keys_dir_for(NEWUI_ROOT));
}
define('FE_PRIVATE_KEY', FE_KEYS_DIR . '/private.pem');
define('FE_PUBLIC_KEY',  FE_KEYS_DIR . '/public.pem');

/**
 * Put deny rules beside the keys, wherever they are.
 *
 * Unconditional — unlike backup_harden_dir(), which fences only a directory
 * that looks published. A private key has no legitimate reachable-over-HTTP
 * state anywhere, the cost is two inert files, and the whole history of this
 * constant is that "outside the web root" was a belief about a layout rather
 * than a fact about the machine.
 *
 * Called before the early return in fe_ensure_keys() on purpose: the installs
 * that need this most are the ones whose keys already exist in a served
 * directory, and those never reach the generation path at all.
 */
function fe_harden_keys_dir(): void
{
    served_dir_harden(FE_KEYS_DIR, 'TicketsCAD encryption keys', true);
}

/**
 * One sentence an administrator can act on when a key file cannot be written.
 *
 * "Check directory permissions" was accurate and led straight to the wrong
 * action: on the reported install the only thing keeping the 2FA key out of a
 * directory published on port 80 was that IIS could not write there. Nothing in
 * the UI or the error named the path, so granting write access looked like the
 * fix. Name the directory, and say when it is one nothing should be written to.
 */
function fe_keys_dir_hint(?string $dir = null): string
{
    $dir = $dir ?? (defined('FE_KEYS_DIR') ? FE_KEYS_DIR : '');
    $msg = 'Key directory: ' . $dir;
    try {
        $x = served_dir_exposure($dir);
        if ($x['served'] || $x['suspect']) {
            $msg .= ' — WARNING: that directory is ' . $x['why']
                 . '. Do not grant write access to it. Set FE_KEYS_DIR in config.php to a '
                 . 'directory no web site publishes (' . FE_KEYS_DIR_DEFAULT . ') and move '
                 . 'any existing key files there; see docs/WEB-SERVER-HARDENING.md.';
        } else {
            $msg .= ' — the web server user needs write access to it.';
        }
    } catch (Throwable $e) {
        $msg .= ' — the web server user needs write access to it.';
    }
    return $msg;
}

// Maximum age (seconds) for encrypted payloads before rejection
define('FE_MAX_AGE', 120); // 2 minutes (tightened from 5 min to reduce replay window)

/**
 * Ensure RSA keypair exists. Generate if missing.
 * Called automatically by fe_get_public_key() on first use,
 * and can be called by the installer.
 *
 * @return bool TRUE on success
 */
function fe_ensure_keys()
{
    // Before the early return, not after it: an install whose keys already sit
    // in a published directory never reaches the generation path, and it is
    // exactly that install which needs the fence.
    fe_harden_keys_dir();

    if (file_exists(FE_PRIVATE_KEY) && file_exists(FE_PUBLIC_KEY)) {
        return true;
    }

    // Create keys directory if needed
    if (!is_dir(FE_KEYS_DIR)) {
        if (!@mkdir(FE_KEYS_DIR, 0700, true)) {
            error_log('field-encrypt: Cannot create keys directory: ' . fe_keys_dir_hint());
            return false;
        }
        fe_harden_keys_dir();
    }

    return fe_generate_keypair();
}

/**
 * Generate a new RSA 2048-bit keypair.
 * Archives old keys before overwriting (if they exist).
 *
 * @return bool TRUE on success
 */
function fe_generate_keypair()
{
    // Archive existing keys before overwriting
    fe_archive_keys();

    $config = array(
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'digest_alg'       => 'sha256',
    );

    // XAMPP on Windows needs explicit path to openssl.cnf.
    //
    // PHP_BINARY is the calling SAPI's OWN executable, not PHP's directory —
    // found 2026-08-06 chasing the identical bug in inc/vapid-keygen.php by
    // actually clicking a button in a browser against Apache rather than
    // only running CLI tests. Under apache2handler on XAMPP, PHP_BINARY is
    // Apache's httpd.exe, so dirname(PHP_BINARY) pointed at Apache's own bin
    // directory and neither path below it ever existed — this fallback had
    // never actually worked through a real request. php_ini_loaded_file()
    // does not have this problem: every SAPI, CLI included, loads a php.ini
    // from inside PHP's own directory.
    $phpDirs = array();
    $iniFile = php_ini_loaded_file();
    if ($iniFile !== false && $iniFile !== '') {
        $phpDirs[] = dirname($iniFile);
    }
    $phpDirs[] = dirname(PHP_BINARY);

    $cnfPaths = array(getenv('OPENSSL_CONF'));
    foreach ($phpDirs as $phpDir) {
        $cnfPaths[] = $phpDir . '/extras/ssl/openssl.cnf';
        $cnfPaths[] = $phpDir . '/../apache/conf/openssl.cnf';
    }
    foreach ($cnfPaths as $cnf) {
        if ($cnf && file_exists($cnf)) {
            $config['config'] = $cnf;
            break;
        }
    }

    $res = @openssl_pkey_new($config);
    if ($res === false) {
        error_log('field-encrypt: openssl_pkey_new() failed: ' . openssl_error_string());
        return false;
    }

    // Export private key
    if (!openssl_pkey_export($res, $privPem, null, $config)) {
        error_log('field-encrypt: openssl_pkey_export() failed: ' . openssl_error_string());
        return false;
    }

    // Extract public key
    $details = openssl_pkey_get_details($res);
    if (!$details || empty($details['key'])) {
        error_log('field-encrypt: openssl_pkey_get_details() failed');
        return false;
    }
    $pubPem = $details['key'];

    // Create keys directory if needed
    if (!is_dir(FE_KEYS_DIR)) {
        if (!@mkdir(FE_KEYS_DIR, 0700, true)) {
            error_log('field-encrypt: Cannot create keys directory: ' . fe_keys_dir_hint());
            return false;
        }
    }
    fe_harden_keys_dir();

    // Write keys to disk.
    // The @ suppression prevents PHP from emitting a "Failed to open stream"
    // Warning into the page when the keys directory is not writable by the
    // web server user. The function already error_logs the failure and
    // returns false, so the warning was redundant — and crucially, a stray
    // warning rendered into <body> on the login page (which uses flex
    // centering) breaks the layout. Fixed 2026-05-20 after Eric flagged
    // an off-centre login form on the your-server.example.com install.
    if (@file_put_contents(FE_PRIVATE_KEY, $privPem) === false) {
        error_log('field-encrypt: Cannot write private key to ' . FE_PRIVATE_KEY
            . ' — ' . fe_keys_dir_hint());
        return false;
    }
    if (@file_put_contents(FE_PUBLIC_KEY, $pubPem) === false) {
        error_log('field-encrypt: Cannot write public key to ' . FE_PUBLIC_KEY);
        return false;
    }

    // Set restrictive permissions (no-op on Windows but correct for Linux)
    // Permissions are intentionally restrictive: 0600 private, 0644 public, 0700 dir
    @chmod(FE_PRIVATE_KEY, 0600); // NOSONAR — 0600 is the correct restrictive permission for private keys
    @chmod(FE_PUBLIC_KEY, 0644);  // NOSONAR — 0644 is correct: public key needs to be readable
    @chmod(FE_KEYS_DIR, 0700);    // NOSONAR — 0700 restricts key directory to owner only

    return true;
}

/**
 * Read the public key PEM. Auto-generates keys if missing.
 *
 * @return string|false PEM string, or FALSE on error
 */
function fe_get_public_key()
{
    if (!file_exists(FE_PUBLIC_KEY)) {
        if (!fe_ensure_keys()) {
            return false;
        }
    }
    $pem = @file_get_contents(FE_PUBLIC_KEY);
    return ($pem !== false && strpos($pem, '-----BEGIN PUBLIC KEY-----') !== false) ? $pem : false;
}

/**
 * Read the private key PEM.
 *
 * @return string|false PEM string, or FALSE on error
 */
function fe_get_private_key()
{
    if (!file_exists(FE_PRIVATE_KEY)) {
        return false;
    }
    return @file_get_contents(FE_PRIVATE_KEY);
}

/**
 * Check if keys exist and are valid.
 *
 * @return array Status info: [exists => bool, valid => bool, created => string|null]
 */
function fe_key_status()
{
    $status = array(
        'exists'  => false,
        'valid'   => false,
        'created' => null,
    );

    if (file_exists(FE_PRIVATE_KEY) && file_exists(FE_PUBLIC_KEY)) {
        $status['exists'] = true;
        $status['created'] = date('Y-m-d H:i:s', filemtime(FE_PRIVATE_KEY));

        // Validate the keypair works
        $pubPem = @file_get_contents(FE_PUBLIC_KEY);
        $privPem = @file_get_contents(FE_PRIVATE_KEY);
        if ($pubPem && $privPem) {
            $testData = 'fe_validation_test';
            $encrypted = '';
            $pubKey = openssl_pkey_get_public($pubPem);
            if ($pubKey && openssl_public_encrypt($testData, $encrypted, $pubKey, OPENSSL_PKCS1_OAEP_PADDING)) {
                $decrypted = '';
                $privKey = openssl_pkey_get_private($privPem);
                if ($privKey && openssl_private_decrypt($encrypted, $decrypted, $privKey, OPENSSL_PKCS1_OAEP_PADDING)) {
                    $status['valid'] = ($decrypted === $testData);
                }
            }
        }
    }

    return $status;
}

/**
 * Detect if the current request is over HTTPS.
 *
 * Thin alias kept for the many existing callers. The logic now lives in
 * inc/https.php so every site in the tree agrees — see that file for why
 * (Ron Jones / @rjonesbsink, 2026-08-02).
 *
 * This is the BEST-EFFORT variant: it believes X-Forwarded-Proto from
 * any peer, which is right for its callers (deciding whether to bother
 * with field encryption, building URLs) and wrong for an access gate.
 * A gate wants is_https_verified().
 *
 * @return bool
 */
function fe_is_https()
{
    return is_https();
}

/**
 * Should field encryption be active for this request?
 * Returns TRUE if NOT on HTTPS and the admin toggle is enabled.
 *
 * @return bool
 */
function fe_should_encrypt()
{
    // If already on HTTPS, no need for field encryption
    if (fe_is_https()) {
        return false;
    }

    // Check admin toggle (default: on)
    $setting = get_setting('field_encrypt_enabled', '1');
    return ($setting === '1');
}

/**
 * Decrypt a base64-encoded RSA-OAEP ciphertext.
 *
 * @param string $encryptedBase64 Base64-encoded ciphertext
 * @return string|false Plaintext, or FALSE on error
 */
function fe_decrypt($encryptedBase64)
{
    $privPem = fe_get_private_key();
    if (!$privPem) {
        error_log('field-encrypt: Private key not available');
        return false;
    }

    $privKey = openssl_pkey_get_private($privPem);
    if (!$privKey) {
        error_log('field-encrypt: Cannot load private key: ' . openssl_error_string());
        return false;
    }

    $ciphertext = base64_decode($encryptedBase64, true);
    if ($ciphertext === false) {
        error_log('field-encrypt: Invalid base64');
        return false;
    }

    $decrypted = '';
    if (!openssl_private_decrypt($ciphertext, $decrypted, $privKey, OPENSSL_PKCS1_OAEP_PADDING)) {
        error_log('field-encrypt: Decryption failed: ' . openssl_error_string());
        return false;
    }

    return $decrypted;
}

/**
 * Decrypt a field value transparently.
 * Supports two formats:
 *   "ENC:"  — Legacy direct RSA-OAEP (base64 ciphertext)
 *   "ENC2:" — Hybrid RSA+AES-GCM (wrappedKeyLen + wrappedKey + iv + ciphertext)
 *
 * If the value has no prefix, it's returned as-is (plaintext pass-through).
 *
 * @param string $fieldValue Raw field value from form submission
 * @return string|false Plaintext value, or FALSE if decryption/validation fails
 */
function fe_decrypt_field($fieldValue)
{
    // Hybrid AES-GCM format
    if (strpos($fieldValue, 'ENC2:') === 0) {
        return fe_decrypt_hybrid(substr($fieldValue, 5));
    }

    // Legacy direct RSA format
    if (strpos($fieldValue, 'ENC:') === 0) {
        return fe_decrypt_legacy(substr($fieldValue, 4));
    }

    // Not encrypted — pass through
    return $fieldValue;
}

/**
 * Decrypt hybrid RSA+AES-GCM payload.
 * Format: base64( wrappedKeyLen(2 bytes BE) | wrappedKey | iv(12) | aesCiphertext+tag )
 */
function fe_decrypt_hybrid($encBase64)
{
    $privPem = fe_get_private_key();
    if (!$privPem) {
        error_log('field-encrypt: Private key not available');
        return false;
    }

    $raw = base64_decode($encBase64, true);
    if ($raw === false || strlen($raw) < 50) { // min: 2 + 32 + 12 + tag(16) = 62, but be lenient
        error_log('field-encrypt: Invalid hybrid payload (too short)');
        return false;
    }

    // Parse wrapped key length (2 bytes, big-endian)
    $wrappedKeyLen = (ord($raw[0]) << 8) | ord($raw[1]);
    if ($wrappedKeyLen < 128 || $wrappedKeyLen > 512) {
        error_log('field-encrypt: Invalid wrapped key length: ' . $wrappedKeyLen);
        return false;
    }

    if (strlen($raw) < 2 + $wrappedKeyLen + 12 + 16) {
        error_log('field-encrypt: Payload too short for declared key length');
        return false;
    }

    $wrappedKey    = substr($raw, 2, $wrappedKeyLen);
    $iv            = substr($raw, 2 + $wrappedKeyLen, 12);
    $aesCiphertext = substr($raw, 2 + $wrappedKeyLen + 12); // includes GCM auth tag

    // Unwrap the AES key with RSA-OAEP
    $privKey = openssl_pkey_get_private($privPem);
    if (!$privKey) {
        error_log('field-encrypt: Cannot load private key');
        return false;
    }

    $aesKeyRaw = '';
    if (!openssl_private_decrypt($wrappedKey, $aesKeyRaw, $privKey, OPENSSL_PKCS1_OAEP_PADDING)) {
        error_log('field-encrypt: RSA unwrap failed: ' . openssl_error_string());
        return false;
    }

    if (strlen($aesKeyRaw) !== 32) {
        error_log('field-encrypt: Unwrapped AES key wrong size: ' . strlen($aesKeyRaw));
        return false;
    }

    // AES-256-GCM: last 16 bytes of ciphertext are the auth tag
    if (strlen($aesCiphertext) < 16) {
        error_log('field-encrypt: AES ciphertext too short');
        return false;
    }

    $tagLen = 16;
    $tag       = substr($aesCiphertext, -$tagLen);
    $encrypted = substr($aesCiphertext, 0, -$tagLen);

    $json = openssl_decrypt($encrypted, 'aes-256-gcm', $aesKeyRaw, OPENSSL_RAW_DATA, $iv, $tag);
    if ($json === false) {
        error_log('field-encrypt: AES-GCM decryption failed (auth tag mismatch or corrupted)');
        return false;
    }

    return fe_validate_envelope($json);
}

/**
 * Decrypt legacy direct RSA-OAEP payload.
 */
function fe_decrypt_legacy($encBase64)
{
    $json = fe_decrypt($encBase64);
    if ($json === false) {
        return false;
    }
    return fe_validate_envelope($json);
}

/**
 * Validate the decrypted JSON envelope (timestamp + nonce).
 * Shared by both legacy and hybrid decryption paths.
 *
 * @param string $json  Decrypted JSON string
 * @return string|false Plaintext value, or FALSE on validation failure
 */
function fe_validate_envelope($json)
{
    $envelope = json_decode($json, true);
    if (!is_array($envelope) || !isset($envelope['value']) || !isset($envelope['ts']) || !isset($envelope['nonce'])) {
        error_log('field-encrypt: Invalid envelope structure');
        return false;
    }

    // Validate timestamp (within FE_MAX_AGE seconds)
    $tsSeconds = (int)($envelope['ts'] / 1000); // JS Date.now() is milliseconds
    $age = time() - $tsSeconds;
    if ($age < -30 || $age > FE_MAX_AGE) {
        error_log('field-encrypt: Payload expired or clock skew (age=' . $age . 's)');
        return false;
    }

    // Validate nonce format (hex string)
    if (!preg_match('/^[0-9a-f]{32}$/', $envelope['nonce'])) {
        error_log('field-encrypt: Invalid nonce format');
        return false;
    }

    return $envelope['value'];
}

/**
 * Inject the field encryption JavaScript into the page.
 * Only outputs when fe_should_encrypt() is true.
 *
 * @return string HTML <script> tags, or empty string if not needed
 */
function fe_inject_js()
{
    if (!fe_should_encrypt()) {
        return '';
    }

    $pubPem = fe_get_public_key();
    if (!$pubPem) {
        return '<!-- field-encrypt: key generation failed -->';
    }

    // Escape for embedding in JS string
    $pubPemJs = str_replace(array("\r\n", "\r", "\n"), '\\n', $pubPem);
    $pubPemJs = str_replace("'", "\\'", $pubPemJs);

    $html = '<script src="assets/js/field-encrypt.js?v=' . asset_v('assets/js/field-encrypt.js') . '"></script>' . "\n";
    $html .= '<script>' . "\n";
    $html .= '(function () {' . "\n";
    $html .= '    "use strict";' . "\n";
    $html .= '    if (window.FieldEncrypt) {' . "\n";
    $html .= '        window.FieldEncrypt.init(\'' . $pubPemJs . '\').then(function () {' . "\n";
    $html .= '            window.FieldEncrypt.autoProtect();' . "\n";
    $html .= '        });' . "\n";
    $html .= '    }' . "\n";
    $html .= '})();' . "\n";
    $html .= '</script>' . "\n";

    return $html;
}

/**
 * Archive existing RSA keys before regeneration.
 * Creates timestamped copies in the keys directory's archive/ so old keys
 * are recoverable if any stored data was encrypted with them.
 *
 * @return bool TRUE if archive succeeded (or no keys to archive)
 */
function fe_archive_keys()
{
    if (!file_exists(FE_PRIVATE_KEY) || !file_exists(FE_PUBLIC_KEY)) {
        return true; // Nothing to archive
    }

    $archiveDir = FE_KEYS_DIR . '/archive';
    if (!is_dir($archiveDir)) {
        if (!@mkdir($archiveDir, 0700, true)) {
            error_log('field-encrypt: Cannot create archive directory: ' . $archiveDir);
            return false;
        }
    }

    $timestamp = date('Y-m-d-His');
    $privArchive = $archiveDir . '/private-' . $timestamp . '.pem';
    $pubArchive  = $archiveDir . '/public-' . $timestamp . '.pem';

    $ok = @copy(FE_PRIVATE_KEY, $privArchive) && @copy(FE_PUBLIC_KEY, $pubArchive);
    if ($ok) {
        @chmod($privArchive, 0600);
        // Phase 41 (Sonar S2612): tighten public-key archive to 0640.
        // The public key is not secret (it verifies signatures), but
        // there's no operational need for world-read, and 0640 satisfies
        // the chmod-mask scanner without affecting any caller. The
        // matching private archive is at 0600.
        @chmod($pubArchive, 0640); // NOSONAR
    } else {
        error_log('field-encrypt: Failed to archive keys');
    }

    return $ok;
}
