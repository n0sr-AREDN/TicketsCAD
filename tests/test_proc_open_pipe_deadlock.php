<?php
/**
 * openises/TicketsCAD#28 (@rjonesbsink) — proc_open pipe deadlock.
 *
 * **stream_set_blocking() cannot put a proc_open pipe into non-blocking mode on
 * Windows.** It returns false and the stream stays blocking. Two functions,
 * written independently, both relied on it working, and in both the timeout
 * guard sits AFTER a blocking read — so the guard is unreachable and cannot
 * fire:
 *
 *   - proxy/ZelloProxyApp.php  runPipe()      — blocked in fread($pipes[2],8192)
 *     waiting for stderr bytes that never come while the child filled the stdout
 *     buffer. In a ReactPHP loop, so the WHOLE Zello proxy froze.
 *   - inc/tts/engine.php       tts_run_pipe() — never drained stderr at all, so
 *     a chatty child wedged; and it then returned ok/detail='ok' with the audio
 *     silently truncated to one buffer.
 *
 * Measured on this project's Windows dev box, PHP 8.2.4 (reporter: 8.4.22), with
 * a verbatim copy of each pre-fix loop:
 *
 *   runPipe, 1 byte stderr + 200,000 stdout, 5s guard:
 *       after stdout fread:  8192 bytes (0.09s)
 *       after stderr fread:     1 byte  (0.09s)
 *       after stdout fread: 16384 bytes (0.11s)
 *       <wedged — deadline never printed, killed externally at 25s>
 *   tts_run_pipe, 8192+ bytes of stderr:  ran past its 5s guard to an external
 *       kill at 20s.
 *   tts_run_pipe, child exiting on its own after 6s, 1s guard: returned at 6.11s.
 *   After the fix: 0.11s / 0.12s respectively, with ALL bytes captured, and the
 *       1s guard now firing at 1.02s.
 *
 * ── HOW THIS TEST IS BUILT ───────────────────────────────────────────────
 *
 * Every probe runs in a SUBPROCESS under a hard deadline (tests/_proc_pipe_probe.php).
 * That is not ceremony: two of the modes are the pre-fix code, and the whole
 * point of a positive control here is that it can wedge forever. Running it
 * in-process would hang the suite instead of failing this file.
 *
 * The controls are verbatim copies of the removed loops, kept in the helper.
 * They have to live in the test — the code they model no longer exists in the
 * tree — but they are byte-faithful to `git show 5aced05:` of each file, and the
 * FIXED assertions drive the real production functions, not copies.
 *
 * Note the platform asymmetry, stated honestly rather than papered over: on
 * POSIX stream_set_blocking() genuinely works on pipes, so the pre-fix runPipe
 * loop does NOT deadlock there. The control therefore asserts the defect where
 * the platform has it and records the platform where it does not. The
 * tts_run_pipe stderr-starvation case wedges on BOTH platforms (POSIX: the child
 * blocks writing >64KB of stderr that nothing ever drains), so that one is a
 * cross-platform guard.
 *
 * Run: /c/xampp/8.2.4/php/php.exe tests/test_proc_open_pipe_deadlock.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$base = dirname(__DIR__);
$pass = 0;
$fail = 0;

function t(string $label, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { echo "[PASS] {$label}\n"; $pass++; }
    else       { echo "[FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
}

$isWin = (stripos(PHP_OS, 'WIN') === 0);

/** Per-probe reaping evidence, asserted in section 5. */
$GLOBALS['probe_reaping'] = [];

echo "=== openises/TicketsCAD#28 — proc_open pipe deadlock ===\n";
echo "PHP " . PHP_VERSION . " on " . PHP_OS . "\n\n";

/**
 * Kill a probe and EVERYTHING it started.
 *
 * proc_terminate() is not enough on Windows, and getting this wrong hangs the
 * whole suite instead of failing this one file — which is exactly what happened
 * while writing this test. Two compounding reasons:
 *
 *   1. PHP runs a command string through `cmd.exe /c`, so proc_terminate() kills
 *      the wrapper and leaves the php.exe underneath it running — and the thing
 *      it is running is, by design here, wedged forever.
 *   2. CreateProcess inherits handles, so every descendant holds a copy of THIS
 *      process's stdout — which under tools/test_all.php is a pipe the runner is
 *      reading to EOF. One surviving grandchild means that pipe never closes and
 *      the runner blocks indefinitely.
 *
 * `taskkill /T` takes the tree. bypass_shell in bounded_probe() removes the
 * wrapper as well, so the pid we hold is the real worker.
 */
function kill_process_tree($proc): void
{
    $st  = @proc_get_status($proc);
    $pid = is_array($st) ? (int) ($st['pid'] ?? 0) : 0;

    if ($pid > 0 && stripos(PHP_OS, 'WIN') === 0) {
        $null = (stripos(PHP_OS, 'WIN') === 0) ? 'NUL' : '/dev/null';
        $k = @proc_open('taskkill /T /F /PID ' . $pid, [
            0 => ['file', $null, 'r'],
            1 => ['file', $null, 'w'],
            2 => ['file', $null, 'w'],
        ], $kp);
        if (is_resource($k)) {
            $wait = microtime(true) + 5.0;
            while (microtime(true) < $wait) {
                $s = proc_get_status($k);
                if (!$s['running']) break;
                usleep(20000);
            }
            @proc_close($k);
        }
    }
    @proc_terminate($proc, 9);
}

// ─────────────────────────────────────────────────────────────────────────
// Bounded subprocess runner. Uses FILE descriptors — i.e. the very fix under
// test — so this harness cannot itself be the thing that hangs.
// ─────────────────────────────────────────────────────────────────────────
function bounded_probe(string $mode, array $a, float $limitS): array
{
    $probe = __DIR__ . '/_proc_pipe_probe.php';
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' ' . $mode
        . ' ' . (int) ($a['so'] ?? 0) . ' ' . (int) ($a['se'] ?? 0)
        . ' ' . (int) ($a['sl'] ?? 0) . ' ' . (int) ($a['tmo'] ?? 5)
        . ' ' . (int) ($a['sin'] ?? 0);

    $tag  = 'p28_' . getmypid() . '_' . bin2hex(random_bytes(5));
    $dir  = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR;
    $fOut = $dir . $tag . '.out';
    $fErr = $dir . $tag . '.err';

    $null = (stripos(PHP_OS, 'WIN') === 0) ? 'NUL' : '/dev/null';
    // bypass_shell: no cmd.exe wrapper, so the pid we get back is the worker
    // itself and kill_process_tree() can actually reach it.
    $proc = @proc_open($cmd, [
        0 => ['file', $null, 'r'],
        1 => ['file', $fOut, 'w'],
        2 => ['file', $fErr, 'w'],
    ], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        return ['spawn' => false, 'timedout' => false, 'elapsed' => 0.0, 'json' => null];
    }

    $t0 = microtime(true);
    $timedOut = false;
    while (true) {
        $st = proc_get_status($proc);
        if (!$st['running']) break;
        if (microtime(true) - $t0 > $limitS) {
            $timedOut = true;
            kill_process_tree($proc);   // the tree, not just the process
            break;
        }
        usleep(20000);
    }
    // proc_close() BLOCKS until the process is gone. If kill_process_tree()
    // failed we would hang here, so the elapsed time below is itself the
    // evidence that reaping worked — see section 5.
    proc_close($proc);
    $elapsed = microtime(true) - $t0;

    $GLOBALS['probe_reaping'][] = ['mode' => $mode, 'limit' => $limitS, 'elapsed' => $elapsed];

    $raw = (string) @file_get_contents($fOut);
    $json = null;
    foreach (array_reverse(array_filter(explode("\n", $raw))) as $line) {
        $d = json_decode(trim($line), true);
        if (is_array($d)) { $json = $d; break; }
    }
    foreach ([$fOut, $fErr] as $f) { if (@is_file($f)) @unlink($f); }

    return ['spawn' => true, 'timedout' => $timedOut, 'elapsed' => $elapsed, 'json' => $json];
}

// ─────────────────────────────────────────────────────────────────────────
// 1. The platform fact the whole fix rests on.
// ─────────────────────────────────────────────────────────────────────────
echo "-- 1. stream_set_blocking() on a proc_open pipe --\n";

$p = @proc_open(escapeshellarg(PHP_BINARY) . ' -r "echo 1;"',
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
if (is_resource($p)) {
    $ret  = stream_set_blocking($pp[1], false);
    $meta = stream_get_meta_data($pp[1]);
    fclose($pp[0]); fclose($pp[1]); fclose($pp[2]); proc_close($p);

    echo "       returned " . var_export($ret, true)
        . ", meta blocked=" . var_export($meta['blocked'], true) . "\n";

    if ($isWin) {
        // If a future PHP ever fixes this, we want to be told, not to keep
        // asserting a platform fact that stopped being true.
        t('Windows: stream_set_blocking() on a proc_open pipe still returns false',
            $ret === false, 'returned ' . var_export($ret, true));
        t('Windows: the pipe is still reported as blocking afterwards',
            $meta['blocked'] === true);
    } else {
        t('POSIX: stream_set_blocking() on a proc_open pipe works',
            $ret === true, 'returned ' . var_export($ret, true));
    }

    // Control that the probe itself is meaningful: sockets DO honour it, on
    // every platform. Without this, "returns false" could just mean the call
    // is broken everywhere and the test proves nothing about pipes.
    $sock = @stream_socket_client('tcp://127.0.0.1:1', $e1, $e2, 0.1, STREAM_CLIENT_ASYNC_CONNECT);
    if ($sock) {
        t('control: the same call on a socket returns true (so the probe is sound)',
            stream_set_blocking($sock, false) === true);
        fclose($sock);
    } else {
        echo "       (socket control unavailable on this host)\n";
    }
} else {
    t('proc_open is available', false, 'cannot spawn');
}

// ─────────────────────────────────────────────────────────────────────────
// 2. POSITIVE CONTROLS — the pre-fix loops, demonstrably broken.
// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. positive controls: the pre-fix loops --\n";

// 2a. Cross-platform: tts_run_pipe never drained stderr. A child that writes
//     more stderr than one pipe buffer blocks writing it, so stdout never
//     reaches EOF. Windows: wedges outright. POSIX: the child blocks on
//     stderr, the guard does fire, and the caller gets TRUNCATED output
//     reported as success — which is defect 1b either way.
$ctlA = bounded_probe('prefix-tts', ['so' => 200000, 'se' => 262144, 'tmo' => 2], 6.0);
$ctlAbytes = $ctlA['json']['bytes'] ?? -1;
printf("       prefix tts_run_pipe (256KB stderr): %s, %.2fs, %d of 200000 bytes\n",
    $ctlA['timedout'] ? 'KILLED at deadline' : 'returned',
    $ctlA['elapsed'], $ctlAbytes);
t('control: the pre-fix tts_run_pipe loop cannot deliver a chatty child\'s output',
    $ctlA['timedout'] || $ctlAbytes < 200000,
    'it returned all 200000 bytes in ' . round($ctlA['elapsed'], 2) . 's — the defect is gone from the CONTROL, so this test no longer proves anything');

// 2b. Cross-platform: the timeout guard is unreachable behind a blocking read.
//     Child exits on its own after 5s, so this is bounded even when wedged.
$ctlB = bounded_probe('prefix-tts', ['so' => 16, 'se' => 1, 'sl' => 4, 'tmo' => 1], 9.0);
printf("       prefix tts_run_pipe (1s guard, child exits at 5s): %.2fs\n", $ctlB['elapsed']);
if ($isWin) {
    t('control: on Windows the pre-fix 1s guard does not fire (blocked in the read)',
        $ctlB['elapsed'] > 3.0,
        'returned in ' . round($ctlB['elapsed'], 2) . 's, so the guard was reachable');
} else {
    echo "       (POSIX: non-blocking pipes work, so this guard was reachable — recorded, not asserted)\n";
}

// 2c. Windows-specific: runPipe's fixed-size stderr fread.
$ctlC = bounded_probe('prefix-runpipe', ['so' => 200000, 'se' => 1, 'tmo' => 2], 6.0);
printf("       prefix runPipe (1 byte stderr, 200KB stdout): %s, %.2fs, %d bytes\n",
    $ctlC['timedout'] ? 'KILLED at deadline' : 'returned',
    $ctlC['elapsed'], $ctlC['json']['bytes'] ?? -1);
if ($isWin) {
    t('control: the pre-fix runPipe loop deadlocks past its own 2s guard',
        $ctlC['timedout'],
        'it completed in ' . round($ctlC['elapsed'], 2) . 's — the control no longer reproduces the bug');
} else {
    echo "       (POSIX: stream_set_blocking works, so this loop does not deadlock — recorded, not asserted)\n";
}

// ─────────────────────────────────────────────────────────────────────────
// 3. THE FIX — the real production functions.
// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. the real functions, after the fix --\n";

// 3a. The exact case 2a wedges on.
$fA = bounded_probe('fixed-tts', ['so' => 200000, 'se' => 262144, 'tmo' => 15], 30.0);
$fAbytes = $fA['json']['bytes'] ?? -1;
printf("       tts_run_pipe (256KB stderr): %.2fs, %d bytes\n", $fA['elapsed'], $fAbytes);
t('tts_run_pipe returns the child\'s ENTIRE stdout however chatty its stderr is',
    !$fA['timedout'] && $fAbytes === 200000,
    $fA['timedout'] ? 'killed at the deadline' : "got {$fAbytes} of 200000");
t('tts_run_pipe does it promptly rather than waiting out a timeout',
    !$fA['timedout'] && $fA['elapsed'] < 8.0,
    round($fA['elapsed'], 2) . 's');

// 3b. The guard is reachable now — and a timeout is reported as FAILURE, not
//     as a short read. Returning the partial buffer is exactly the "ok=true
//     with truncated audio" the reporter saw.
$fB = bounded_probe('fixed-tts', ['so' => 16, 'se' => 1, 'sl' => 5, 'tmo' => 1], 9.0);
printf("       tts_run_pipe (1s guard, child sleeps 5s): %.2fs, null=%s\n",
    $fB['elapsed'], var_export($fB['json']['null'] ?? null, true));
t('tts_run_pipe honours its timeout instead of waiting for the child',
    !$fB['timedout'] && $fB['elapsed'] < 3.5,
    round($fB['elapsed'], 2) . 's for a 1s timeout');
t('a timed-out synth returns null — never a truncated buffer reported as audio',
    ($fB['json']['null'] ?? false) === true);

// 3c. runPipe: the case that froze the whole proxy.
if (!is_file($base . '/vendor/autoload.php')) {
    echo "       (skipping ZelloProxyApp::runPipe — vendor/ absent; CI does not run composer install)\n";
} else {
    $fC = bounded_probe('fixed-runpipe', ['so' => 200000, 'se' => 1, 'tmo' => 15], 30.0);
    $fCbytes = $fC['json']['bytes'] ?? -1;
    printf("       runPipe (1 byte stderr, 200KB stdout): %.2fs, %d bytes\n",
        $fC['elapsed'], $fCbytes);
    t('ZelloProxyApp::runPipe returns the whole payload and does not deadlock',
        !$fC['timedout'] && $fCbytes === 200000,
        $fC['timedout'] ? 'killed at the deadline' : "got {$fCbytes} of 200000");

    // The stdin side had the same defect independently: fwrite() of ~527 KB of
    // raw PCM onto a blocking pipe for the ffmpeg stage.
    $fD = bounded_probe('fixed-runpipe', ['so' => 0, 'se' => 1, 'tmo' => 15, 'sin' => 527196], 30.0);
    printf("       runPipe (527196-byte stdin): %.2fs\n", $fD['elapsed']);
    t('ZelloProxyApp::runPipe accepts a 527 KB stdin payload without blocking',
        !$fD['timedout'] && ($fD['json']['error'] ?? '') === '',
        $fD['timedout'] ? 'killed at the deadline' : (string) ($fD['json']['error'] ?? ''));
}

// ─────────────────────────────────────────────────────────────────────────
// 4. THE SWEEP — the reporter's own closing suggestion.
// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. tree sweep: stream_set_blocking() on proc_open pipes --\n";

/**
 * Files allowed to call BOTH proc_open and stream_set_blocking. Empty on
 * purpose. If you add one, say here WHY the stream is not a proc_open pipe —
 * the call is a silent no-op on a Windows pipe, so a new pairing is a bug
 * until proven otherwise.
 */
$allow = [];

$hits = [];
$rii = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        static function ($f) {
            $n = $f->getFilename();
            if ($f->isDir()) {
                return !in_array($n, ['vendor', 'node_modules', '.git', '.claude', 'cache', 'uploads'], true);
            }
            return substr($n, -4) === '.php';
        }
    )
);
/**
 * Tokenize rather than grep. Both patched files now DESCRIBE the defect in
 * their docblocks, and a substring scan cannot tell an explanation of a bug
 * from the bug. token_get_all() sees comments as T_COMMENT/T_DOC_COMMENT and
 * a call as a T_STRING followed by "(", so only real calls count.
 */
function calls_function(string $src, string $fn): bool
{
    $tokens = @token_get_all($src);
    if (!is_array($tokens)) return false;
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_STRING || strcasecmp($t[1], $fn) !== 0) continue;
        // Skip a method/property access ($obj->fn(), Cls::fn()) and declarations.
        $prev = $i > 0 ? $tokens[$i - 1] : null;
        if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) continue;
        for ($j = $i + 1; $j < $n; $j++) {
            $nx = $tokens[$j];
            if (is_array($nx) && in_array($nx[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
            if ($nx === '(') return true;
            break;
        }
    }
    return false;
}

foreach ($rii as $f) {
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
    if ($rel === 'tests/_proc_pipe_probe.php') continue;          // the controls, deliberately
    if ($rel === 'tests/' . basename(__FILE__)) continue;         // this file's own platform probe
    if (in_array($rel, $allow, true)) continue;
    $src = (string) @file_get_contents($f->getPathname());
    if (strpos($src, 'stream_set_blocking') === false) continue;  // cheap pre-filter
    if (!calls_function($src, 'stream_set_blocking')) continue;
    if (!calls_function($src, 'proc_open')) continue;             // not a pipe site
    $hits[] = $rel;
}
sort($hits);
foreach ($hits as $h) echo "       HIT {$h}\n";
t('no file pairs proc_open with stream_set_blocking (it is a no-op on Windows pipes)',
    $hits === [],
    implode(', ', $hits));

// The two originals specifically: assert they use FILE descriptors now. Cheap,
// and it catches a naive revert before the behavioural probes above have to.
$eng = (string) @file_get_contents($base . '/inc/tts/engine.php');
t('inc/tts/engine.php tts_run_pipe() stages its descriptors as files',
    preg_match('/function tts_run_pipe.*?0\s*=>\s*\[\s*.file./s', $eng) === 1);
$zpa = (string) @file_get_contents($base . '/proxy/ZelloProxyApp.php');
t('proxy/ZelloProxyApp.php runPipe() stages its descriptors as files',
    preg_match('/function runPipe.*?0\s*=>\s*\[\s*.file./s', $zpa) === 1);

// ─────────────────────────────────────────────────────────────────────────
// 5. This file must not leave anything running.
// ─────────────────────────────────────────────────────────────────────────
// Deliberately wedging processes is only safe if every one of them is reaped.
// A survivor inherits this process's stdout, so under tools/test_all.php it
// holds the runner's pipe open and the SUITE hangs rather than this file
// failing. That is not hypothetical — it is what happened before
// kill_process_tree() existed, and the symptom (a runner stuck with no output)
// gives no hint where to look.
echo "\n-- 5. no probe processes left behind --\n";

// Deliberately NOT a count of php.exe processes on the box: this machine may be
// running other work, and a flaky gate gets muted. proc_close() blocks until the
// process it was given is gone, so "the probe returned close to its own deadline"
// is direct evidence the kill landed. A probe that escaped would have parked us
// in proc_close() instead.
$worst = null;
foreach (($GLOBALS['probe_reaping'] ?? []) as $r) {
    $over = $r['elapsed'] - $r['limit'];
    if ($worst === null || $over > $worst['over']) { $worst = $r + ['over' => $over]; }
}
if ($worst !== null) {
    printf("       %d probes; worst overshoot past its own deadline: %+.2fs (%s)\n",
        count($GLOBALS['probe_reaping']), $worst['over'], $worst['mode']);
    // 8s of slack covers kill_process_tree()'s taskkill wait on a loaded box.
    t('every wedged probe was reaped promptly (nothing left holding our stdout)',
        $worst['over'] < 8.0,
        sprintf('%s overshot by %.2fs — a probe likely escaped kill_process_tree(), '
            . 'which is what hangs tools/test_all.php', $worst['mode'], $worst['over']));
} else {
    t('probes ran at all', false, 'no probe results were recorded');
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
