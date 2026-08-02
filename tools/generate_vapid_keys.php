<?php
/**
 * VAPID keypair generator for Phase 96 Web Push.
 *
 * One-time admin setup. Generates a P-256 ECDSA keypair (the only
 * algorithm Web Push allows for VAPID per RFC 8292). Public key is
 * base64url-encoded uncompressed point (65 bytes); private key is
 * base64url-encoded raw scalar (32 bytes).
 *
 * Output is paste-ready for Settings → Push Notifications →
 * VAPID Public Key / VAPID Private Key fields.
 *
 * Usage:  php tools/generate_vapid_keys.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/vapid-keygen.php';

// GH TicketsCAD#8: prefer the library, but fall back to generating with an
// explicit openssl.cnf on hosts whose OpenSSL cannot find its own — stock
// Windows PHP, where this used to die with "Unable to create the key".
try {
    $keys = vapid_generate_keypair();
} catch (Throwable $e) {
    fwrite(STDERR, "\n" . $e->getMessage() . "\n\n");
    exit(1);
}

echo "\n";
echo "VAPID keypair generated.\n";
echo "========================\n\n";

if (($keys['via'] ?? '') === 'openssl-direct') {
    echo "Note: this host's OpenSSL could not find its own configuration file,\n";
    echo "so the key was generated using:\n";
    echo "  " . ($keys['config'] ?? '(located openssl.cnf)') . "\n";
    echo "The key is valid and nothing further is required for Web Push, but\n";
    echo "other OpenSSL-dependent features may hit the same thing — see\n";
    echo "docs/INSTALL-WINDOWS-IIS.md.\n\n";
}
echo "Paste both values into Settings → Push Notifications, then\n";
echo "set push_vapid_subject to a 'mailto:' contact (required by RFC 8292)\n";
echo "and flip push_enabled to 1.\n\n";

echo "Public key:\n";
echo $keys['publicKey'] . "\n\n";

echo "Private key:\n";
echo $keys['privateKey'] . "\n\n";

echo "Or run the SQL directly:\n";
$pubEsc = addslashes($keys['publicKey']);
$privEsc = addslashes($keys['privateKey']);
echo "  UPDATE settings SET value='{$pubEsc}' WHERE name='push_vapid_public_key';\n";
echo "  UPDATE settings SET value='{$privEsc}' WHERE name='push_vapid_private_key';\n";
echo "  UPDATE settings SET value='mailto:admin@example.com' WHERE name='push_vapid_subject';\n";
echo "  UPDATE settings SET value='1' WHERE name='push_enabled';\n\n";

echo "WARNING: store the private key securely. If it leaks, attackers\n";
echo "can send pushes that appear to come from your TicketsCAD install\n";
echo "(though they can't decrypt subscribers' data without their own\n";
echo "subscription).\n";
