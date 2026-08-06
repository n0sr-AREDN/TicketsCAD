<?php
/**
 * Composer post-install/post-update hook (GH #31).
 *
 * The problem this patches: minishlink/web-push generates a fresh EC key for
 * EVERY push message, via a private static method that calls
 * openssl_pkey_new(['curve_name' => 'prime256v1', ...]) with no explicit
 * config path. On a host where OpenSSL cannot find its own config file
 * (stock Windows PHP is the common case — see inc/vapid-keygen.php), that
 * call fails every time, forever — not just once at setup like keypair
 * generation did before GH #8.
 *
 * Why a patch rather than app-level code: the failing call is inside vendor
 * code we do not control, it is a PRIVATE method (subclassing cannot
 * override it — self:: always resolves to the defining class), and
 * putenv('OPENSSL_CONF=...') from PHP userland does NOT work around it —
 * verified directly: this stack's OpenSSL resolves its default config at
 * process start, before any request code runs, so a mid-request putenv()
 * has no effect on it. Setting the OS-level environment variable before
 * PHP starts DOES work, but that is a deployment concern this patch does
 * not depend on — it fixes the call site directly instead, using the exact
 * same config-file candidates inc/vapid-keygen.php already searches, so
 * there is ONE place in the codebase that knows how to find openssl.cnf.
 *
 * This targets inc/vapid-keygen.php's vapid_find_openssl_conf(), guarded by
 * function_exists() so the library degrades to its original behaviour if it
 * is ever used completely outside TicketsCAD.
 *
 * Idempotent: re-running composer install/update does not double-patch.
 * Fails loudly (non-zero exit) if a future minishlink/web-push release
 * changes this method enough that neither the original text nor our patch
 * marker is found — a build that silently keeps shipping the unpatched
 * vendor file is worse than one that stops and says why.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$target = __DIR__ . '/../vendor/minishlink/web-push/src/Encryption.php';

if (!is_file($target)) {
    // composer install hasn't populated vendor/ yet, or web-push isn't
    // required at all in this checkout. Nothing to patch; nothing to fail.
    echo "patch_vendor_webpush: $target not present, skipping.\n";
    exit(0);
}

$src = file_get_contents($target);
if ($src === false) {
    fwrite(STDERR, "patch_vendor_webpush: could not read $target\n");
    exit(1);
}

$marker = '// TicketsCAD GH#31: resolve this host\'s openssl.cnf explicitly';

if (strpos($src, $marker) !== false) {
    echo "patch_vendor_webpush: already applied, skipping.\n";
    exit(0);
}

$original = <<<'PHP'
    private static function createLocalKeyObject(): array
    {
        $keyResource = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if (!$keyResource) {
            throw new \RuntimeException('Unable to create the local key.');
        }
PHP;

$patched = <<<'PHP'
    private static function createLocalKeyObject(): array
    {
        // TicketsCAD GH#31: resolve this host's openssl.cnf explicitly.
        // Keypair generation (inc/vapid-keygen.php, GH#8) already works
        // around a host that cannot find its OpenSSL config; this call is
        // the OTHER place the same failure occurs, once per message rather
        // than once at setup, and it shipped no workaround until now.
        // function_exists() guards against this library ever running
        // outside TicketsCAD, where inc/vapid-keygen.php would not be loaded.
        $opensslOptions = [
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ];
        if (function_exists('vapid_find_openssl_conf')) {
            $cnf = vapid_find_openssl_conf();
            if ($cnf !== null) {
                $opensslOptions['config'] = $cnf;
            }
        }
        $keyResource = openssl_pkey_new($opensslOptions);
        if (!$keyResource) {
            throw new \RuntimeException('Unable to create the local key.');
        }
PHP;

$count = 0;
$out = str_replace($original, $patched, $src, $count);

if ($count !== 1) {
    fwrite(STDERR,
        "patch_vendor_webpush: FAILED — expected exactly one occurrence of the \n" .
        "known createLocalKeyObject() body in $target, found $count.\n" .
        "minishlink/web-push has likely changed this method. The GH#31 fix \n" .
        "(TicketsCAD's own installs failing to send push notifications on \n" .
        "hosts without a working OPENSSL_CONF) is NOT applied. Update this \n" .
        "script's expected text to match the new vendor source before \n" .
        "proceeding — see tools/patch_vendor_webpush.php.\n"
    );
    exit(1);
}

if (file_put_contents($target, $out) === false) {
    fwrite(STDERR, "patch_vendor_webpush: could not write $target\n");
    exit(1);
}

echo "patch_vendor_webpush: applied to $target\n";
exit(0);
