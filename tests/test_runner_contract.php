<?php
/**
 * The gate on the gate.
 *
 * tools/test_all.php is what .github/workflows/qa.yml and the pre-commit hook
 * treat as proof that the tree is sound. Until 2026-07-29 it decided a file's
 * result from one regex over stdout and consulted nothing else, so a test file
 * that stopped early — a `die("prerequisite missing")`, a guard clause's
 * `exit(0)`, a summary worded differently — parsed as "0 passed, 0 failed" and
 * was reported in the same breath as a clean pass. Fourteen files locally and
 * sixteen in CI were in exactly that state: ~290 assertions the headline number
 * did not contain, and no way to tell them from files that had died.
 *
 * These cases pin each hole shut. They drive the REAL classifier out of
 * tools/suite_contract.php — not a re-implementation of it — because a gate
 * verified only against a hand-written copy of its own logic is the failure
 * this codebase keeps rediscovering (CLAUDE.md: "tests that pass by
 * hand-seeding state the real writer never produces").
 *
 * Run directly:  php tests/test_runner_contract.php
 */

require_once __DIR__ . '/../tools/suite_contract.php';

$pass = 0;
$fail = 0;
$failures = [];

function rc_check(string $name, bool $cond, string $why = ''): void
{
    global $pass, $fail, $failures;
    if ($cond) {
        echo "  PASS  $name\n";
        $pass++;
    } else {
        echo "  FAIL  $name" . ($why !== '' ? " — $why" : '') . "\n";
        $fail++;
        $failures[] = $name . ($why !== '' ? " — $why" : '');
    }
}

/** Assert the classifier's verdict for a given child output + exit code. */
function rc_status(string $name, string $output, int $exit, string $want): void
{
    $v = test_all_classify($output, $exit);
    rc_check($name, $v['status'] === $want,
        "wanted {$want}, got {$v['status']}"
        . ($v['reasons'] ? ' (' . implode('; ', $v['reasons']) . ')' : ''));
}

echo "=== test_all.php result contract ===\n\n";

// ── The happy path still works ───────────────────────────────────────
echo "-- Normal results --\n";
rc_status('a clean run is PASS',
    "  PASS  a\n  PASS  b\n\n=== 2 passed, 0 failed ===\n", 0, 'PASS');

rc_status('a run with failures is FAIL',
    "  PASS  a\n  FAIL  b\n\n=== 1 passed, 1 failed ===\n", 1, 'FAIL');

rc_status('failures with a 0 exit code are still FAIL (not swallowed)',
    "  FAIL  b\n\n=== 1 passed, 1 failed ===\n", 0, 'FAIL');

$v = test_all_classify("  FAIL  b\n\n=== 1 passed, 1 failed ===\n", 0);
rc_check('a test that fails but exits 0 is called out as a hygiene problem',
    !empty($v['reasons']) && strpos(implode(' ', $v['reasons']), 'exit(1)') !== false,
    'reasons: ' . implode('; ', $v['reasons']));

$v = test_all_classify("  PASS  a\n=== 40 passed, 0 failed ===\n", 0);
rc_check('a passing file contributes its counts', $v['pass'] === 40 && $v['fail'] === 0,
    "got {$v['pass']}/{$v['fail']}");

// ── Hole 1: died with no output at all ───────────────────────────────
echo "\n-- Abnormal termination --\n";
rc_status('no output at all is ERROR, not a pass', '', 255, 'ERROR');

rc_status('a PHP fatal part-way through is ERROR',
    "  PASS  a\nPHP Fatal error:  Uncaught Error: Call to undefined function foo() in x.php:12\n",
    255, 'ERROR');

// The one the old runner could not see at all: a fatal whose exit status
// was laundered back to 0 by a shutdown handler.
rc_status('a fatal in the output is ERROR even with exit code 0',
    "  PASS  a\nPHP Fatal error:  Allowed memory size exhausted\n=== 1 passed, 0 failed ===\n",
    0, 'ERROR');

rc_status('a parse error is ERROR',
    "PHP Parse error:  syntax error, unexpected token in x.php on line 3\n", 255, 'ERROR');

rc_status('an uncaught throwable is ERROR even with exit code 0',
    "  PASS  a\nUncaught PDOException: SQLSTATE[42S22]\n=== 1 passed, 0 failed ===\n", 0, 'ERROR');

// ── Hole 2: exited 0 before printing a summary ───────────────────────
echo "\n-- Silent early exit (the shape every guard clause in this suite uses) --\n";
rc_status('die("...") mid-file — output but no summary, exit 0 — is ERROR',
    "  PASS  a\nprerequisite missing\n", 0, 'ERROR');

rc_status('a differently-worded summary is ERROR, not a silent 0/0',
    "=== Summary ===\nPassed: 77\nFailed: 0\n", 0, 'ERROR');

rc_status('"(22 pass, 0 fail)" is not a canonical summary',
    "=== Results: PASS (22 pass, 0 fail) ===\n", 0, 'ERROR');

rc_status('"8/8 passed" is not a canonical summary',
    "Phase 63 location-freshness tests: 8/8 passed\n", 0, 'ERROR');

// ── Hole 3: asserted nothing, said nothing about why ─────────────────
echo "\n-- Zero assertions --\n";
rc_status('0/0 with a declared SKIP is SKIP',
    "SKIP: composer install has not been run\n=== 0 passed, 0 failed ===\n", 0, 'SKIP');

rc_status('0/0 with no skip declared is ERROR',
    "=== 0 passed, 0 failed ===\n", 0, 'ERROR');

$v = test_all_classify("SKIP: no seeded data\n=== 0 passed, 0 failed ===\n", 0);
rc_check('a declared skip contributes no assertions', $v['pass'] === 0 && $v['fail'] === 0,
    "got {$v['pass']}/{$v['fail']}");

// ── Hole 4: exit code contradicts the summary ────────────────────────
echo "\n-- Exit code is checked first --\n";
rc_status('non-zero exit with a clean summary is ERROR (the file did not finish)',
    "  PASS  a\n=== 2 passed, 0 failed ===\n", 255, 'ERROR');

rc_status('non-zero exit WITH reported failures is FAIL, not ERROR',
    "  FAIL  b\n=== 62 passed, 1 failed ===\n", 1, 'FAIL');

$v = test_all_classify("  PASS  a\n=== 2 passed, 0 failed ===\n", 255);
rc_check("an errored file's counts are reported but must not be trusted",
    $v['status'] === 'ERROR' && !empty($v['reasons']),
    'reasons: ' . implode('; ', $v['reasons']));

// ── Hole 5: reported fewer failures than it printed ──────────────────
echo "\n-- Under-reported failures --\n";
rc_status('[FAIL] lines exceeding the reported failure count is ERROR',
    "[PASS] a\n[FAIL] b\n[FAIL] c\n=== 1 passed, 0 failed ===\n", 0, 'ERROR');

rc_status('a failure marker inside a line (not at its start) is not miscounted',
    "  PASS  guard rejects the [FAIL] token in a message\n=== 1 passed, 0 failed ===\n", 0, 'PASS');

// ── Hole 6: the summary was not the end ──────────────────────────────
echo "\n-- Summary must be final --\n";
rc_status('a decorative line after the summary is fine',
    "=== 5 passed, 0 failed ===\n==========================\n", 0, 'PASS');

rc_status('a per-section line does not stand in for the total (last match wins)',
    "-- section 1 --\n=== 2 passed, 0 failed ===\n-- section 2 --\n=== 30 passed, 0 failed ===\n",
    0, 'PASS');

$v = test_all_classify(
    "-- section 1 --\n=== 2 passed, 0 failed ===\n-- section 2 --\n=== 30 passed, 0 failed ===\n", 0);
rc_check('the LAST summary is the one counted, not the first', $v['pass'] === 30,
    "got {$v['pass']}");

rc_status('substantial output after the summary is ERROR',
    "=== 5 passed, 0 failed ===\n" . str_repeat("still running...\n", 20), 0, 'ERROR');

// ── The runner itself ────────────────────────────────────────────────
echo "\n-- Runner wiring --\n";
$runner = file_get_contents(__DIR__ . '/../tools/test_all.php');
rc_check('test_all.php uses the shared classifier rather than its own copy',
    strpos($runner, "require_once __DIR__ . '/suite_contract.php'") !== false
    && strpos($runner, 'test_all_classify(') !== false);
rc_check('test_all.php exits non-zero when any file errored',
    strpos($runner, '$totalFail > 0 || $erroredFiles') !== false);
rc_check('errored files are excluded from the pass total',
    strpos($runner, "\$verdict['status'] !== 'ERROR'") !== false);
rc_check('errored files get their output printed for diagnosis',
    substr_count($runner, 'test_all_tail(') >= 2);
rc_check('the @requires-http skip convention is preserved',
    strpos($runner, '@requires-http') !== false && strpos($runner, 'NEWUI_TEST_NO_HTTP') !== false);
rc_check('suite_contract.php is not itself discovered as a test file',
    !fnmatch('test_*.php', basename(__DIR__ . '/../tools/suite_contract.php')));

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
