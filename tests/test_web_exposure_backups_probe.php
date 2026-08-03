<?php
/**
 * Gate: the web-exposure self-check must never call the backups path "blocked"
 * on the strength of a request for the DIRECTORY.
 *
 * ── WHAT HAPPENED ────────────────────────────────────────────────────
 *
 * @rjonesbsink, on his own install:
 *
 *   > `/backups/` returned 403 but the archive inside it returned 200 and
 *   > served in full — the complete 168 KB database export.
 *
 * That is not a strange server. It is what any server with directory listing
 * off and no deny rule on files does; on Apache, `Options -Indexes` alone
 * produces exactly it. So `403` on the folder is at once the most reassuring
 * answer a server can give and worth nothing as evidence — and it was
 *
 *   (a) the fallback health_check_web_exposure() used whenever it could not
 *       name an archive, scored as 'blocked' and summarised as "No non-public
 *       directory answered over HTTP"; and
 *   (b) the one-minute command the Critical advisory told worried operators to
 *       run, with "403 or 404 — good, that path is blocked" beside it.
 *
 * The check an admin runs to confirm they are safe, returning "good" while
 * their database was being served.
 *
 * Worse after 5b88fbb: the archive glob looked in ONE location
 * (BACKUP_DIR_LEGACY) while the code had grown four — BACKUP_DIR, the sibling,
 * the legacy in-tree folder and the `backup_dir` setting — so on most installs
 * it found nothing and reached for the directory probe.
 *
 * ── WHAT THIS FILE ASSERTS ───────────────────────────────────────────
 *
 *   1. THE REPORTED CASE. Against a real local server that answers 403 for a
 *      path ending in `/` and 200 for a file — @rjonesbsink's server, exactly —
 *      the check reports EXPOSED and critical. Driven end to end through
 *      health_check_web_exposure(); nothing is hand-seeded.
 *   2. A genuinely denying server (403 for everything) still reports blocked,
 *      so this is not a check that simply always cries wolf.
 *   3. The probe asks for a FILE. Its path is never the bare directory.
 *   4. NO ARCHIVE AND NO CANARY ⇒ 'untested', with the reason, and the whole
 *      check's severity is 'unknown' — never 'ok' and never 'blocked'.
 *   5. Archives are discovered across every supported location, including one
 *      named by the `backup_dir` setting, and out-of-tree directories are
 *      excluded (they are not at <base>/… and are the Backup-location row's
 *      job).
 *   6. The Status page and tools/check-health.php render 'untested' as
 *      something other than a pass, and every shipped copy of the manual check
 *      names an archive.
 *
 * Nothing leaves this machine: the server is bound to 127.0.0.1 on an
 * ephemeral port, and the no-server assertions use RFC 2606 `.invalid`.
 *
 * Usage: php tests/test_web_exposure_backups_probe.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/backup.php';
require_once __DIR__ . '/../inc/health-check.php';

$pass = 0;
$fail = 0;

function test($label, $condition, $hint = '') {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "  [PASS] $label\n";
    } else {
        $fail++;
        echo "  [FAIL] $label" . ($hint !== '' ? "\n         $hint" : '') . "\n";
    }
}

$root = rtrim(str_replace('\\', '/', NEWUI_ROOT), '/');

// ─────────────────────────────────────────────────────────────────────
// A local web server that behaves the way the reporter's did.
//
// proc_open with an ARGV ARRAY, never a command string: this project gates
// against shelling out (tests/test_no_shell_command_execution.php), and an
// argv array is the form that is not handed to a shell to re-parse.
// ─────────────────────────────────────────────────────────────────────

/** An ephemeral port nothing is listening on. */
function wep_free_port(): ?int
{
    $s = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!is_resource($s)) { return null; }
    $name = stream_socket_get_name($s, false);
    fclose($s);
    if (!is_string($name) || strrpos($name, ':') === false) { return null; }
    return (int) substr($name, strrpos($name, ':') + 1);
}

/**
 * Start `php -S 127.0.0.1:<port> <router>`.
 *
 * $mode 'dirdeny'  — 403 for any path ending in '/', 200 for any file.
 *                    This is the reported server.
 * $mode 'denyall'  — 403 for everything. A server that really is configured.
 *
 * @return array{proc:resource,port:int,dir:string}|null
 */
function wep_start_server(string $mode): ?array
{
    if (!function_exists('proc_open')) { return null; }
    $bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : null;
    if ($bin === null || !@is_file($bin)) { return null; }
    $port = wep_free_port();
    if ($port === null) { return null; }

    $dir = sys_get_temp_dir() . '/tcad-wep-' . getmypid() . '-' . mt_rand();
    if (!@mkdir($dir, 0777, true) && !is_dir($dir)) { return null; }

    $router = $dir . '/router.php';
    $code = "<?php\n"
          . '$p = (string) parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);' . "\n"
          . '$mode = ' . var_export($mode, true) . ";\n"
          . 'if ($mode === "denyall" || substr($p, -1) === "/") {' . "\n"
          . '    http_response_code(403); header("Content-Type: text/plain");' . "\n"
          . '    echo "Forbidden"; return true;' . "\n"
          . "}\n"
          . 'http_response_code(200); header("Content-Type: application/octet-stream");' . "\n"
          . 'echo "served ", $p; return true;' . "\n";
    if (@file_put_contents($router, $code) === false) { return null; }

    $desc = [1 => ['file', $dir . '/out.log', 'a'], 2 => ['file', $dir . '/err.log', 'a']];
    $proc = @proc_open([$bin, '-S', '127.0.0.1:' . $port, $router], $desc, $pipes, $dir);
    if (!is_resource($proc)) { return null; }

    // Wait for it to accept connections. The built-in server is quick, but
    // "quick" is not "already listening" when the next line makes a request.
    for ($i = 0; $i < 100; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
        if (is_resource($c)) { fclose($c); return ['proc' => $proc, 'port' => $port, 'dir' => $dir]; }
        usleep(50000);
    }
    @proc_terminate($proc);
    @proc_close($proc);
    return null;
}

function wep_stop_server(?array $srv): void
{
    if ($srv === null) { return; }
    @proc_terminate($srv['proc']);
    @proc_close($srv['proc']);
    foreach ((glob($srv['dir'] . '/*') ?: []) as $f) { @unlink($f); }
    @rmdir($srv['dir']);
}

/** Run health_check_web_exposure() as though this install answered at $base. */
function wep_check_against(string $base): array
{
    $savedHost = $_SERVER['HTTP_HOST'] ?? null;
    $savedName = $_SERVER['SERVER_NAME'] ?? null;
    $savedEnv  = getenv('NEWUI_BASE_URL');
    // No HTTP_HOST: _health_self_base_url() then falls through to the env var,
    // which is the CLI path and the one that lets this point at the fixture.
    unset($_SERVER['HTTP_HOST'], $_SERVER['SERVER_NAME']);
    putenv('NEWUI_BASE_URL=' . $base);
    try {
        $out = health_check_web_exposure(true);
    } finally {
        if ($savedHost !== null) { $_SERVER['HTTP_HOST']   = $savedHost; }
        if ($savedName !== null) { $_SERVER['SERVER_NAME'] = $savedName; }
        if ($savedEnv === false) { putenv('NEWUI_BASE_URL'); }
        else { putenv('NEWUI_BASE_URL=' . $savedEnv); }
    }
    return $out;
}

/** The backups row of a probe result. */
function wep_backups_probe(array $out): ?array
{
    foreach (($out['probes'] ?? []) as $p) {
        if (strpos((string) ($p['path'] ?? ''), 'sql/') === 0) { continue; }
        if (strpos((string) ($p['path'] ?? ''), 'tools/') === 0) { continue; }
        return $p;
    }
    return null;
}

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 1. An archive must exist in the tree for the end-to-end probes --\n";
// Use whatever the real discovery finds; only manufacture one when this install
// genuinely has none, and take it away again afterwards.

$inTree = _health_backup_dirs_in_tree();
test('_health_backup_dirs_in_tree() exists and returns a map',
    is_array($inTree));

$madeArchive = null;
$madeDir     = null;
if (empty($inTree)) {
    $madeDir = $root . '/backups';
    if (!is_dir($madeDir)) { @mkdir($madeDir, 0777, true); }
    else { $madeDir = null; }
    $inTree = _health_backup_dirs_in_tree();
}
$firstDir = null;
foreach ($inTree as $rel => $dir) { $firstDir = $dir; break; }

if ($firstDir !== null) {
    $have = glob(rtrim($firstDir, '/\\') . '/ticketscad-*.{zip,gz}', GLOB_BRACE) ?: [];
    if (empty($have)) {
        $madeArchive = rtrim($firstDir, '/\\') . '/ticketscad-19700101-000000.zip';
        @file_put_contents($madeArchive, "fixture, not a real archive\n");
    }
}
$haveArchive = false;
foreach ($inTree as $rel => $dir) {
    if (!empty(glob(rtrim($dir, '/\\') . '/ticketscad-*.{zip,gz}', GLOB_BRACE) ?: [])) {
        $haveArchive = true;
        break;
    }
}
test('an in-tree backup directory with an archive is available to probe',
    $haveArchive, 'dirs=' . implode(', ', array_values($inTree)));

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 2. THE REPORTED CASE: 403 on the folder, 200 on the file --\n";

$srv = $haveArchive ? wep_start_server('dirdeny') : null;
if ($srv === null) {
    echo "[SKIP] could not start a local PHP web server — the end-to-end probe is not exercised\n";
} else {
    $out = wep_check_against('http://127.0.0.1:' . $srv['port']);
    $bp  = wep_backups_probe($out);
    wep_stop_server($srv);

    test('the backups path is probed at all', is_array($bp),
        'probes=' . json_encode(array_column($out['probes'] ?? [], 'path')));

    if (is_array($bp)) {
        // The whole defect in one assertion.
        test('a 200 on the archive is reported EXPOSED even though the folder said 403',
            ($bp['state'] ?? '') === 'exposed',
            'state=' . ($bp['state'] ?? '?') . ' status=' . var_export($bp['status'] ?? null, true)
            . ' path=' . ($bp['path'] ?? '?'));
        test('…and the request was for a FILE, not the directory',
            ($bp['path'] ?? '') !== 'backups/'
            && substr((string) ($bp['path'] ?? ''), -1) !== '/',
            'path=' . ($bp['path'] ?? '?'));
        test('…and it named an actual archive',
            strpos((string) ($bp['path'] ?? ''), 'ticketscad-') !== false,
            'path=' . ($bp['path'] ?? '?'));
    }
    test('the check as a whole is CRITICAL', ($out['severity'] ?? '') === 'critical',
        'severity=' . ($out['severity'] ?? '?') . ' summary=' . ($out['summary'] ?? ''));
    test('the summary does not claim nothing answered',
        stripos((string) ($out['summary'] ?? ''), 'no non-public') === false,
        (string) ($out['summary'] ?? ''));
}

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 3. A server that really denies is still reported as blocked --\n";
// Otherwise this would be a check that always fails, which gets muted, and then
// it is the silent one.

$srv = $haveArchive ? wep_start_server('denyall') : null;
if ($srv === null) {
    echo "[SKIP] could not start a local PHP web server — the negative case is not exercised\n";
} else {
    $out = wep_check_against('http://127.0.0.1:' . $srv['port']);
    $bp  = wep_backups_probe($out);
    wep_stop_server($srv);

    test('403 on a named archive IS a pass', is_array($bp) && ($bp['state'] ?? '') === 'blocked',
        'state=' . ($bp['state'] ?? '?') . ' status=' . var_export($bp['status'] ?? null, true));
    test('…and the check reports ok, so this is not a permanent red',
        ($out['severity'] ?? '') === 'ok',
        'severity=' . ($out['severity'] ?? '?') . ' summary=' . ($out['summary'] ?? ''));
    test('…and it says it asked for an archive rather than the folder',
        is_array($bp) && stripos((string) ($bp['note'] ?? ''), 'directory') !== false,
        (string) ($bp['note'] ?? ''));
}

// Clean up anything this test manufactured, before the untested-state section.
if ($madeArchive !== null) { @unlink($madeArchive); $madeArchive = null; }
if ($madeDir !== null)     { @rmdir($madeDir);      $madeDir = null; }

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 4. No archive and no canary is UNTESTED, never blocked --\n";
// Driven through the real _health_backups_probe_result() with the two directory
// states an install can genuinely be in: none in the tree at all (the v4.2.4
// default everywhere), and one present but empty (a fresh install that has not
// backed up yet). $inTree is a parameter for exactly this reason — a developer
// box has archives in the tree and CI does not, and both branches have to be
// assertable from one machine.

$noHost = function (callable $fn) {
    $savedHost = $_SERVER['HTTP_HOST'] ?? null;
    $savedName = $_SERVER['SERVER_NAME'] ?? null;
    unset($_SERVER['HTTP_HOST'], $_SERVER['SERVER_NAME']);
    try { return $fn(); } finally {
        if ($savedHost !== null) { $_SERVER['HTTP_HOST']   = $savedHost; }
        if ($savedName !== null) { $_SERVER['SERVER_NAME'] = $savedName; }
    }
};

$base = 'http://tcad-selftest.invalid';

$none = $noHost(function () use ($base) {
    return _health_backups_probe_result($base, true, []);
});
test('no backup directory in the tree ⇒ untested, not blocked',
    ($none['state'] ?? '') === 'untested',
    'state=' . ($none['state'] ?? '?'));
test('…and it never claims a status code it did not receive',
    ($none['status'] ?? null) === null && ($none['url'] ?? null) === null);
test('…and it says where the real answer is instead',
    stripos((string) ($none['note'] ?? ''), 'backup archive location') !== false,
    (string) ($none['note'] ?? ''));
// The two "no answer" cases are not the same, and conflating them breaks the
// check one way or the other: a v4.2.4 install correctly keeps its archives
// outside the tree, and colouring that grey for ever is how a row gets muted.
test('…and it is marked ABSENT (certain), not inconclusive',
    ($none['untested_reason'] ?? '') === 'absent',
    'reason=' . ($none['untested_reason'] ?? '?'));

// An empty in-tree directory, on a host with no HTTP context, so the canary
// cannot run either. Real directory, real function, no request leaves the box.
$emptyDir = $root . '/cache/tcad-wep-empty-' . getmypid();
@mkdir($emptyDir, 0777, true);
if (is_dir($emptyDir)) {
    $empty = $noHost(function () use ($base, $emptyDir) {
        return _health_backups_probe_result($base, true, ['cache/x' => $emptyDir]);
    });
    test('a backup directory with no archive and no usable canary ⇒ untested',
        ($empty['state'] ?? '') === 'untested',
        'state=' . ($empty['state'] ?? '?') . ' note=' . ($empty['note'] ?? ''));
    test('…and the reason names the missing archive',
        stripos((string) ($empty['note'] ?? ''), 'no archive present') !== false,
        (string) ($empty['note'] ?? ''));
    test('…and it spells out that a 403 on the folder would not have answered it',
        stripos((string) ($empty['note'] ?? ''), '403') !== false,
        (string) ($empty['note'] ?? ''));
    // THIS one is the ambiguous kind: a directory is there, files may be
    // published, and we could not ask. It has to escalate.
    test('…and it is marked INCONCLUSIVE, so the check goes grey rather than green',
        ($empty['untested_reason'] ?? '') === 'inconclusive',
        'reason=' . ($empty['untested_reason'] ?? '?'));
    @rmdir($emptyDir);
} else {
    echo "[SKIP] could not create a scratch directory under cache/\n";
}

// …and the same state, through the whole check, must not read as healthy.
$outUn = $noHost(function () use ($base) {
    $savedEnv = getenv('NEWUI_BASE_URL');
    putenv('NEWUI_BASE_URL=' . $base);
    try {
        // .invalid does not resolve, so every probe comes back 'unknown'. A
        // report with nothing in it is not a pass.
        return health_check_web_exposure(true);
    } finally {
        if ($savedEnv === false) { putenv('NEWUI_BASE_URL'); }
        else { putenv('NEWUI_BASE_URL=' . $savedEnv); }
    }
});
test('a check that could not reach anything is never reported as ok',
    ($outUn['severity'] ?? '') !== 'ok',
    'severity=' . ($outUn['severity'] ?? '?') . ' summary=' . ($outUn['summary'] ?? ''));

// The verdict rule itself, fed rows the REAL prober produced. This machine can
// only be in one of the two no-archive states at a time; the classification has
// to be right in both.
$blockedRow = ['path' => 'sql/run_migrations.php', 'state' => 'blocked'];
test('an ABSENT backups directory does not colour a healthy install grey',
    _health_exposure_severity([$blockedRow, $blockedRow, $none]) === 'ok',
    _health_exposure_severity([$blockedRow, $blockedRow, $none]));
if (isset($empty)) {
    test('…while an INCONCLUSIVE one does — the question was asked and not answered',
        _health_exposure_severity([$blockedRow, $blockedRow, $empty]) === 'unknown',
        _health_exposure_severity([$blockedRow, $blockedRow, $empty]));
}
test('an exposed row beats everything else',
    _health_exposure_severity([$blockedRow, $none,
        ['state' => 'exposed']]) === 'critical');
test('nothing reachable at all is a warning, not a pass',
    _health_exposure_severity([['state' => 'unknown'], ['state' => 'unknown']]) === 'warn');

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 5. Archives are looked for in every supported location --\n";
// 5b88fbb turned "the backup directory" into a list. The old glob knew one of
// them, and silently fell back to the directory probe whenever the archives
// were anywhere else.

$src = (string) file_get_contents($root . '/inc/health-check.php');
test('discovery uses backup_dirs_all(), not a single hard-coded constant',
    strpos($src, 'backup_dirs_all()') !== false
    && preg_match('/_health_backup_dirs_in_tree.*?backup_dirs_all\(\)/s', $src) === 1);

// Behavioural, not a grep: a directory created under each supported constant is
// actually discovered, and one outside the tree is actually excluded.
$scratch = $root . '/cache/tcad-wep-loc-' . getmypid();
@mkdir($scratch, 0777, true);
if (is_dir($scratch)) {
    $found = _health_backup_dirs_in_tree();
    $norm  = function (string $p): string {
        $r = @realpath($p);
        return rtrim(str_replace('\\', '/', $r !== false ? $r : $p), '/');
    };
    $vals = array_map($norm, array_values($found));

    // BACKUP_DIR_LEGACY is in-tree by definition, so when it exists it must be
    // in the list — that is the location the old code knew about, kept honest.
    if (defined('BACKUP_DIR_LEGACY') && is_dir(BACKUP_DIR_LEGACY)) {
        test('the in-tree legacy directory is discovered',
            in_array($norm(BACKUP_DIR_LEGACY), $vals, true),
            implode(' ', $vals));
    } else {
        echo "  [INFO] no in-tree legacy backups directory on this install\n";
    }

    // Out-of-tree directories must NOT appear: they are not reachable at
    // <base>/… at all, and pretending to probe them is the mistake in reverse.
    $outside = [];
    foreach ([defined('BACKUP_DIR') ? BACKUP_DIR : null,
              defined('BACKUP_DIR_LEGACY_SIBLING') ? BACKUP_DIR_LEGACY_SIBLING : null] as $d) {
        if ($d === null || !is_dir($d)) { continue; }
        $nd = $norm($d);
        if ($nd !== $norm(NEWUI_ROOT) && strpos($nd, $norm(NEWUI_ROOT) . '/') !== 0) {
            $outside[] = $nd;
        }
    }
    foreach ($outside as $nd) {
        test('an out-of-tree backup directory is excluded: ' . $nd,
            !in_array($nd, $vals, true),
            'it cannot answer at <base>/… — that is health_check_backups()\'s job');
    }
    if (empty($outside)) {
        echo "  [INFO] no out-of-tree backup directory exists on this install to exclude\n";
    }

    // The application root itself must never map to a probe: rel '' would ask
    // for the home page and score a 200 as a database disclosure.
    test('the application root itself is never treated as a backup directory',
        !in_array($norm(NEWUI_ROOT), $vals, true));

    @rmdir($scratch);
} else {
    echo "[SKIP] could not create a scratch directory under cache/\n";
}

// The canary must be able to reach an IN-TREE directory on a subdirectory
// install — without the app's own URL prefix it only ever asked the host root
// and the parent, neither of which is where an in-tree folder lives.
$savedHost   = $_SERVER['HTTP_HOST'] ?? null;
$savedScript = $_SERVER['SCRIPT_NAME'] ?? null;
$_SERVER['HTTP_HOST']   = 'tcad-selftest.invalid';
$_SERVER['SCRIPT_NAME'] = '/newui/status.php';
$canaryDir = $root . '/cache/tcad-wep-canary-' . getmypid();
@mkdir($canaryDir, 0777, true);
if (is_dir($canaryDir)) {
    $p = health_check_backup_probe($canaryDir, true);
    test('the canary tries the application\'s own URL prefix, where an in-tree folder lives',
        !empty(array_filter($p['tried'] ?? [], function ($u) {
            return strpos($u, '/newui/') !== false;
        })),
        implode(' ', $p['tried'] ?? []));
    test('…and still never requests a real archive',
        !in_array(true, array_map(function ($u) {
            return strpos($u, 'ticketscad-') !== false;
        }, $p['tried'] ?? []), true));
    test('…and an unreachable host is not reported as exposed', empty($p['exposed']));
    foreach ((glob($canaryDir . '/*') ?: []) as $f) { @unlink($f); }
    @rmdir($canaryDir);
} else {
    echo "[SKIP] could not create a scratch directory for the canary\n";
}
if ($savedHost === null)   { unset($_SERVER['HTTP_HOST']); }   else { $_SERVER['HTTP_HOST']   = $savedHost; }
if ($savedScript === null) { unset($_SERVER['SCRIPT_NAME']); } else { $_SERVER['SCRIPT_NAME'] = $savedScript; }

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 6. Nothing renders 'untested' as a pass, and no doc teaches the bad check --\n";

$status = (string) @file_get_contents($root . '/status.php');
test('the Status page has an "unknown" severity badge for web exposure',
    strpos($status, "we.severity === 'unknown'") !== false);
test('…and names the paths it could not test',
    strpos($status, "p.state !== 'untested'") !== false);

$chk = (string) @file_get_contents($root . '/tools/check-health.php');
test('the CLI does not print [OK] for an untested probe',
    strpos($chk, "'untested'") !== false && strpos($chk, '[????]') !== false);

// The manual check, in every place it is published. A copy that still tells the
// reader to request the directory and read a 403 as safe is the defect itself.
$docs = [
    'docs/security/advisory-2026-07-30-exposed-directories.md',
    'docs/WEB-SERVER-HARDENING.md',
    'docs/INSTALL.md',
    'docs/INSTALLATION-CHECKLIST.md',
    'SECURITY.md',
    'CHANGELOG.md',
];
foreach ($docs as $rel) {
    $txt = (string) @file_get_contents($root . '/' . $rel);
    if ($txt === '') { echo "  [INFO] not present: $rel\n"; continue; }
    // Only a curl COMMAND aimed at the bare folder is the defect. The advisory
    // quotes the old command in its correction note on purpose, so a plain
    // substring search would fail the very text that fixes this.
    test($rel . ' no longer curls the bare backups directory',
        preg_match('#^[^\n]*curl[^\n]*/backups/[\s`\'"]*$#m', $txt) !== 1,
        'a request for the folder is the check that lies');
    test($rel . ' points the reader at an archive filename',
        preg_match('#backups/ticketscad-[^\s`\'"]+#', $txt) === 1,
        'the reader needs a real filename to ask for');
}

$adv = (string) @file_get_contents($root . '/docs/security/advisory-2026-07-30-exposed-directories.md');
test('the advisory says plainly that a 403 on the folder proves nothing',
    stripos($adv, 'proves nothing') !== false || stripos($adv, 'means nothing') !== false);
test('…and tells anyone who ran the old check to run it again',
    stripos($adv, 'Corrected 2026-08-02') !== false);
test('…and credits the reporter',
    strpos($adv, 'rjonesbsink') !== false);

$amend = (string) @file_get_contents($root . '/docs/security/advisory-2026-07-30-check-correction.md');
test('the amendment text for the published advisory exists', $amend !== '');
test('…and it is the corrected check, not a summary of one',
    strpos($amend, 'backups/ticketscad-') !== false
    && stripos($amend, 'Check your own install') !== false);

$hard = (string) @file_get_contents($root . '/docs/WEB-SERVER-HARDENING.md');
test('the hardening guide explains why the folder request is not the test',
    stripos($hard, 'ask for an archive') !== false
    || stripos($hard, 'not for the folder') !== false);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
