<?php
/**
 * inc/api_guard.php — an API endpoint never returns an empty body.
 *
 * ORIGIN. A beta tester deleted a mesh bridge (2026-07-28), the delete worked,
 * and he got a red "Failed to execute 'json' on 'Response': Unexpected end of
 * JSON input". api/mesh.php had passed an array where audit_log() declares
 * `string $summary`. That is a TypeError — which extends Error, NOT Exception —
 * so the surrounding `catch (Exception $e)` never saw it. With display_errors
 * off (deliberate: warnings would corrupt JSON) PHP died AFTER the writes had
 * committed and sent nothing at all.
 *
 * There are ~825 `catch (Exception ...)` blocks in api/. Widening them to
 * Throwable would silently swallow real faults, so instead the OUTER boundary
 * is hardened. These tests lock in the three properties that make that safe:
 *   1. a fatal of any kind becomes valid JSON + HTTP 500,
 *   2. it never double-emits or appends to a body that already started,
 *   3. it never leaks a message, file path, or stack trace to the client,
 * plus the wiring invariant: every api/*.php has a path to the guard.
 *
 * No database or HTTP server required.
 */

echo "=== inc/api_guard.php — fatal-to-JSON guard ===\n\n";
$pass = 0; $fail = 0;
function ok(string $n): void  { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad(string $n, string $why = ''): void { global $fail; echo "[FAIL] $n" . ($why ? " — $why" : '') . "\n"; $fail++; }
function is_true($c, string $n, string $why = ''): void { $c ? ok($n) : bad($n, $why); }

$base = realpath(__DIR__ . '/..');
$php  = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';

/**
 * Run a snippet in its own display_errors=0 process (a fatal kills the
 * process, so each scenario needs a fresh one) and return only its STDOUT —
 * i.e. exactly the bytes a browser would receive as the response body.
 */
function run_isolated(string $body, string $extraIni = ''): string
{
    global $php, $base;
    $tmp = sys_get_temp_dir() . '/api_guard_case_' . bin2hex(random_bytes(6)) . '.php';
    $err = $tmp . '.err';
    file_put_contents($tmp,
        "<?php\nrequire_once " . var_export($base . '/inc/api_guard.php', true) . ";\n"
        . "api_guard_install();\n" . $body . "\n");

    $cmd = escapeshellarg($php) . ' -d display_errors=0 -d log_errors=0 ' . $extraIni . ' '
         . escapeshellarg($tmp) . ' 2>' . escapeshellarg($err);
    $out = (string) shell_exec($cmd);
    @unlink($tmp);
    @unlink($err);
    return $out;
}

// ── 1. The library itself ────────────────────────────────────────────────
require_once $base . '/inc/api_guard.php';
is_true(function_exists('api_guard_install'),        'api_guard_install() is defined');
is_true(function_exists('api_guard_output_started'), 'api_guard_output_started() is defined');
is_true(function_exists('api_guard_ref'),            'api_guard_ref() is defined');
is_true(preg_match('/^[0-9a-f]{8}$/', api_guard_ref()) === 1,
    'api_guard_ref() is a stable 8-hex-char opaque token');
is_true(api_guard_ref() === api_guard_ref(), 'api_guard_ref() is minted once per request');

// ── 2. The exact bug: TypeError inside catch (Exception) ────────────────
$out = run_isolated(<<<'PHP'
function audit_log_sim(string $category, string $summary): bool { return true; }
try {
    audit_log_sim('mesh', ['bridge' => 7]);      // the mesh.php bug shape
} catch (Exception $e) {
    echo json_encode(['error' => 'catch(Exception) reached — impossible']);
}
PHP);
is_true($out !== '', 'TypeError no longer produces an EMPTY body');
$decoded = json_decode($out, true);
is_true(json_last_error() === JSON_ERROR_NONE && is_array($decoded),
    'TypeError produces valid JSON', 'got: ' . substr($out, 0, 120));
is_true(isset($decoded['error']) && isset($decoded['ref']),
    'the JSON carries an error message and an opaque reference id');

// ── 3. Nothing internal reaches the client ──────────────────────────────
is_true(strpos($out, 'audit_log_sim') === false,
    'client body does not name the failing function');
is_true(strpos($out, 'must be of type string') === false,
    'client body does not repeat the TypeError message');
is_true(strpos($out, '.php') === false && strpos($out, '#0 ') === false,
    'client body carries no file path and no stack trace');
is_true(isset($decoded['ref']) && preg_match('/^[0-9a-f]{8}$/', (string) $decoded['ref']) === 1,
    'the reference id is opaque (hex only, no detail encoded)');

// ── 4. Fatals no handler can intercept still produce JSON ───────────────
$out = run_isolated('require "/no/such/file/anywhere.php";');
is_true(json_decode($out, true) !== null, 'failed require becomes JSON, not an empty body');

$out = run_isolated('$a = []; while (true) { $a[] = str_repeat("x", 100000); }',
    '-d memory_limit=16M -d output_buffering=4096');
is_true(json_decode($out, true) !== null,
    'memory exhaustion (shutdown-only fatal) becomes JSON even with output buffering on');

// ── 5. Never double-emits ───────────────────────────────────────────────
// json_response() sets __api_guard_body_sent then echoes and exits. A fatal
// raised from a later shutdown function must add nothing.
$out = run_isolated(<<<'PHP'
register_shutdown_function(function () { boom_undefined(); });
$GLOBALS['__api_guard_body_sent'] = true;
echo json_encode(['ok' => true, 'deleted' => 1]);
exit;
PHP, '-d output_buffering=4096');
is_true(trim($out) === '{"ok":true,"deleted":1}',
    'a completed json_response() is not followed by a second JSON document',
    'got: ' . substr($out, 0, 160));

// ── 6. Never corrupts a non-JSON body that already started ──────────────
// SSE (stream.php), CSV exports, backup downloads, dmr audio.
$out = run_isolated("echo \"id,name\\n1,North Hill\\n\"; flush(); boom_undefined();");
is_true(trim($out) === "id,name\n1,North Hill",
    'a half-written CSV/SSE body gets no JSON appended', 'got: ' . substr($out, 0, 160));

// ── 7. Wiring — every api/*.php can reach the guard ─────────────────────
// Directly (api_guard_install), or via a bootstrap that installs it:
// api/auth.php, api/external/v1/_auth.php, inc/json-safe.php.
$bootstraps = [
    'api/auth.php'              => true,
    'api/external/v1/_auth.php' => true,
];
foreach ($bootstraps as $rel => $_) {
    $src = (string) @file_get_contents($base . '/' . $rel);
    is_true(strpos($src, 'api_guard_install()') !== false,
        "$rel installs the guard");
    is_true(strpos($src, 'api_guard.php') !== false, "$rel requires inc/api_guard.php");
}
$src = (string) @file_get_contents($base . '/inc/json-safe.php');
is_true(strpos($src, 'api_guard_install()') !== false,
    'inc/json-safe.php delegates to the guard (one shutdown handler, not two)');
$src = (string) @file_get_contents($base . '/inc/functions.php');
is_true(strpos($src, '__api_guard_body_sent') !== false,
    'json_response() flags that the body has been written');

$uncovered = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base . '/api'));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') continue;
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
    if ($rel === 'api/auth.php') continue;             // the bootstrap itself
    $src = (string) @file_get_contents($f->getPathname());
    $covered = strpos($src, 'api_guard_install()') !== false
            || strpos($src, "auth.php'") !== false     // api/auth.php or external _auth.php
            || strpos($src, 'json-safe.php') !== false;
    if (!$covered) $uncovered[] = $rel;
}
is_true(empty($uncovered),
    'every api/*.php reaches the fatal-to-JSON guard',
    'uncovered: ' . implode(', ', $uncovered));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
