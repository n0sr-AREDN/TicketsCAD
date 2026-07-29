<?php
/**
 * Version-source tests — the deployed version must follow the CODE.
 *
 * Regression gate for the defect found by the mgitwin/mgitnix video QA
 * (finding C1): NEWUI_VERSION was defined only in config.php, which is
 * gitignored, so `git pull` could never change what Help → About reported.
 * Eric's own install showed 4.0.0-dev against 4.1.3 code, and the update
 * video's "prove it worked — check About" step could never pass.
 *
 * What is locked down here:
 *   1. A tracked VERSION file exists and holds a sane version string.
 *   2. newui_version() reports it.
 *   3. The tracked file BEATS a config.php define() — proved in a subprocess
 *      that pre-defines a stale constant, i.e. exactly a legacy install.
 *   4. config.php is still honoured as a FALLBACK when the file is missing.
 *   5. config.example.php no longer hardcodes a version (so fresh installs
 *      and Docker's generated config can never re-pin one).
 *   6. NOTHING in the app reads the bare NEWUI_VERSION constant any more —
 *      the "make them all agree" gate. A new `<?php echo NEWUI_VERSION ?>`
 *      would silently reintroduce the drift on every pre-existing install.
 *
 * Usage: php tests/test_version_source.php
 */

require_once __DIR__ . '/../config.php';

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

echo "=== Version source tests (About must follow the code) ===\n\n";

$root = defined('NEWUI_ROOT') ? NEWUI_ROOT : dirname(__DIR__);
$php  = PHP_BINARY;

// ── 1. The tracked VERSION file ───────────────────────────────
echo "-- The tracked VERSION file --\n";

$verFile = $root . '/VERSION';
test('VERSION file exists at the app root', is_file($verFile),
    'create it: the canonical version lives there, not in config.php');

$verRaw = is_file($verFile) ? (string) file_get_contents($verFile) : '';
$verVal = trim(strtok($verRaw, "\r\n") ?: '');
test('VERSION holds a single sane version string',
    $verVal !== '' && strlen($verVal) <= 40 && preg_match('/^[0-9A-Za-z._+\-]+$/', $verVal) === 1,
    'got ' . var_export($verVal, true));
test('VERSION starts with a major version digit', preg_match('/^\d+\./', $verVal) === 1);

// It must be a TRACKED file — a gitignored VERSION would recreate the bug.
$giSrc = (string) @file_get_contents($root . '/.gitignore');
test('VERSION is not excluded by .gitignore',
    !preg_match('/^\s*\/?VERSION\s*$/m', $giSrc));

// ── 2. newui_version() reports it ─────────────────────────────
echo "\n-- newui_version() --\n";

test('inc/version.php exists', is_file($root . '/inc/version.php'));
test('newui_version() is defined', function_exists('newui_version'));
test('newui_version_source() is defined', function_exists('newui_version_source'));
test('newui_version() === the VERSION file contents',
    function_exists('newui_version') && newui_version() === $verVal,
    'newui_version()=' . (function_exists('newui_version') ? newui_version() : '?') . ' file=' . $verVal);
test("newui_version_source() reports 'file'",
    function_exists('newui_version_source') && newui_version_source() === 'file');

// inc/functions.php is required by EVERY config.php ever shipped — that is the
// hook that makes the function reach installs whose config.php we cannot edit.
$fnSrc = (string) file_get_contents($root . '/inc/functions.php');
test('inc/functions.php requires inc/version.php',
    strpos($fnSrc, "require_once __DIR__ . '/version.php'") !== false,
    'without this, legacy installs fatal on newui_version()');

// ── 3. Precedence: the tracked file beats a config.php define ──
echo "\n-- Precedence (a legacy config.php must NOT win) --\n";

$probe = sys_get_temp_dir() . '/tcad_ver_probe_' . getmypid() . '.php';
file_put_contents($probe,
    "<?php\n" .
    "define('NEWUI_VERSION', '0.0.0-stale-from-config');\n" .
    "require " . var_export($root . '/inc/version.php', true) . ";\n" .
    "echo newui_version(), '|', newui_version_source(), '|', NEWUI_VERSION;\n"
);
$out = [];
$rc  = 1;
exec(escapeshellarg($php) . ' ' . escapeshellarg($probe) . ' 2>&1', $out, $rc);
@unlink($probe);
$line  = trim(implode('', $out));
$parts = explode('|', $line);

test('probe ran cleanly (no redefinition warning)', $rc === 0 && count($parts) === 3,
    'output: ' . $line);
test('newui_version() ignores a stale config.php define',
    ($parts[0] ?? '') === $verVal,
    'got ' . var_export($parts[0] ?? null, true) . ', expected ' . $verVal);
test('the legacy constant is left alone (no fatal redefine)',
    ($parts[2] ?? '') === '0.0.0-stale-from-config');
test('newui_version_config_pin() surfaces the stale pin for the health card',
    function_exists('newui_version_config_pin'));

// ── 4. Fallback: config.php still wins when VERSION is absent ──
echo "\n-- Fallback when the VERSION file is missing --\n";

$tmpRoot = sys_get_temp_dir() . '/tcad_ver_noffile_' . getmypid();
@mkdir($tmpRoot . '/inc', 0777, true);
copy($root . '/inc/version.php', $tmpRoot . '/inc/version.php');   // no VERSION alongside
$probe2 = $tmpRoot . '/probe.php';
file_put_contents($probe2,
    "<?php\n" .
    "define('NEWUI_VERSION', '9.9.9-from-config');\n" .
    "require __DIR__ . '/inc/version.php';\n" .
    "echo newui_version(), '|', newui_version_source();\n"
);
$out2 = [];
$rc2  = 1;
exec(escapeshellarg($php) . ' ' . escapeshellarg($probe2) . ' 2>&1', $out2, $rc2);
$line2  = trim(implode('', $out2));
$parts2 = explode('|', $line2);
@unlink($probe2);
@unlink($tmpRoot . '/inc/version.php');
@rmdir($tmpRoot . '/inc');
@rmdir($tmpRoot);

test('with no VERSION file, config.php is the fallback',
    ($parts2[0] ?? '') === '9.9.9-from-config', 'output: ' . $line2);
test("fallback source reports 'config'", ($parts2[1] ?? '') === 'config');

// ── 5. The template must not re-pin a version ─────────────────
echo "\n-- config.example.php (fresh installs + Docker) --\n";

$exSrc = (string) file_get_contents($root . '/config.example.php');
test('config.example.php does NOT define NEWUI_VERSION',
    preg_match("/define\s*\(\s*'NEWUI_VERSION'/", $exSrc) !== 1,
    'a hardcoded version here re-pins every new install at its install date');
test('config.example.php requires inc/version.php',
    strpos($exSrc, "require_once __DIR__ . '/inc/version.php'") !== false);

// docker-config-gen.php copies the template verbatim except the DB lines, so
// containers inherit whatever the template does.
$genSrc = (string) file_get_contents($root . '/docker-config-gen.php');
test('docker-config-gen.php still generates from config.example.php',
    strpos($genSrc, 'config.example.php') !== false);
test('docker-config-gen.php does not inject its own version',
    strpos($genSrc, 'NEWUI_VERSION') === false);

// ── 6. No app code reads the bare constant any more ───────────
echo "\n-- Every reader agrees (no bare NEWUI_VERSION reads) --\n";

// Files allowed to mention the constant: the two that define it, and the
// health check, which parses it out of config.php on purpose.
$allowed = [
    'config.php', 'config.example.php',
    'inc/version.php', 'inc/health-check.php',
    'tests/test_version_source.php',
];
$skipDirs = ['vendor', 'node_modules', 'docs', 'specs', '.git', '.claude', 'cache', 'uploads', 'backups'];

$offenders = [];
$it = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        function ($f, $k, $iter) use ($skipDirs) {
            if ($iter->hasChildren()) {
                return !in_array($f->getFilename(), $skipDirs, true);
            }
            return substr($f->getFilename(), -4) === '.php';
        }
    )
);
foreach ($it as $f) {
    $abs = str_replace('\\', '/', $f->getPathname());
    $rel = ltrim(substr($abs, strlen(str_replace('\\', '/', $root))), '/');
    if (in_array($rel, $allowed, true)) {
        continue;
    }
    $src = (string) file_get_contents($abs);
    if (strpos($src, 'NEWUI_VERSION') === false) {
        continue;
    }
    // Tokenize rather than regex: only a real T_STRING token is a constant
    // READ. Quoted uses (defined('NEWUI_VERSION')), comments and inline HTML
    // prose are all fine and must not trip this gate.
    $tokens = @token_get_all($src);
    if (!is_array($tokens)) {
        continue;
    }
    foreach ($tokens as $t) {
        if (is_array($t) && $t[0] === T_STRING && $t[1] === 'NEWUI_VERSION') {
            $offenders[] = $rel;
            break;
        }
    }
}
test('no PHP file reads the bare NEWUI_VERSION constant',
    empty($offenders),
    'use newui_version() instead — offenders: ' . implode(', ', array_slice($offenders, 0, 8)));

// ── 7. The surfaces the QA named ──────────────────────────────
echo "\n-- Reporting surfaces --\n";

foreach ([
    'about.php'             => 'About page',
    'inc/navbar.php'        => 'navbar version badge',
    'login.php'             => 'login footer',
    'api/config-summary.php' => 'settings summary API',
    'api/captions.php'      => 'captions API',
    'api/feed.php'          => 'RSS generator',
    'inc/backup.php'        => 'backup metadata',
] as $rel => $what) {
    $src = (string) @file_get_contents($root . '/' . $rel);
    test("$what ($rel) reports newui_version()", strpos($src, 'newui_version()') !== false);
}

// health_check_version_match() must keep its documented shape and gain the
// reported/config_pin advisory fields.
require_once $root . '/inc/health-check.php';
$hv = health_check_version_match();
test('health_check_version_match() still returns checked=true', ($hv['checked'] ?? false) === true);
test('health check reports the resolved version', ($hv['reported'] ?? '') === $verVal);
test('health check still names a version source file', !empty($hv['version_file']));
test('health check exposes config_pin (null when clean)', array_key_exists('config_pin', $hv));

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
