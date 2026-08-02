<?php
/**
 * The health check must answer for the WEB SERVER, not for whoever ran it.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────────
 *
 * `php tools/check-health.php`, run over SSH as the operator account — which
 * is exactly what docs/UPDATE-CHECKLIST.md instructs — reported on 2026-07-31:
 *
 *     === Summary: 5 critical, 0 warning(s) ===
 *     sudo chown -R www-data:www-data .../uploads
 *
 * on two live hosts whose directories were already `www-data:www-data 775` and
 * already writable by the web server. The same check rendered in the browser
 * said OK. One check, asked as two different people: health_check_dirs() called
 * is_writable(), which answers for the CURRENT process, and under the CLI that
 * is a human login that was never meant to write those directories.
 *
 * A health check that reports a correct install as critically broken every time
 * it is run gets muted, and then it is the silent one — the failure mode this
 * project already paid for once with a scheduled job that had not run in seven
 * weeks while nothing said so.
 *
 * ── WHAT IS UNDER TEST ──────────────────────────────────────────────────────
 *
 * Behaviour, not source text. The POSIX access rules are exercised as
 * arithmetic over real ownership/mode triples (so they run identically on the
 * Windows workstation and the Linux CI runner); the account resolver is driven
 * against fixture /proc trees and fixture server configs on disk; and the
 * severity model is driven end to end through health_check_dirs() with the
 * resolved account injected, including the case no host can be asked to
 * reproduce on demand — the account not being determinable at all.
 *
 * Usage: php tests/test_health_web_user.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/health-check.php';

$passed  = 0;
$failed  = 0;
$skipped = 0;

function test($label, $condition) {
    global $passed, $failed;
    if ($condition) { echo "[PASS] $label\n"; $passed++; }
    else            { echo "[FAIL] $label\n"; $failed++; }
}

function skip($label, $why) {
    global $skipped;
    echo "[SKIP] $label — $why\n";
    $skipped++;
}

/** Recursively remove a fixture tree. Never used on anything but our own temp dir. */
function hwu_rmtree(string $dir): void {
    if (!is_dir($dir)) { return; }
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $e) {
        $p = $dir . DIRECTORY_SEPARATOR . $e;
        is_dir($p) ? hwu_rmtree($p) : @unlink($p);
    }
    @rmdir($dir);
}

echo "=== Health check answers for the web server account ===\n\n";

$root    = defined('NEWUI_ROOT') ? NEWUI_ROOT : dirname(__DIR__);
$fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hwu_' . uniqid();
@mkdir($fixture, 0777, true);

// ─────────────────────────────────────────────────────────────────────────────
// 1. The POSIX access rules — the arithmetic the whole fix rests on.
//
// These are the real numbers from the two live hosts. The first two assertions
// ARE the defect: the same directory, evaluated for two different accounts,
// gives two different answers, and the old code always asked the wrong one.
// ─────────────────────────────────────────────────────────────────────────────
echo "-- POSIX write access, evaluated for a named account --\n";

test('_health_mode_writable() defined', function_exists('_health_mode_writable'));

$web      = ['uid' => 33,   'gids' => [33]];      // www-data
$operator = ['uid' => 1000, 'gids' => [1000]];    // ejosterberg, over SSH

// uploads/ and cache/ as they actually are on training + your deployment.
test('www-data CAN write www-data:www-data 0775 (the live hosts, reported correct)',
    _health_mode_writable(33, 33, 0775, $web) === true);
test('the SSH operator CANNOT write that same directory — this is the false critical',
    _health_mode_writable(33, 33, 0775, $operator) === false);
// cache/zello-audio was 0755 on one host.
test('www-data CAN write www-data:www-data 0755',
    _health_mode_writable(33, 33, 0755, $web) === true);

// NEGATIVE CONTROL. The fix must not become "always say yes". A genuinely
// broken directory — the git-pull-as-root case this checker exists for — is
// still a hard failure when asked about the account that matters.
test('NEGATIVE CONTROL: root-owned 0755 is NOT writable by www-data (a real fault)',
    _health_mode_writable(0, 0, 0755, $web) === false);
test('NEGATIVE CONTROL: root-owned 0700 is NOT writable by www-data',
    _health_mode_writable(0, 0, 0700, $web) === false);

// POSIX consults exactly ONE class and stops. A directory you own at 0077 is
// not writable by you, however permissive group and other look — getting this
// backwards would invent write access that does not exist.
test('owner class is decisive: owner-owned 0077 is NOT writable by the owner',
    _health_mode_writable(33, 33, 0077, $web) === false);
test('group access counts when the account is in the group',
    _health_mode_writable(0, 33, 0770, $web) === true);
test('group access does not count when the account is not in the group',
    _health_mode_writable(0, 50, 0770, $web) === false);
test('world-writable 0777 is writable by anyone',
    _health_mode_writable(0, 0, 0777, $web) === true);
// Creating an entry in a directory needs search as well as write.
test('write without search (0600 owner) is not enough to create entries',
    _health_mode_writable(33, 33, 0600, $web) === false);
test('root (uid 0) is not subject to mode bits',
    _health_mode_writable(1000, 1000, 0700, ['uid' => 0, 'gids' => [0]]) === true);

// ─────────────────────────────────────────────────────────────────────────────
// 2. Determining the account — no hardcoded www-data anywhere.
// ─────────────────────────────────────────────────────────────────────────────
echo "\n-- Determining which account serves the application --\n";

test('health_check_web_user() defined', function_exists('health_check_web_user'));

// ── /proc: a running web server worker. The master runs as root and the
//    workers as the web account; it is the workers that open files.
$proc = $fixture . '/proc';
@mkdir($proc . '/1', 0777, true);      // init
@mkdir($proc . '/100', 0777, true);    // apache2 master, root
@mkdir($proc . '/101', 0777, true);    // apache2 worker, uid 33
file_put_contents($proc . '/1/comm',     "systemd\n");
file_put_contents($proc . '/1/status',   "Name:\tsystemd\nUid:\t0\t0\t0\t0\n");
file_put_contents($proc . '/100/comm',   "apache2\n");
file_put_contents($proc . '/100/status', "Name:\tapache2\nUid:\t0\t0\t0\t0\n");
file_put_contents($proc . '/101/comm',   "apache2\n");
file_put_contents($proc . '/101/status', "Name:\tapache2\nUid:\t33\t33\t33\t33\n");

$hit = _health_web_user_from_proc($proc);
test('finds the running web server worker and skips the root master',
    is_array($hit) && (int) $hit['uid'] === 33);
test('names the evidence it used', is_array($hit) && stripos((string) $hit['basis'], 'apache2') !== false);

// php-fpm workers present as php-fpm8.2 / "php-fpm: pool www".
$proc2 = $fixture . '/proc2';
@mkdir($proc2 . '/7', 0777, true);
file_put_contents($proc2 . '/7/comm',   "php-fpm8.2\n");
file_put_contents($proc2 . '/7/status', "Name:\tphp-fpm\nUid:\t48\t48\t48\t48\n");
$hit2 = _health_web_user_from_proc($proc2);
test('recognises a versioned php-fpm worker (uid 48 = apache on RHEL)',
    is_array($hit2) && (int) $hit2['uid'] === 48);

// A machine with no web server running must yield nothing, not a guess.
$proc3 = $fixture . '/proc3';
@mkdir($proc3 . '/9', 0777, true);
file_put_contents($proc3 . '/9/comm',   "sshd\n");
file_put_contents($proc3 . '/9/status', "Name:\tsshd\nUid:\t0\t0\t0\t0\n");
test('no web server running → no answer (not a default)', _health_web_user_from_proc($proc3) === null);
test('no /proc at all → no answer', _health_web_user_from_proc($fixture . '/nope') === null);
// Only the root master present: its uid must not be reported as the web account.
$proc4 = $fixture . '/proc4';
@mkdir($proc4 . '/3', 0777, true);
file_put_contents($proc4 . '/3/comm',   "nginx\n");
file_put_contents($proc4 . '/3/status', "Name:\tnginx\nUid:\t0\t0\t0\t0\n");
test('a root-only master is not mistaken for the worker account',
    _health_web_user_from_proc($proc4) === null);

// ── Server configuration files, across the distributions this ships on.
$cfg = $fixture . '/cfg';
@mkdir($cfg, 0777, true);
file_put_contents($cfg . '/envvars',    "export APACHE_RUN_USER=www-data\nexport APACHE_RUN_GROUP=www-data\n");
file_put_contents($cfg . '/httpd.conf', "ServerRoot \"/etc/httpd\"\nUser apache\nGroup apache\n");
file_put_contents($cfg . '/nginx.conf', "user  nginx;\nworker_processes auto;\n");
file_put_contents($cfg . '/fpm.conf',   "[www]\nuser = http\ngroup = http\n");
file_put_contents($cfg . '/indirect.conf', "User \${APACHE_RUN_USER}\n");

$r = _health_web_user_from_server_config([$cfg . '/envvars' => '/^\s*export\s+APACHE_RUN_USER=(\S+)/m']);
test('Debian/Ubuntu apache2 envvars → www-data', is_array($r) && $r['name'] === 'www-data');
$r = _health_web_user_from_server_config([$cfg . '/httpd.conf' => '/^\s*User\s+([A-Za-z0-9._-]+)\s*$/m']);
test('RHEL httpd.conf → apache (NOT www-data)', is_array($r) && $r['name'] === 'apache');
$r = _health_web_user_from_server_config([$cfg . '/nginx.conf' => '/^\s*user\s+([A-Za-z0-9._-]+)\s*;/m']);
test('nginx.conf → nginx', is_array($r) && $r['name'] === 'nginx');
$r = _health_web_user_from_server_config([$cfg . '/fpm.conf' => '/^\s*user\s*=\s*(\S+)/m']);
test('php-fpm pool → http (Arch)', is_array($r) && $r['name'] === 'http');
// "User ${APACHE_RUN_USER}" is indirection, not an answer.
test('a variable reference is not accepted as an account name',
    _health_web_user_from_server_config([$cfg . '/indirect.conf' => '/^\s*User\s+(\S+)\s*$/m']) === null);
test('no readable server config → no answer',
    _health_web_user_from_server_config([$cfg . '/absent.conf' => '/(x)/']) === null);

// ── Ownership of this install's runtime directories.
if (PHP_OS_FAMILY === 'Windows') {
    skip('runtime-owner probe', 'fileowner() returns 0 for every path on NTFS, so the probe correctly declines');
    test('runtime-owner probe declines on Windows rather than guessing',
        _health_web_user_from_runtime_owner($fixture) === null);
} else {
    $empty = $fixture . '/emptyroot';
    @mkdir($empty, 0777, true);
    test('an install with no runtime directories yields no answer',
        _health_web_user_from_runtime_owner($empty) === null);
    $owned = $fixture . '/ownedroot';
    @mkdir($owned . '/cache', 0777, true);
    @mkdir($owned . '/uploads', 0777, true);
    $hitO = _health_web_user_from_runtime_owner($owned);
    test('consistently-owned runtime directories resolve to their owner',
        is_array($hitO) && (int) $hitO['uid'] === (function_exists('posix_geteuid') ? posix_geteuid() : (int) $hitO['uid']));
}

// The composite must never invent a default. Whatever it returns, a determined
// answer always carries the evidence it rests on.
$wu = health_check_web_user();
test('resolver returns the documented shape',
    is_array($wu) && array_key_exists('determined', $wu) && array_key_exists('name', $wu)
    && array_key_exists('uid', $wu) && array_key_exists('basis', $wu));
test('a determined account always names the evidence for it',
    ($wu['determined'] === false) || (is_string($wu['basis']) && $wu['basis'] !== ''));
test('an undetermined account is reported as such, with no name invented',
    ($wu['determined'] === true) || (($wu['note'] ?? '') !== ''));

// A remedy that cannot work on the reader's system is its own defect — the
// same shape as reporting a problem that is not there. NEWUI_WEB_USER fixes
// this on a POSIX host (the name resolves to a uid and groups, and the access
// question becomes answerable); on a system with no POSIX account model it
// cannot, whatever name is supplied, so it must not be offered there.
$remedy = _health_undetermined_remedy();
test('the remedy always points at the browser, which needs to work nothing out',
    stripos($remedy, 'Status') !== false);
if (function_exists('posix_getpwnam')) {
    test('on a POSIX host the remedy offers NEWUI_WEB_USER, which does resolve there',
        strpos($remedy, 'NEWUI_WEB_USER') !== false);
    // Prove it: the configured name really does become an evaluable account.
    $me = _health_process_user();
    if ($me === null) {
        skip('a configured NEWUI_WEB_USER becomes an evaluable account', 'no process user to name');
    } else {
        putenv('NEWUI_WEB_USER=' . $me);
        $cfgWu = health_check_web_user(true);
        putenv('NEWUI_WEB_USER');
        health_check_web_user(true);   // restore the memoised real answer
        test('a configured NEWUI_WEB_USER becomes an evaluable account',
            $cfgWu['determined'] === true && $cfgWu['name'] === $me
            && strpos((string) $cfgWu['basis'], 'NEWUI_WEB_USER') !== false);
    }
} else {
    test('without a POSIX account model the remedy does NOT offer a setting that cannot help',
        strpos($remedy, "define('NEWUI_WEB_USER'") === false);
    test('and it says why, rather than leaving the reader at a dead end',
        stripos($remedy, 'would not change that') !== false);
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. The severity model, driven end to end through health_check_dirs().
// ─────────────────────────────────────────────────────────────────────────────
echo "\n-- Severity model --\n";

// (a) A correctly-configured install: the directories are writable by the web
//     server account. Zero criticals — the whole point of the change.
$asMe = [
    'determined'      => true,
    'is_this_process' => true,          // ask the kernel via is_writable()
    'name'            => 'test-web',
    'uid'             => function_exists('posix_geteuid') ? posix_geteuid() : null,
    'gids'            => [],
    'basis'           => 'injected by tests',
    'note'            => '',
];
$good = $fixture . '/good';
@mkdir($good, 0777, true);
$res  = health_check_dirs([$good], $asMe);
$e    = null;
foreach ($res['dirs'] as $d) { if ($d['path'] === $good) { $e = $d; } }
test('a writable directory is OK, not critical',
    $e !== null && $e['severity'] === 'ok' && $e['writable'] === true);

// (b) A missing directory that the app creates for itself is a WARNING. It was
//     reported critical from the CLI purely because the parent's writability
//     was also being judged as the wrong user.
$absent = $fixture . '/good/not-yet-created';
$res = health_check_dirs([$absent], $asMe);
$e   = null;
foreach ($res['dirs'] as $d) { if ($d['path'] === $absent) { $e = $d; } }
test('a missing but creatable directory is a warning, never critical',
    $e !== null && $e['exists'] === false && $e['severity'] === 'warn');

// (c) A genuinely inaccessible directory is still CRITICAL. The fix must not
//     have turned the check into an unconditional pass.
$nowhere = $fixture . '/good/no-such-parent-' . uniqid() . '/child';
$blind = $asMe;
$blind['is_this_process'] = false;      // force the mode-bit path
$blind['uid']  = 4242;                  // an account that owns nothing here
$blind['gids'] = [4242];
$res = health_check_dirs([$good], $blind);
$e   = null;
foreach ($res['dirs'] as $d) { if ($d['path'] === $good) { $e = $d; } }
if (PHP_OS_FAMILY === 'Windows') {
    // stat() reports 0777 for everything on NTFS, so no path is deniable here.
    skip('an unwritable directory is reported critical',
        'NTFS reports mode 0777 for every path; the denial arithmetic is covered above '
        . 'and end to end on POSIX below');
} else {
    @chmod($good, 0700);
    $res = health_check_dirs([$good], $blind);
    foreach ($res['dirs'] as $d) { if ($d['path'] === $good) { $e = $d; } }
    test('an unwritable directory is reported CRITICAL for the web account',
        $e !== null && $e['writable'] === false && $e['severity'] === 'critical');
    test('the critical names the ownership and mode that produced it',
        $e !== null && strpos((string) $e['note'], 'mode 0700') !== false);
    @chmod($good, 0777);
}

// (d) When the account cannot be established: UNKNOWN. Never ok, never
//     critical. This is the standing rule for an undeterminable probe, and the
//     case a real host cannot be asked to produce on demand.
$undet = [
    'determined'      => false,
    'is_this_process' => false,
    'name'            => null,
    'uid'             => null,
    'gids'            => [],
    'basis'           => null,
    'note'            => 'injected by tests',
];
$res = health_check_dirs([$good, $absent], $undet);
$allUnknown = count($res['dirs']) > 0;
$anyVerdict = false;
foreach ($res['dirs'] as $d) {
    if ($d['severity'] !== 'unknown') { $allUnknown = false; }
    if ($d['severity'] === 'ok' || $d['severity'] === 'critical') { $anyVerdict = true; }
    if ($d['writable'] !== null) { $allUnknown = false; }
}
test('an undeterminable web account reports UNKNOWN for every directory', $allUnknown);
test('an undeterminable web account never reports ok and never reports critical', !$anyVerdict);

// The bundle must carry unknowns in their own bucket, not folded into either
// of the other two.
$all = health_check_all();
test('summary carries a separate unknown count',
    isset($all['summary']['unknown']) && is_int($all['summary']['unknown']));
test('summary still carries integer critical + warn counts',
    is_int($all['summary']['critical'] ?? null) && is_int($all['summary']['warn'] ?? null));
test('the bundle reports which account it judged by',
    isset($all['web_user']) && array_key_exists('determined', $all['web_user']));

// ─────────────────────────────────────────────────────────────────────────────
// 4. The command line and the browser must agree.
//
// They agree structurally — both render one computation from
// inc/health-check.php — but "structurally" is what was believed before, so
// this compares what the CLI actually PRINTS for each directory against the
// severity the API hands the browser for that same directory, in one run.
// ─────────────────────────────────────────────────────────────────────────────
echo "\n-- The CLI and the browser render the same verdict --\n";

$cli = $root . '/tools/check-health.php';
$ran = false; $out = []; $rc = null;
if (function_exists('proc_open')) {
    $sink  = tmpfile();
    $pipes = [];
    $proc  = @proc_open([PHP_BINARY, $cli], [0 => ['pipe', 'r'], 1 => $sink, 2 => $sink], $pipes);
    if (is_resource($proc)) {
        fclose($pipes[0]);
        $rc = proc_close($proc);
        rewind($sink);
        $out = preg_split('/\r\n|\r|\n/', (string) stream_get_contents($sink));
        $ran = true;
    }
    if ($sink !== false) { @fclose($sink); }
}

if (!$ran) {
    skip('CLI and API agree per directory', 'no way to start a subprocess on this host');
} else {
    // What the CLI printed, per directory path.
    $tagFor = ['[OK]' => 'ok', '[WARN]' => 'warn', '[CRIT]' => 'critical', '[UNKN]' => 'unknown'];
    $cliSev = [];
    foreach ($out as $line) {
        if (preg_match('/^(\[OK\]|\[WARN\]|\[CRIT\]|\[UNKN\])\s+(\S+) — /u', $line, $m)) {
            $cliSev[$m[2]] = $tagFor[$m[1]];
        }
    }
    // What api/health-check.php hands the browser, in this process.
    $apiSev = [];
    foreach (($all['dirs']['dirs'] ?? []) as $d) { $apiSev[$d['path']] = $d['severity']; }

    test('the CLI printed a verdict for every required directory',
        count(array_intersect_key($apiSev, $cliSev)) === count($apiSev));
    $agree = true;
    foreach ($apiSev as $path => $sev) {
        if (($cliSev[$path] ?? null) !== $sev) { $agree = false; }
    }
    test('every directory carries the same verdict on the command line as in the browser', $agree);

    // The exit code must follow the same summary the browser badge follows.
    $crit = (int) ($all['summary']['critical'] ?? 0);
    $warn = (int) ($all['summary']['warn'] ?? 0);
    $unkn = (int) ($all['summary']['unknown'] ?? 0);
    $want = $crit > 0 ? 2 : (($warn > 0 || $unkn > 0) ? 1 : 0);
    test("CLI exit code follows the shared summary (expected $want, got " . var_export($rc, true) . ')',
        $rc === $want);
    test('an unknown alone never produces the critical exit code',
        !($crit === 0 && $unkn > 0 && $rc === 2));
}

// The browser must render the four severities distinguishably. An 'unknown'
// falling through to the green OK badge would be the same defect mirrored.
$statusSrc = @file_get_contents($root . '/status.php') ?: '';
test('status.php renders "unknown" as its own badge, not as OK',
    preg_match('/sev === \'unknown\'/', $statusSrc) === 1);
test('status.php never renders a null writability as "No"',
    strpos($statusSrc, 'yesNoUnknown') !== false);

// ─────────────────────────────────────────────────────────────────────────────
// 5. No suggestion may take .git with it.
//
// Standing rule (docs/UPDATE-CHECKLIST.md, 2026-07-28): `chown -R` on anything
// carrying a repository breaks the reader's next `git pull` with "detected
// dubious ownership" — and was never needed, because the web server only READS
// program files.
// ─────────────────────────────────────────────────────────────────────────────
echo "\n-- Suggested commands cannot break the reader's next git pull --\n";

test('_health_recursive_chown_safe() defined', function_exists('_health_recursive_chown_safe'));
test('the install root itself is refused', _health_recursive_chown_safe($root) === false);
test('an ancestor of the install root is refused', _health_recursive_chown_safe(dirname($root)) === false);
test('the filesystem root is refused', _health_recursive_chown_safe('/') === false);
test('uploads/ is allowed', _health_recursive_chown_safe($root . '/uploads') === true);
test('cache/ is allowed', _health_recursive_chown_safe($root . '/cache') === true);
// A directory that carries a repository is refused even when it is not the root.
$repoish = $fixture . '/carries-a-repo';
@mkdir($repoish . '/.git', 0777, true);
test('a directory containing .git is refused', _health_recursive_chown_safe($repoish) === false);

// And nothing the tool can print may name a broader target. Read every emitted
// chown line out of a real run rather than trusting the source.
if ($ran) {
    $badChown = [];
    foreach ($out as $line) {
        if (preg_match('/^\s*(?:#\s*)?sudo\s+chown\s+-R\s+\S+\s+(.+?)(?:\s+#.*)?$/', $line, $m)) {
            $target = trim($m[1]);
            if (!_health_recursive_chown_safe($target)) { $badChown[] = $target; }
        }
    }
    test('no chown -R the tool printed targets the install tree or a repository'
        . (empty($badChown) ? '' : ' — ' . implode(', ', $badChown)), empty($badChown));
    // The old wording hardcoded www-data as the example account for every
    // install, including the ones that run as apache/nginx/the site owner.
    $hardcoded = false;
    foreach ($out as $line) {
        if (preg_match('/sudo chown -R www-data:www-data/', $line)
            && (($wu['name'] ?? '') !== 'www-data')) { $hardcoded = true; }
    }
    test('no suggestion hardcodes www-data on an install that does not use it', !$hardcoded);
} else {
    skip('emitted chown targets are checked against the .git rule', 'CLI could not be run');
}

// ─────────────────────────────────────────────────────────────────────────────
// 6. The config.php NEWUI_VERSION advice must be safe to follow.
// ─────────────────────────────────────────────────────────────────────────────
echo "\n-- The 'delete the NEWUI_VERSION line' advice --\n";

// The advice is emitted only when newui_version_config_pin() is non-null, and
// that returns non-null only when the tracked VERSION file was read
// successfully. So it can never appear on an install whose version would become
// unresolvable once the line is gone. That is the guarantee, and it is
// structural rather than a matter of wording.
$pin = function_exists('newui_version_config_pin') ? newui_version_config_pin() : null;
test('the advice is only ever offered when the tracked VERSION file resolves',
    $pin === null || newui_version_read_file() !== null);

if ($pin === null) {
    skip('following the advice leaves the install working',
        'this config.php carries no NEWUI_VERSION define, so there is nothing to remove '
        . '(the shipped config.example.php has not defined it since 2026-07)');
} elseif (!function_exists('proc_open')) {
    skip('following the advice leaves the install working', 'no way to start a subprocess on this host');
} else {
    // Do the edit the tool describes, in both forms, and boot the application
    // with each. __DIR__ resolves to the install root for any file placed
    // there, so a probe copy exercises the same include graph config.php does.
    $src   = (string) @file_get_contents($root . '/config.php');
    $probe = function (string $name, string $contents) use ($root) {
        $file = $root . DIRECTORY_SEPARATOR . $name;
        file_put_contents($file, $contents);
        $sink  = tmpfile();
        $pipes = [];
        $code  = 'require ' . var_export($file, true) . '; '
               . 'echo newui_version(), "|", (defined("NEWUI_VERSION") ? NEWUI_VERSION : "UNDEFINED"), '
               . '"|", asset_v("assets/js/dashboard.js");';
        $proc = @proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => $sink, 2 => $sink], $pipes);
        $outp = ''; $rc = null;
        if (is_resource($proc)) {
            fclose($pipes[0]);
            $rc = proc_close($proc);
            rewind($sink);
            $outp = (string) stream_get_contents($sink);
        }
        if ($sink !== false) { @fclose($sink); }
        @unlink($file);
        return [$outp, $rc];
    };

    $pattern = '/^[ \t]*define\s*\(\s*[\'"]NEWUI_VERSION[\'"]\s*,.*\R/m';
    $deleted = preg_replace($pattern, '', $src, 1);
    $replaced = preg_replace($pattern, "require_once __DIR__ . '/inc/version.php';\n", $src, 1);

    // NEGATIVE CONTROL: the unmodified config must boot here, or the two
    // results below mean nothing (no database, no PHP, wrong path — any of
    // those would fail all three identically).
    [$baseOut, $baseRc] = $probe('.hwu-probe-baseline.php', $src);
    if ($baseRc !== 0 || strpos($baseOut, '|') === false) {
        skip('following the advice leaves the install working',
            'the UNMODIFIED config.php does not boot in a subprocess here, so the '
            . 'comparison would prove nothing (' . trim(substr($baseOut, 0, 160)) . ')');
    } else {
        test('NEGATIVE CONTROL: the unmodified config.php boots', $baseRc === 0);
        foreach ([['deleting the define', $deleted], ['replacing it with the require_once', $replaced]] as [$what, $variant]) {
            [$o, $c] = $probe('.hwu-probe-' . md5($what) . '.php', $variant);
            $parts = explode('|', trim($o));
            test("$what still boots the application", $c === 0);
            test("$what still resolves the version from the tracked VERSION file",
                ($parts[0] ?? '') === newui_version_read_file());
            test("$what leaves NEWUI_VERSION defined for legacy readers",
                ($parts[1] ?? '') === newui_version_read_file());
            // asset_v() is the only reader of the constant anywhere in the tree,
            // and it lives in config.php itself — the file being edited.
            test("$what keeps asset_v() working (the only reader of the constant)",
                ($parts[2] ?? '') !== '' && strpos((string) ($parts[2] ?? ''), 'UNDEFINED') === false);
        }
    }
}

hwu_rmtree($fixture);

echo "\n";
if ($skipped > 0) { echo "($skipped check(s) skipped — see [SKIP] lines above)\n"; }
echo "=== $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
