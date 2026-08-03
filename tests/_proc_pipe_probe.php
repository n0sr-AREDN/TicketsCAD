<?php
/**
 * Helper for tests/test_proc_open_pipe_deadlock.php (openises/TicketsCAD#28).
 * NOT a test file — the leading underscore keeps it out of the runner's
 * `test_*.php` glob.
 *
 * Runs ONE probe and prints a single JSON line. It is always invoked as a
 * subprocess under a hard deadline, because two of the modes here are the
 * PRE-FIX code, whose entire defect is that it can wedge forever: a positive
 * control for a deadlock cannot be run in-process without risking the suite.
 *
 * Modes:
 *   child <stdoutBytes> <stderrBytes> <sleepS>
 *       The reproduction child itself. Writes stderr, then stdout, then
 *       optionally sleeps, then exits.
 *   fixed-tts     <...>   real inc/tts/engine.php tts_run_pipe()
 *   prefix-tts    <...>   verbatim copy of the tts_run_pipe() loop as it was
 *                         before the fix (git show 5aced05:inc/tts/engine.php)
 *   fixed-runpipe <...>   real ZelloProxyApp::runPipe() via reflection
 *   prefix-runpipe<...>   verbatim copy of runPipe() as it was before the fix
 *
 * Trailing args for every non-`child` mode:
 *   <stdoutBytes> <stderrBytes> <sleepS> <timeoutS> <stdinBytes>
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$mode = $argv[1] ?? '';
$so   = (int) ($argv[2] ?? 200000);
$se   = (int) ($argv[3] ?? 1);
$sl   = (int) ($argv[4] ?? 0);
$tmo  = (int) ($argv[5] ?? 5);
$sin  = (int) ($argv[6] ?? 0);

// ── The reproduction child ──────────────────────────────────────────────
if ($mode === 'child') {
    if ($se > 0) fwrite(STDERR, str_repeat('E', $se));
    if ($so > 0) {
        // Written in one call so the child blocks inside it when the reader
        // stops draining — that is the half of the deadlock we are recreating.
        echo str_repeat('a', $so);
    }
    if ($sl > 0) sleep($sl);
    exit(0);
}

$childCmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
    . ' child ' . $so . ' ' . $se . ' ' . $sl;
$input = $sin > 0 ? str_repeat('S', $sin) : '';

/** Verbatim pre-fix tts_run_pipe() — the deadlocking original. */
function prefix_tts_run_pipe(string $cmd, string $input, int $timeoutSec = 30): ?string
{
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) return null;
    // Non-blocking write+read to avoid pipe deadlock on large payloads.
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $out = ''; $start = time();
    do {
        $out .= (string) stream_get_contents($pipes[1]);
        $status = proc_get_status($proc);
        if (!$status['running']) { $out .= (string) stream_get_contents($pipes[1]); break; }
        if (time() - $start > $timeoutSec) { proc_terminate($proc); break; }
        usleep(10000);
    } while (true);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return $out === '' ? null : $out;
}

/** Verbatim pre-fix ZelloProxyApp::runPipe() — the deadlocking original. */
function prefix_run_pipe(string $cmd, string $input, int $timeoutS): ?string
{
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) return null;
    if ($input !== '') { fwrite($pipes[0], $input); }
    fclose($pipes[0]);

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + $timeoutS;
    do {
        $chunk = fread($pipes[1], 65536);
        if ($chunk !== false && $chunk !== '') $stdout .= $chunk;
        $errc = fread($pipes[2], 8192);
        if ($errc !== false && $errc !== '') $stderr .= $errc;

        $status = proc_get_status($proc);
        if (!$status['running']) {
            while (($chunk = fread($pipes[1], 65536)) !== false && $chunk !== '') $stdout .= $chunk;
            break;
        }
        if (microtime(true) > $deadline) {
            proc_terminate($proc, 9);
            fclose($pipes[1]); fclose($pipes[2]);
            proc_close($proc);
            return null;
        }
        usleep(5000);
    } while (true);

    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    if ($exit !== 0) return null;
    return $stdout;
}

$t0  = microtime(true);
$out = null;
$err = '';

try {
    switch ($mode) {
        case 'fixed-tts':
            require_once __DIR__ . '/../config.php';
            require_once __DIR__ . '/../inc/tts/engine.php';
            $out = tts_run_pipe($childCmd, $input, $tmo);
            break;

        case 'prefix-tts':
            $out = prefix_tts_run_pipe($childCmd, $input, $tmo);
            break;

        case 'prefix-runpipe':
            $out = prefix_run_pipe($childCmd, $input, $tmo);
            break;

        case 'fixed-runpipe':
            if (!is_file(__DIR__ . '/../vendor/autoload.php')) {
                echo json_encode(['skip' => 'vendor/ absent']) . "\n";
                exit(0);
            }
            require_once __DIR__ . '/../vendor/autoload.php';
            // ZelloProxyApp calls \plog() for diagnostics; the daemon defines it.
            if (!function_exists('plog')) { function plog($m) {} }
            require_once __DIR__ . '/../proxy/ZelloProxyApp.php';
            $rc  = new ReflectionClass(NewUI\Proxy\ZelloProxyApp::class);
            $app = $rc->newInstanceWithoutConstructor();
            $m   = $rc->getMethod('runPipe');
            $m->setAccessible(true);
            $out = $m->invoke($app, $childCmd, $input, $tmo);
            break;

        default:
            echo json_encode(['error' => 'unknown mode ' . $mode]) . "\n";
            exit(2);
    }
} catch (Throwable $e) {
    $err = get_class($e) . ': ' . $e->getMessage();
}

echo json_encode([
    'mode'    => $mode,
    'elapsed' => round(microtime(true) - $t0, 3),
    'bytes'   => strlen((string) $out),
    'null'    => $out === null,
    'error'   => $err,
]) . "\n";
exit(0);
