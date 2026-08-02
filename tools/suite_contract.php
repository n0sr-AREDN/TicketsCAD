<?php
/**
 * The contract tools/test_all.php holds every test file to, and the single
 * function that decides whether a child run satisfied it.
 *
 * This lives in its own file, deliberately, for two reasons:
 *
 *   1. tests/test_runner_contract.php drives the REAL classifier rather than
 *      a copy of it. A gate whose own logic is only exercised by a hand-made
 *      re-implementation is the thing this project keeps getting bitten by
 *      (see the `assigns.rec_facility_id` and `un_status.extra_data_target`
 *      episodes in CLAUDE.md — tests that passed against a state the real
 *      writer never produced).
 *   2. tools/test_all.php discovers `test_*.php`, so a shared helper cannot
 *      be named that way without the runner trying to run it as a test. The
 *      filename here is chosen to fall outside that glob.
 *
 * ── WHY ANY OF THIS EXISTS ────────────────────────────────────────────
 *
 * Until 2026-07-29 the runner decided a file's result from one regex over
 * its stdout — `N passed, M failed` — and nothing else. A file whose summary
 * was worded differently, or which stopped before printing one, parsed as
 * 0/0 and was reported alongside the clean passes. That was 14 of 96 files
 * locally and 16 in CI: roughly 290 real assertions the headline "3974
 * passing, 0 failed" did not include, behind a number that two gates
 * (.github/workflows/qa.yml and the pre-commit hook) treat as proof. A
 * silent early `exit(0)` — the shape every "prerequisite missing" guard in
 * this suite uses — was literally indistinguishable from success.
 */

/** Lines of child output echoed for a file that failed or errored. */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

if (!defined('TEST_ALL_TAIL_LINES')) {
    define('TEST_ALL_TAIL_LINES', 25);
}

/** Non-blank lines allowed after the summary before we call it non-final. */
if (!defined('TEST_ALL_TRAILING_SLACK')) {
    define('TEST_ALL_TRAILING_SLACK', 10);
}

/**
 * Classify one child test run.
 *
 * Every file must satisfy ALL of:
 *
 *   1. Exit code 0 — OR a non-zero code accompanied by the failures that
 *      explain it. Checked FIRST and it wins: a child that died has counts
 *      that are fiction, whatever its stdout said. (Same discipline as
 *      sql/run_migrations.php — exit code first, string matching second.)
 *   2. A canonical summary line: "<N> passed, <M> failed". Any other wording
 *      is not a summary, because the runner cannot tell one from a file that
 *      died before writing it.
 *   3. If N and M are both 0, the output must ALSO carry a skip marker saying
 *      why nothing ran. A test that quietly asserts nothing is not a pass.
 *   4. No PHP fatal/parse error and no uncaught throwable in the output, even
 *      when the exit code came back 0 — a shutdown handler can eat the status.
 *   5. No more failure markers in the body than the summary admits to. Catches
 *      a file that prints [FAIL] lines and then reports 0 failed.
 *   6. The summary is the end of the output. Substantial output after it means
 *      the file kept going, so the summary was not its final accounting.
 *
 * @return array{status:string,pass:int,fail:int,reasons:string[]}
 *         status is one of PASS, FAIL, SKIP, ERROR.
 */
function test_all_classify(string $outputText, int $exitCode): array
{
    $reasons = [];

    // ── The summary: LAST canonical match wins ───────────────────────
    // Last, not first: a summary is by definition the file's final
    // accounting. Taking the first match would let a per-section line
    // stand in for a total the file never got around to printing.
    $pass = 0;
    $fail = 0;
    $n = preg_match_all('/(\d+)\s+passed,\s+(\d+)\s+failed/', $outputText, $matches, PREG_SET_ORDER);
    if ($n > 0) {
        $pass = (int) $matches[$n - 1][1];
        $fail = (int) $matches[$n - 1][2];
    }

    // ── 1. Exit code first, and it always wins ───────────────────────
    // A non-zero child is only NOT an error when the file also reported
    // the failures that explain it — i.e. the test ran to completion,
    // found problems, and exited 1 on purpose.
    if ($exitCode !== 0 && ($n === 0 || $fail === 0)) {
        $reasons[] = "exit code {$exitCode} with no failures reported — the file "
            . 'did not finish; its counts cannot be trusted';
    }
    // The mirror image is a hygiene problem, not an untrustworthy result:
    // the file found failures and still handed back a clean status. It is
    // reported as FAILED and additionally called out.
    $exitHygiene = ($exitCode === 0 && $n > 0 && $fail > 0);

    // ── 2. A canonical summary must exist ────────────────────────────
    if ($n === 0) {
        $reasons[] = 'no "N passed, M failed" summary line — the file did not '
            . 'report a result (died early, exited early, or worded its summary differently)';
    }

    // ── 4. PHP-level breakage, even with a 0 exit code ───────────────
    if (preg_match('/^\s*(PHP )?(Fatal error|Parse error)\s*:/mi', $outputText, $pm)) {
        $reasons[] = 'PHP ' . strtolower($pm[2]) . ' in output';
    }
    if (preg_match('/\bUncaught\s+(\w+(?:\\\\\w+)*)\b/', $outputText, $um)) {
        $reasons[] = "uncaught {$um[1]} in output";
    }

    if ($n > 0) {
        // ── 3. Zero assertions must be an explicit, stated skip ──────
        if ($pass === 0 && $fail === 0 && stripos($outputText, 'skip') === false) {
            $reasons[] = 'reported 0 passed and 0 failed without declaring a SKIP — '
                . 'a file that asserts nothing is not a pass';
        }

        // ── 5. Failure markers the summary does not admit to ─────────
        $markers = preg_match_all('/^[\s>*-]*(\[FAIL\]|\x{2717}|FAIL[:\s\x{2014}])/mu', $outputText);
        if ($markers > $fail) {
            $reasons[] = "printed {$markers} failure marker(s) but reported only {$fail} failed";
        }

        // ── 6. The summary must be the end of the output ─────────────
        $lines = preg_split('/\r?\n/', rtrim($outputText));
        $idx = -1;
        foreach ($lines as $i => $line) {
            if (preg_match('/\d+\s+passed,\s+\d+\s+failed/', $line)) {
                $idx = $i;
            }
        }
        if ($idx >= 0) {
            $after = 0;
            for ($i = $idx + 1, $c = count($lines); $i < $c; $i++) {
                if (trim($lines[$i]) !== '') {
                    $after++;
                }
            }
            if ($after > TEST_ALL_TRAILING_SLACK) {
                $reasons[] = "{$after} more lines of output after the summary — the summary was not the end of the run";
            }
        }
    }

    if ($reasons) {
        return ['status' => 'ERROR', 'pass' => $pass, 'fail' => $fail, 'reasons' => $reasons];
    }
    if ($fail > 0) {
        return [
            'status' => 'FAIL',
            'pass' => $pass,
            'fail' => $fail,
            'reasons' => $exitHygiene
                ? ['note: reported failures but exited 0 — the file should `exit(1)` when it fails']
                : [],
        ];
    }
    if ($pass === 0 && $fail === 0) {
        return ['status' => 'SKIP', 'pass' => 0, 'fail' => 0, 'reasons' => []];
    }
    return ['status' => 'PASS', 'pass' => $pass, 'fail' => $fail, 'reasons' => []];
}

/** Last N lines of a child's output, indented for the report. */
function test_all_tail(string $outputText, int $lines = TEST_ALL_TAIL_LINES): string
{
    $all = preg_split('/\r?\n/', rtrim($outputText));
    if ($all === false || $all === [''] || $all === []) {
        return "      (no output)\n";
    }
    $slice = array_slice($all, -$lines);
    $out = '';
    if (count($all) > $lines) {
        $out .= '      ... (' . (count($all) - $lines) . " earlier lines omitted)\n";
    }
    foreach ($slice as $line) {
        $out .= '      ' . $line . "\n";
    }
    return $out;
}
