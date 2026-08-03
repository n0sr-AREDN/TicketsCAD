<?php
/**
 * Gate: the RSA private key and the 2FA key must not sit in a directory a web
 * server publishes — and fixing that must not orphan the keys an install
 * already has.
 *
 * ── WHAT HAPPENED (GHSA-3jmh-c6f6-64jc) ──────────────────────────────
 *
 * Until 4.2.4, inc/field-encrypt.php said, unconditionally:
 *
 *     define('FE_KEYS_DIR', NEWUI_ROOT . '/../keys');
 *
 * with the intent stated in docs/UPDATE-CHECKLIST.md: "one level ABOVE the
 * install directory, on purpose … so the private key is not HTTP-reachable".
 *
 * That reasoning holds on Linux (/var/www/newui → /var/www) and INVERTS on a
 * standard Windows/IIS install, where sites are subdirectories of a *served*
 * C:\inetpub\wwwroot — so C:\inetpub\wwwroot\TicketsV4\..\keys is
 * C:\inetpub\wwwroot\keys, inside Default Web Site, bound to port 80.
 * @rjonesbsink proved the directory was being served:
 *
 *     GET http://localhost/keys/_probe.txt   ->  200  "control-file"
 *     GET http://localhost/keys/private.pem  ->  404.3 (MIME type restriction)
 *
 * The .pem refusal is an accident of file naming, not a control: IIS has no
 * MIME mapping for .pem, any mapped extension in that directory IS served, and
 * Apache — same layout, same reasoning — serves .pem as plain text. The
 * directory had no web.config and no .htaccess.
 *
 * It is the same root cause as commit 5b88fbb (BACKUP_DIR) and the same as the
 * 2026-07-30 in-tree exposure. Third time.
 *
 * ── WHAT THIS FILE ASSERTS ───────────────────────────────────────────
 *
 *   1. The Windows default is not inside inetpub\wwwroot or xampp\htdocs, on
 *      any of the real layouts reported.
 *   2. The POSIX default is unchanged — it was correct there, and it is the
 *      same directory the previous version used, so a Linux upgrade is a no-op.
 *   3. THE CRITICAL ONE: an install whose keys are already in the historical
 *      location keeps using them, in place, with no operator action. A missed
 *      backup is an inconvenience; a missed tfa.key locks every 2FA user out
 *      of the system with no way back.
 *   4. The FE_KEYS_DIR define is guarded, so config.php can override it —
 *      driven through a real config.php in a real second process, because the
 *      guard is the whole mechanism and PHP cannot redefine a constant.
 *   5. A keys directory inside a served tree is REPORTED, by the real
 *      health_check_keys() with a real directory, and nothing is relocated.
 *   6. The deny files written beside the keys are the shape this project
 *      standardised in cb2db27 (Request Filtering, never <authorization>).
 *   7. The remediation text is platform-correct and copy-verify-delete, in
 *      that order.
 *
 * Usage: php tests/test_fe_keys_dir_platform.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/served-dir.php';
require_once __DIR__ . '/../inc/field-encrypt.php';
require_once __DIR__ . '/../inc/backup.php';
require_once __DIR__ . '/../inc/health-check.php';

$passed = 0;
$failed = 0;

function test($label, $condition, $hint = '') {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $label\n";
        $passed++;
    } else {
        echo "[FAIL] $label" . ($hint !== '' ? " — $hint" : '') . "\n";
        $failed++;
    }
}

$root = defined('NEWUI_ROOT') ? NEWUI_ROOT : dirname(__DIR__);
$n = function (string $p): string { return rtrim(str_replace('\\', '/', $p), '/'); };

echo "=== Encryption keys: platform-correct location, without orphaning keys ===\n\n";

// ─────────────────────────────────────────────────────────────────────
echo "-- 1. The Windows default never lands in a site root --\n";

// The layouts @rjonesbsink reported, plus XAMPP, which has the identical shape.
$winInstalls = [
    'C:\\inetpub\\wwwroot\\TicketsV4',
    'C:\\inetpub\\wwwroot\\ticketscad',
    'C:\\inetpub\\wwwroot\\phpMyAdmin',
    'C:\\xampp\\htdocs\\newui',
    'D:\\sites\\ticketscad',
];
foreach ($winInstalls as $app) {
    $d  = fe_default_keys_dir_for($app, true);
    $dN = $n($d);
    test("Windows keys default for $app is not inside inetpub\\wwwroot",
        stripos($dN, '/inetpub/wwwroot') === false, 'got ' . $d);
    test("Windows keys default for $app is not inside xampp\\htdocs",
        stripos($dN, '/xampp/htdocs') === false, 'got ' . $d);
    test("Windows keys default for $app is not inside the application tree",
        strpos($dN . '/', $n($app) . '/') !== 0, 'got ' . $d);
    test("Windows keys default for $app is not the old sibling-of-install path",
        $dN !== $n(fe_legacy_keys_dir_for($app, true)), 'got ' . $d);
    test("Windows keys default for $app is an absolute Windows path",
        preg_match('/^[A-Za-z]:\\\\/', $d) === 1, 'got ' . $d);
    test("Windows keys default for $app uses backslashes throughout",
        strpos($d, '/') === false, 'got ' . $d);
}

test('the Windows keys default is under ProgramData',
    stripos($n(fe_default_keys_dir_for('C:\\inetpub\\wwwroot\\TicketsV4', true)),
            '/programdata/ticketscad/keys') !== false,
    fe_default_keys_dir_for('C:\\inetpub\\wwwroot\\TicketsV4', true));
$pdBase = served_dir_program_data() . '\\TicketsCAD\\';
test('…under the same %ProgramData%\\TicketsCAD base the backup fix chose, '
    . 'so an operator has one place to look',
    strpos(fe_default_keys_dir_for('C:\\x\\y', true), $pdBase) === 0
    && strpos(backup_default_dir_for('C:\\x\\y', true), $pdBase) === 0,
    fe_default_keys_dir_for('C:\\x\\y', true) . ' vs ' . backup_default_dir_for('C:\\x\\y', true));

// The exact directory the report was about.
test('the OLD rule put the keys in C:\\inetpub\\wwwroot\\keys — the reported state',
    $n(fe_legacy_keys_dir_for('C:\\inetpub\\wwwroot\\TicketsV4', true))
    === 'C:/inetpub/wwwroot/keys',
    fe_legacy_keys_dir_for('C:\\inetpub\\wwwroot\\TicketsV4', true));
$wx = served_dir_exposure('C:\\inetpub\\wwwroot\\keys');
$sd = getenv('SystemDrive');
$sd = ($sd !== false && trim((string) $sd) !== '') ? rtrim((string) $sd, '\\/') : 'C:';
if (strcasecmp($sd, 'C:') === 0) {
    test('…and that directory is graded CERTAIN exposure, not merely suspect',
        $wx['served'] === true && $wx['state'] === 'in_default_site_root',
        'state=' . $wx['state']);
} else {
    echo "[SKIP] %SystemDrive% is not C: on this machine — the literal-path grading is not exercised\n";
}

// Determinism: the DEFAULT must not vary with what is on disk. (The RESOLVER
// deliberately does look at the disk — that is section 3 — but the per-platform
// default it chooses between must be a pure function.)
test('the platform default is deterministic — same input, same answer',
    fe_default_keys_dir_for('C:\\inetpub\\wwwroot\\TicketsV4', true)
    === fe_default_keys_dir_for('C:\\inetpub\\wwwroot\\TicketsV4', true));
test('the platform default does not consult the filesystem',
    preg_match('/function fe_default_keys_dir_for.*?\n\}/s',
        (string) file_get_contents($root . '/inc/field-encrypt.php'), $m) === 1
    && strpos($m[0], 'is_dir') === false
    && strpos($m[0], 'file_exists') === false
    && strpos($m[0], 'realpath') === false,
    'the default is what a fresh install gets; only the legacy CHECK reads the disk');

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 2. The POSIX default is unchanged (it was right there) --\n";
test('/var/www/newui  → /var/www/keys',
    fe_default_keys_dir_for('/var/www/newui', false) === '/var/www/keys');
test('/srv/ticketscad → /srv/keys',
    fe_default_keys_dir_for('/srv/ticketscad', false) === '/srv/keys');
test('on POSIX the default IS the historical location, so an upgrade moves nothing',
    fe_default_keys_dir_for('/var/www/newui', false)
    === fe_legacy_keys_dir_for('/var/www/newui', false));
// …and it is the SAME directory the pre-4.2.4 string named, so no POSIX install
// has to do anything. Collapsing the "/x/../" segment is the whole claim.
test('…and it is byte-for-byte the directory the old NEWUI_ROOT/../keys resolved to',
    preg_replace('#/[^/]+/\.\./#', '/', '/var/www/newui/../keys')
    === fe_default_keys_dir_for('/var/www/newui', false));

test('FE_KEYS_DIR on THIS machine matches the resolver',
    defined('FE_KEYS_DIR') && FE_KEYS_DIR === fe_keys_dir_for($root),
    'FE_KEYS_DIR=' . (defined('FE_KEYS_DIR') ? FE_KEYS_DIR : 'undefined'));
test('FE_KEYS_DIR is outside the application tree',
    defined('FE_KEYS_DIR') && strpos($n(FE_KEYS_DIR) . '/', $n($root) . '/') !== 0,
    'FE_KEYS_DIR=' . (defined('FE_KEYS_DIR') ? FE_KEYS_DIR : 'undefined'));
test('the derived key paths follow FE_KEYS_DIR',
    FE_PRIVATE_KEY === FE_KEYS_DIR . '/private.pem'
    && FE_PUBLIC_KEY === FE_KEYS_DIR . '/public.pem');

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 3. THE CRITICAL ONE: existing keys are found where they are --\n";
// Driven with two REAL directories through the real resolver and the real
// file-existence oracle. Taking the two paths (rather than an app root and a
// platform flag) is what makes this runnable on Linux at all: there the two
// would be the same directory and the only branch that matters — "the legacy
// location holds keys" — could never be exercised on the CI machine.

$sandbox = sys_get_temp_dir() . '/tcad-keys-' . getmypid();
$legacy  = $sandbox . '/legacy';
$fresh   = $sandbox . '/programdata';
@mkdir($legacy, 0777, true);
@mkdir($fresh, 0777, true);

if (!is_dir($legacy) || !is_dir($fresh)) {
    echo "[SKIP] filesystem sandbox could not be created — the upgrade path is not exercised\n";
} else {
    test('an empty historical directory is NOT key material (nothing to lose)',
        fe_dir_holds_keys($legacy) === false);
    test('…so a fresh install goes to the new default',
        $n(fe_keys_dir_resolve($legacy, $fresh)) === $n($fresh));

    // Each of the three files, on its own, must pin the directory. An install
    // that never enabled field encryption has only tfa.key; an install on HTTPS
    // that never enrolled 2FA has only the pems. Both must keep working.
    foreach (['private.pem' => "-----BEGIN PRIVATE KEY-----\n",
              'public.pem'  => "-----BEGIN PUBLIC KEY-----\n",
              'tfa.key'     => str_repeat("\x01", 32)] as $file => $body) {
        @file_put_contents($legacy . '/' . $file, $body);
        test("an install holding only $file keeps using the directory it is in",
            fe_dir_holds_keys($legacy) === true
            && $n(fe_keys_dir_resolve($legacy, $fresh)) === $n($legacy),
            'resolved to ' . fe_keys_dir_resolve($legacy, $fresh));
        @unlink($legacy . '/' . $file);
    }

    // The half-moved state. Preferring the NEW directory here would decrypt
    // nothing if the two keys differ — every enrolled authenticator dead. The
    // rule is asymmetric on purpose.
    @file_put_contents($legacy . '/tfa.key', str_repeat("\x01", 32));
    @file_put_contents($fresh . '/tfa.key', str_repeat("\x02", 32));
    test('with keys in BOTH places the historical one still wins (no 2FA lockout)',
        $n(fe_keys_dir_resolve($legacy, $fresh)) === $n($legacy),
        'resolved to ' . fe_keys_dir_resolve($legacy, $fresh));

    // …and once the operator finishes the move, the default takes over with no
    // config change. That is what makes the remediation instructions true.
    @unlink($legacy . '/tfa.key');
    test('once the old directory is emptied, the new default takes over by itself',
        $n(fe_keys_dir_resolve($legacy, $fresh)) === $n($fresh));
    @unlink($fresh . '/tfa.key');

    test('a directory that does not exist at all is not key material',
        fe_dir_holds_keys($sandbox . '/nope') === false);
    test('when the two paths are the same, the answer is that path (POSIX)',
        $n(fe_keys_dir_resolve($legacy, $legacy)) === $n($legacy));
}

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 4. config.php can override FE_KEYS_DIR (the define is guarded) --\n";

/**
 * Ask a FRESH PHP process, with constants defined BEFORE config.php loads —
 * which is the only way to test a guarded define(), since PHP cannot redefine
 * a constant and this process has already defined it.
 *
 * proc_open with an ARGV ARRAY, never a command string: this project gates
 * against shelling out (tests/test_no_shell_command_execution.php).
 */
function fek_fresh(string $root, array $defines, string $expr): ?array
{
    if (!function_exists('proc_open')) { return null; }
    $bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : null;
    if ($bin === null || !@is_file($bin)) { return null; }

    $pre = '';
    foreach ($defines as $k => $v) {
        $pre .= 'define(' . var_export($k, true) . ', ' . var_export($v, true) . ');';
    }
    $code = '<?php ' . $pre
          . ' require_once ' . var_export($root . '/config.php', true) . ';'
          // The order a real request has: config.php first (which is where an
          // operator's define lives), then whatever the page needs.
          . ' require_once ' . var_export($root . '/inc/field-encrypt.php', true) . ';'
          . ' require_once ' . var_export($root . '/inc/tfa.php', true) . ';'
          . ' require_once ' . var_export($root . '/inc/health-check.php', true) . ';'
          . ' ob_start(); $__v = (' . $expr . '); $__noise = ob_get_clean();'
          . ' echo "<<<FEK>>>" . json_encode($__v);';
    $tmp = sys_get_temp_dir() . '/tcad-fek-' . getmypid() . '-' . mt_rand() . '.php';
    if (@file_put_contents($tmp, $code) === false) { return null; }

    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open([$bin, '-d', 'display_errors=0', $tmp], $desc, $pipes);
    if (!is_resource($proc)) { @unlink($tmp); return null; }
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    @unlink($tmp);

    $at = strpos($out, '<<<FEK>>>');
    if ($at === false) { return null; }
    $json = json_decode(substr($out, $at + 9), true);
    return is_array($json) ? $json : null;
}

$override = $sandbox . '/operator-choice';
@mkdir($override, 0777, true);

$r = fek_fresh($root, ['FE_KEYS_DIR' => $override],
    '["dir" => FE_KEYS_DIR, "priv" => FE_PRIVATE_KEY, "tfa" => FE_KEYS_DIR . "/tfa.key",
      "default" => FE_KEYS_DIR_DEFAULT, "legacy" => FE_KEYS_DIR_LEGACY]');
if ($r === null) {
    echo "[SKIP] could not start a second PHP process — the config.php override is not exercised\n";
} else {
    test('a define() made before config.php wins (this did NOT work before 4.2.4)',
        $n((string) ($r['dir'] ?? '')) === $n($override), (string) ($r['dir'] ?? ''));
    test('…and the private key path follows it',
        $n((string) ($r['priv'] ?? '')) === $n($override) . '/private.pem');
    test('…and the 2FA key follows it too (inc/tfa.php must not guess separately)',
        $n((string) ($r['tfa'] ?? '')) === $n($override) . '/tfa.key');
    test('…while the built-in locations are still reported, for the Status page',
        !empty($r['default']) && !empty($r['legacy']));
}

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 5. A keys directory in a served tree is REPORTED, and nothing moves --\n";
// The real health_check_keys(), with FE_KEYS_DIR pointed at a directory that is
// unambiguously inside the served tree, in a fresh process. cache/ is
// gitignored and the sub-directory is removed below.

$exposedKeys = $root . '/cache/tcad-keys-test-' . getmypid();
@mkdir($exposedKeys, 0777, true);
@file_put_contents($exposedKeys . '/tfa.key', str_repeat("\x03", 32));

$r = fek_fresh($root, ['FE_KEYS_DIR' => $exposedKeys], 'health_check_keys()');
if ($r === null) {
    echo "[SKIP] could not start a second PHP process — health_check_keys() is not exercised\n";
} else {
    test('keys inside the served tree are reported CRITICAL',
        ($r['severity'] ?? '') === 'critical',
        'severity=' . ($r['severity'] ?? '?') . ' state=' . ($r['state'] ?? '?'));
    test('…the summary says the directory is published',
        stripos((string) ($r['summary'] ?? ''), 'published') !== false,
        (string) ($r['summary'] ?? ''));
    test('…the directory is named, so the operator knows where to look',
        strpos($n((string) ($r['summary'] ?? '')), $n($exposedKeys)) !== false
        && $n((string) ($r['active_dir'] ?? '')) === $n($exposedKeys),
        (string) ($r['active_dir'] ?? '?'));
    test('…the key files found there are listed by name',
        in_array('tfa.key', (array) ($r['key_files'] ?? []), true));
    test('…and a remedy is given', trim((string) ($r['remedy'] ?? '')) !== '');
    test('…which is correct for THIS platform',
        DIRECTORY_SEPARATOR === '\\'
            ? strpos((string) ($r['remedy'] ?? ''), 'mkdir -p') === false
            : strpos((string) ($r['remedy'] ?? ''), 'New-Item') === false);
    test('…and the blind spot is disclosed rather than implied away',
        trim((string) ($r['blind_spot'] ?? '')) !== '');
}

// NOTHING may be relocated automatically. A half-completed key move is worse
// than the exposure: it loses 2FA for everyone at once.
test('the key file was left exactly where it was — nothing is moved for the operator',
    is_file($exposedKeys . '/tfa.key'),
    'health_check_keys() must report, never relocate');
$feSrc = (string) file_get_contents($root . '/inc/field-encrypt.php');
$hcSrc = (string) file_get_contents($root . '/inc/health-check.php');
test('no code path renames or copies a key file between directories',
    preg_match('/\b(rename|copy)\s*\(\s*[^)]*FE_KEYS_DIR_LEGACY/', $feSrc . $hcSrc) !== 1
    && preg_match('/\b(rename|copy)\s*\([^)]*FE_KEYS_DIR_DEFAULT/', $feSrc . $hcSrc) !== 1);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 6. The deny files beside the keys are the standardised shape --\n";
// Driven through the real writer. cb2db27 settled on Request Filtering:
// <authorization> is an optional IIS role service, and a web.config naming a
// section whose module is absent makes IIS answer 500.19 — a deny by accident,
// which is what gets the file deleted and the exposure restored.

$fence = $sandbox . '/fence';
@mkdir($fence, 0777, true);
if (is_dir($fence)) {
    served_dir_harden($fence, 'TicketsCAD encryption keys', true);
    $wc = (string) @file_get_contents($fence . '/web.config');
    $ht = (string) @file_get_contents($fence . '/.htaccess');

    test('a web.config is written beside the keys', $wc !== '');
    test('it denies FILES, not just the listing (the .pem itself)',
        strpos($wc, '<fileExtensions allowUnlisted="false" />') !== false, $wc);
    test('it keeps directory browsing off as the independent second stop',
        strpos($wc, '<directoryBrowse enabled="false" />') !== false, $wc);
    test('it uses Request Filtering, never URL Authorization',
        strpos($wc, '<authorization') === false && strpos($wc, 'requestFiltering') !== false, $wc);
    test('it is well-formed XML',
        @simplexml_load_string($wc) !== false);
    test('an .htaccess is written too, because IIS is not the only server',
        $ht !== '' && strpos($ht, 'RewriteRule .* - [F,L]') !== false, $ht);
    test('the .htaccess names the directory for mod_alias as well as mod_rewrite',
        strpos($ht, 'RedirectMatch 404 (^|/)fence(/|$)') !== false, $ht);
    test('an existing deny file is never overwritten',
        (function () use ($fence) {
            @file_put_contents($fence . '/web.config', 'MINE');
            served_dir_harden($fence, 'TicketsCAD encryption keys', true);
            return trim((string) @file_get_contents($fence . '/web.config')) === 'MINE';
        })());

    // …and the backup caller keeps its old behaviour: fence only what looks
    // published. A deny file in a directory no server can see is noise.
    $quiet = $sandbox . '/quiet-backups';
    @mkdir($quiet, 0777, true);
    backup_harden_dir($quiet);
    $qx = served_dir_exposure($quiet);
    if (!$qx['served'] && !$qx['suspect']) {
        test('backup_harden_dir() still writes nothing where there is no evidence of exposure',
            !is_file($quiet . '/web.config') && !is_file($quiet . '/.htaccess'));
    } else {
        echo "[SKIP] the temp directory grades as suspect here — the negative case is not exercised\n";
    }
} else {
    echo "[SKIP] filesystem sandbox unavailable — the deny-file writer is not exercised\n";
}

// The keys writer must be unconditional: a private key has no legitimate
// reachable-over-HTTP state, so it is fenced wherever it lives.
test('fe_harden_keys_dir() fences unconditionally, not only when it looks published',
    preg_match('/served_dir_harden\(\s*FE_KEYS_DIR\s*,[^)]*true\s*\)/', $feSrc) === 1);
test('…and it runs BEFORE the early return in fe_ensure_keys()',
    preg_match('/function fe_ensure_keys\(\)\s*\{(.*?)if \(file_exists\(FE_PRIVATE_KEY\)/s',
        $feSrc, $m2) === 1 && strpos($m2[1], 'fe_harden_keys_dir()') !== false,
    'an install whose keys already exist never reaches the generation path');

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 7. Remediation text is platform-correct and safe to follow --\n";

$winText = health_keys_move_remedy('C:\\inetpub\\wwwroot\\keys', true);
$nixText = health_keys_move_remedy('/var/www/html/keys', false);

foreach (['mkdir -p', 'sudo ', 'chown', 'chmod', 'www-data'] as $bad) {
    test('Windows key remediation contains no `' . trim($bad) . '`',
        strpos($winText, $bad) === false);
}
test('Windows key remediation uses PowerShell verbs',
    strpos($winText, 'New-Item') !== false && strpos($winText, 'Copy-Item') !== false
    && strpos($winText, 'icacls') !== false);
test('Windows key remediation has no mixed path separators',
    preg_match('#[A-Za-z]:\\\\[^\s\']*/#', $winText) !== 1, $winText);
test('Windows key remediation never suggests inetpub\\wwwroot as a destination',
    preg_match('/New-Item[^\n]*inetpub\\\\wwwroot/i', $winText) !== 1);
test('Windows key remediation warns off C:\\inetpub\\wwwroot by name',
    stripos($winText, 'Do NOT use C:\\inetpub\\wwwroot') !== false);
test('POSIX key remediation contains no PowerShell',
    strpos($nixText, 'New-Item') === false && strpos($nixText, 'icacls') === false);

foreach (['windows' => $winText, 'posix' => $nixText] as $k => $txt) {
    // Copy, verify, THEN delete. The reverse order loses every 2FA enrollment
    // if anything goes wrong in the middle, and there is no recovery from that.
    $copyAt = max((int) strpos($txt, 'Copy-Item'), (int) strpos($txt, 'cp -p'));
    $delAt  = max((int) strpos($txt, 'Remove-Item'), (int) strpos($txt, 'rm -f'));
    test("[$k] the copy comes before the delete", $copyAt > 0 && $delAt > $copyAt);
    test("[$k] the reader is told to verify a 2FA login in between",
        stripos($txt, 'confirm it still works') !== false);
    test("[$k] it says what losing tfa.key costs",
        stripos($txt, 'every enrolled authenticator') !== false);
    test("[$k] it points at the advisory",
        strpos($txt, 'advisory-2026-08-03-fe-keys-dir.md') !== false);
}

// On Windows the destination IS the new default, so the move needs no config
// edit — and the text must say which of the two situations the reader is in.
test('Windows: moving out of the old directory needs no config change, and says so',
    stripos($winText, 'No config change is needed') !== false, $winText);
// On POSIX the flagged directory usually IS the default, so a config edit is
// the only fix, and the text must give the exact line.
test('POSIX: when the default itself is the problem, config.php is named with the line',
    strpos($nixText, "define('FE_KEYS_DIR'") !== false, $nixText);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 8. The 2FA error names the directory it could not write to --\n";
// "Check directory permissions" was accurate and pointed the administrator at
// granting write access to a directory published on port 80 — the permission
// failure was the only thing keeping the 2FA key out of it.

$hint = fe_keys_dir_hint($root . '/cache');    // inside the tree: certainly served
test('the hint names the directory', strpos($hint, 'cache') !== false, $hint);
test('…and warns when that directory is served, instead of asking for write access',
    stripos($hint, 'WARNING') !== false && stripos($hint, 'Do not grant write access') !== false,
    $hint);
test('…and names a safe directory to use instead',
    strpos($hint, FE_KEYS_DIR_DEFAULT) !== false, $hint);

$sx = served_dir_exposure($sandbox);
if (!$sx['served'] && !$sx['suspect']) {
    $safeHint = fe_keys_dir_hint($sandbox);
    test('a directory with no evidence of exposure gets a plain permissions hint, no alarm',
        stripos($safeHint, 'WARNING') === false
        && stripos($safeHint, 'write access') !== false,
        $safeHint);
} else {
    echo "[SKIP] the temp directory grades as " . $sx['state'] . " here — the quiet hint is not exercised\n";
}

$tfaApi = (string) file_get_contents($root . '/api/tfa-key.php');
test('api/tfa-key.php no longer says only "Check directory permissions."',
    strpos($tfaApi, 'Check directory permissions.') === false, 'api/tfa-key.php:80');
test('…both key-write failures carry the directory hint',
    substr_count($tfaApi, 'fe_keys_dir_hint(') >= 2);
test('…and the GET response reports the directory, which nothing in the UI did',
    strpos($tfaApi, "'keys_dir'") !== false);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 9. Docs say the platform-dependent thing platform-dependently --\n";
$upd  = (string) @file_get_contents($root . '/docs/UPDATE-CHECKLIST.md');
$iis  = (string) @file_get_contents($root . '/docs/INSTALL-WINDOWS-IIS.md');
$hard = (string) @file_get_contents($root . '/docs/WEB-SERVER-HARDENING.md');
$adv  = (string) @file_get_contents($root . '/docs/security/advisory-2026-08-03-fe-keys-dir.md');

test('UPDATE-CHECKLIST no longer claims "one level above" is why the key is private',
    stripos($upd, 'One level ABOVE the install directory, on purpose') === false,
    'that rationale is false on Windows, which is the whole defect');
test('UPDATE-CHECKLIST names the Windows location',
    stripos($upd, 'ProgramData\\TicketsCAD\\keys') !== false);
test('the Windows/IIS guide names the safe keys directory',
    stripos($iis, 'ProgramData\\TicketsCAD\\keys') !== false);
test('the Windows/IIS guide warns that the parent of an IIS site is served',
    stripos($iis, 'inetpub\\wwwroot\\keys') !== false);
test('the hardening guide covers the keys directory',
    stripos($hard, 'keys') !== false && stripos($hard, 'ProgramData') !== false);
test('the advisory exists', $adv !== '');
test('…and says the .pem 404 is a MIME accident, not a control',
    stripos($adv, 'MIME') !== false && stripos($adv, '404.3') !== false);
test('…and tells the reader nothing was moved for them',
    stripos($adv, 'nothing is moved') !== false || stripos($adv, 'Nothing is moved') !== false);

// ─────────────────────────────────────────────────────────────────────
// Tidy up.
foreach (glob($exposedKeys . '/*') ?: [] as $f) { @unlink($f); }
@rmdir($exposedKeys);
foreach ([$sandbox . '/legacy', $sandbox . '/programdata', $sandbox . '/operator-choice',
          $sandbox . '/fence', $sandbox . '/quiet-backups', $sandbox] as $d) {
    foreach (glob($d . '/*') ?: [] as $f) { if (is_file($f)) { @unlink($f); } }
    foreach (glob($d . '/.*') ?: [] as $f) { if (is_file($f)) { @unlink($f); } }
    @rmdir($d);
}

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
