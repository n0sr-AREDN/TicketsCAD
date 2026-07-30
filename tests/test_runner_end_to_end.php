<?php
/**
 * The gate on the gate — end to end, through the real runner.
 *
 * tests/test_runner_contract.php already drives test_all_classify() directly,
 * one case per hole in the contract. What it CANNOT prove is that
 * tools/test_all.php actually consults that verdict and lets it decide the
 * process exit status. It checks that by grepping the runner's source for
 * fragments like `$totalFail > 0 || $erroredFiles` — which is a proxy, and a
 * brittle one: rename the variable and the assertion breaks without any
 * behaviour changing, or (far worse) wire the classifier in and then ignore
 * what it returns, and the grep still passes.
 *
 * So this file plants real test files on disk, runs the real runner over them,
 * and asserts on its real exit code and report. Same discipline CLAUDE.md
 * records for the bed-automation episode: drive the production path, not a
 * hand-seeded copy of it.
 *
 * The case that started it (Phase 128, 2026-07-29): deleting
 * _rbac_legacy_check() made tools/test_rbac.php fatal at Test 7 and
 * tools/test_security.php fatal at Test 19. Run directly, each printed a PHP
 * Fatal error and exited 255. Run through the old runner, both were reported
 * in the same shape as a clean pass and the suite total said 0 failed — every
 * assertion after the fatal simply vanished from the count.
 *
 * Probes are planted as tests/test_zzprobe_*.php and the runner is scoped to
 * them with --only=, so this file cannot recurse into the whole suite. A stale
 * probe left behind by a crashed run would poison every later full run, so the
 * sweep below removes any before starting and the last case checks the tree is
 * clean afterwards.
 *
 * Run directly:  php tests/test_runner_end_to_end.php
 */

$base   = realpath(__DIR__ . '/..');
$php    = PHP_BINARY;
$runner = $base . '/tools/test_all.php';

$pass = 0;
$fail = 0;
$failures = [];

function rte_ok(string $m): void
{
    global $pass;
    $pass++;
    echo "  PASS  $m\n";
}

function rte_bad(string $m, string $why = ''): void
{
    global $fail, $failures;
    $fail++;
    $line = $m . ($why !== '' ? " — $why" : '');
    echo "  FAIL  $line\n";
    $failures[] = $line;
}

function rte_check(string $m, bool $cond, string $why = ''): void
{
    $cond ? rte_ok($m) : rte_bad($m, $why);
}

/** Absolute path of a probe file, given its short suffix. */
function rte_path(string $suffix): string
{
    global $base;
    return $base . '/tests/test_zzprobe_' . $suffix . '.php';
}

/** Write a probe test file. Returns false if the write failed. */
function rte_plant(string $suffix, string $src): bool
{
    return @file_put_contents(rte_path($suffix), $src) !== false;
}

/**
 * Run the real runner scoped to one probe.
 *
 * @return array{0:int,1:string} exit code, combined output
 */
function rte_run(string $marker, ?string $noHttp = null): array
{
    global $php, $runner;

    $saved = getenv('NEWUI_TEST_NO_HTTP');
    if ($noHttp === null) {
        putenv('NEWUI_TEST_NO_HTTP');            // unset for this child
    } else {
        putenv('NEWUI_TEST_NO_HTTP=' . $noHttp);
    }

    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($runner)
        . ' --only=' . escapeshellarg('zzprobe_' . $marker) . ' 2>&1';
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);

    // Restore, so one case cannot leak into the next.
    if ($saved === false) {
        putenv('NEWUI_TEST_NO_HTTP');
    } else {
        putenv('NEWUI_TEST_NO_HTTP=' . $saved);
    }

    return [$code, implode("\n", $out)];
}

echo "=== test_all.php end-to-end (real runner, planted probes) ===\n\n";

// A crashed earlier run must not be able to break the full suite.
$stale = glob($base . '/tests/test_zzprobe_*.php') ?: [];
foreach ($stale as $f) {
    @unlink($f);
}
rte_check('no stale probe files left by an earlier run', $stale === [],
    count($stale) . ' swept: ' . implode(', ', array_map('basename', $stale)));

if (!is_file($runner)) {
    rte_bad('tools/test_all.php present');
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(1);
}
rte_ok('tools/test_all.php present');

// ── 1. The Phase 128 case: a file that fatals part-way through ───────────
// tools/test_rbac.php's exact shape — some assertions pass, then a call to a
// function that no longer exists. The runner must not report this as clean.
echo "\n-- A test file that fatals part-way through --\n";
$fatalSrc = "<?php\n"
    . "// temporary probe written by tests/test_runner_end_to_end.php\n"
    . "\$pass = 0; \$fail = 0;\n"
    . "echo \"=== probe ===\\n\";\n"
    . "echo \"[Test 1] fine... PASS\\n\"; \$pass++;\n"
    . "echo \"[Test 2] about to die... \";\n"
    . "__zzprobe_function_that_does_not_exist();\n"
    . "echo \"\\n=== \$pass passed, \$fail failed ===\\n\";\n"
    . "exit(\$fail > 0 ? 1 : 0);\n";

if (!rte_plant('fatal', $fatalSrc)) {
    rte_bad('could not write the fatal probe', rte_path('fatal'));
} else {
    [$code, $text] = rte_run('fatal');
    @unlink(rte_path('fatal'));

    rte_check('a fatal in a test file makes the runner exit non-zero', $code !== 0,
        "exit {$code}");
    rte_check('the runner names the file that died',
        strpos($text, 'test_zzprobe_fatal.php') !== false,
        'probe filename absent from the report');
    rte_check('it is reported as ERRORED, not counted as passing',
        strpos($text, 'Errored test files') !== false,
        'no errored-files section in the report');
    rte_check('the reason names the fatal',
        stripos($text, 'fatal error') !== false || stripos($text, 'uncaught') !== false,
        'reason did not mention the fatal');
    rte_check('the assertions it did print are NOT added to the total',
        (bool) preg_match('/TOTAL:\s*0 passed/', $text),
        'an errored file contributed counts to the total');
    rte_check('the child output is echoed so CI can diagnose it',
        strpos($text, '__zzprobe_function_that_does_not_exist') !== false,
        'runner swallowed the child stdout');
    rte_check('the fatal probe is cleaned up', !file_exists(rte_path('fatal')));
}

// ── 2. The more dangerous shape: exit(0) with no summary ─────────────────
// A `die("prerequisite missing")` or a guard clause's exit(0). PHP hands back
// a clean status, so the exit code alone cannot catch this one.
echo "\n-- A test file that exits 0 without reporting a result --\n";
$silentSrc = "<?php\n"
    . "// temporary probe written by tests/test_runner_end_to_end.php\n"
    . "echo \"=== probe ===\\n\";\n"
    . "echo \"[Test 1] fine... PASS\\n\";\n"
    . "die(\"prerequisite missing, giving up\\n\");\n";

if (!rte_plant('silent', $silentSrc)) {
    rte_bad('could not write the silent-exit probe', rte_path('silent'));
} else {
    [$code, $text] = rte_run('silent');
    @unlink(rte_path('silent'));

    rte_check('a file that exits 0 with no summary makes the runner exit non-zero',
        $code !== 0, "exit {$code}");
    rte_check('the runner names the file that reported nothing',
        strpos($text, 'test_zzprobe_silent.php') !== false);
    rte_check('the silent-exit probe is cleaned up', !file_exists(rte_path('silent')));
}

// ── 3. A declared SKIP must stay green ──────────────────────────────────
// Many files in this suite cannot run on a virgin DB and say so. Turning
// those red would make the gate unusable, so the fix must not overreach.
echo "\n-- A declared skip stays green --\n";
$skipSrc = "<?php\n"
    . "// temporary probe written by tests/test_runner_end_to_end.php\n"
    . "echo \"SKIP: probe declares its prerequisites absent\\n\";\n"
    . "echo \"\\n=== 0 passed, 0 failed ===\\n\";\n"
    . "exit(0);\n";

if (!rte_plant('skip', $skipSrc)) {
    rte_bad('could not write the skip probe', rte_path('skip'));
} else {
    [$code, $text] = rte_run('skip');
    @unlink(rte_path('skip'));

    rte_check('a declared SKIP (0/0 + "SKIP:") keeps the runner green', $code === 0,
        "exit {$code}: " . $text);
    rte_check('the skip is reported as a skip, not a pass',
        strpos($text, 'SKIP') !== false && strpos($text, 'declared a skip') !== false);
    rte_check('the skip probe is cleaned up', !file_exists(rte_path('skip')));
}

// ── 4. An ordinary passing file still passes and still counts ───────────
echo "\n-- An ordinary passing file --\n";
$cleanSrc = "<?php\n"
    . "// temporary probe written by tests/test_runner_end_to_end.php\n"
    . "echo \"=== probe ===\\n\";\n"
    . "echo \"  PASS  a\\n  PASS  b\\n  PASS  c\\n\";\n"
    . "echo \"\\n=== 3 passed, 0 failed ===\\n\";\n"
    . "exit(0);\n";

if (!rte_plant('clean', $cleanSrc)) {
    rte_bad('could not write the clean probe', rte_path('clean'));
} else {
    [$code, $text] = rte_run('clean');
    @unlink(rte_path('clean'));

    rte_check('a clean file keeps the runner green', $code === 0, "exit {$code}: " . $text);
    rte_check('its assertions reach the total',
        (bool) preg_match('/TOTAL:\s*3 passed,\s*0 failed,\s*0 errored/', $text),
        'total did not pick up the 3 assertions');
    rte_check('the clean probe is cleaned up', !file_exists(rte_path('clean')));
}

// ── 5. @requires-http under NEWUI_TEST_NO_HTTP=1 must really skip ───────
// The probe fatals on purpose. If NO_HTTP=1 truly skips it the run is green;
// if the marker were ignored the run would go red. Asserting both directions
// proves the file was skipped rather than merely tolerated.
echo "\n-- @requires-http honours NEWUI_TEST_NO_HTTP=1 --\n";
$httpSrc = "<?php\n"
    . "/**\n"
    . " * temporary probe written by tests/test_runner_end_to_end.php\n"
    . " * @requires-http\n"
    . " */\n"
    . "__zzprobe_this_file_should_never_have_run();\n";

if (!rte_plant('http', $httpSrc)) {
    rte_bad('could not write the requires-http probe', rte_path('http'));
} else {
    [$skipCode, $skipText] = rte_run('http', '1');
    [$runCode, $runText]   = rte_run('http', null);
    @unlink(rte_path('http'));

    rte_check('@requires-http + NEWUI_TEST_NO_HTTP=1 keeps the runner green',
        $skipCode === 0, "exit {$skipCode}: " . $skipText);
    rte_check('the runner says it skipped the file',
        strpos($skipText, 'Skipping') !== false
        && strpos($skipText, 'test_zzprobe_http.php') !== false);
    rte_check('the skipped file really did not run',
        strpos($skipText, '__zzprobe_this_file_should_never_have_run') === false,
        'the probe executed despite being reported as skipped');
    rte_check('without NEWUI_TEST_NO_HTTP the same file runs and goes red',
        $runCode !== 0, "exit {$runCode} — the skip marker suppressed it unconditionally");
    rte_check('the requires-http probe is cleaned up', !file_exists(rte_path('http')));
}

// ── 6. --only= cannot be mistaken for a full run ────────────────────────
// This flag exists for the cases above. A filtered run that reads like a
// full one would be a new way to report green on evidence nobody gathered.
echo "\n-- --only= announces itself --\n";
if (rte_plant('clean', $cleanSrc)) {
    [$code, $text] = rte_run('clean');
    @unlink(rte_path('clean'));
    rte_check('a filtered run is labelled as filtered in the header',
        strpos($text, 'FILTERED RUN') !== false);
    rte_check('a filtered run is labelled as filtered at the total',
        (bool) preg_match('/TOTAL:.*\n.*FILTERED RUN/', $text),
        'the TOTAL line is not followed by the filtered-run notice');
} else {
    rte_bad('could not re-write the clean probe for the filter check');
}

// ── 7. The runner refuses to nest more than one level ───────────────────
// A test file invoking the runner is now legitimate (this file does it). A
// runner invoked from a file that a runner invoked from a file is a mistake
// that would fork-bomb the machine.
echo "\n-- Recursion backstop --\n";
$savedDepth = getenv('NEWUI_TEST_ALL_DEPTH');
putenv('NEWUI_TEST_ALL_DEPTH=2');
$dOut = [];
$dCode = 0;
exec(escapeshellarg($php) . ' ' . escapeshellarg($runner)
    . ' --only=' . escapeshellarg('zzprobe_nothing_matches_this') . ' 2>&1', $dOut, $dCode);
if ($savedDepth === false) {
    putenv('NEWUI_TEST_ALL_DEPTH');
} else {
    putenv('NEWUI_TEST_ALL_DEPTH=' . $savedDepth);
}
$dText = implode("\n", $dOut);
rte_check('the runner refuses to nest two levels deep', $dCode !== 0, "exit {$dCode}");
rte_check('and says why', stripos($dText, 'refusing to nest') !== false, $dText);

// ── 8. Nothing was left behind ──────────────────────────────────────────
$left = glob($base . '/tests/test_zzprobe_*.php') ?: [];
foreach ($left as $f) {
    @unlink($f);
}
rte_check('every probe file was removed', $left === [],
    'left behind: ' . implode(', ', array_map('basename', $left)));

if ($fail > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
}
// Canonical summary — tools/test_all.php will not count a file that does
// not print this exact shape, and errors on one that prints nothing.
echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
