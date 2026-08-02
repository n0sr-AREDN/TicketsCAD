<?php
/**
 * Gate: a script that mutates the schema or spawns a subprocess must refuse
 * to run under a web SAPI.
 *
 * ── THE PROBLEM ──────────────────────────────────────────────────────
 *
 * sql/run_migrations.php, tools/install_fresh.php and tools/check-schema.php
 * live INSIDE the web root, begin with `require_once config.php`, and run to
 * completion. None of them has a PHP_SAPI guard. The root .htaccess blocks
 * only .git; there is no .htaccess in sql/ or tools/, and IIS — the platform
 * PR #10 explicitly targets — does not read .htaccess at all.
 *
 * So on a default install, an UNAUTHENTICATED
 *
 *     GET /sql/run_migrations.php
 *
 * applies every pending migration as the web user. ($argv is null under a web
 * SAPI, so --list and --force are both false and it goes straight to "apply".)
 * GET /tools/install_fresh.php runs the schema migration the same way.
 *
 * ── WHY NOW ──────────────────────────────────────────────────────────
 *
 * This is pre-existing, but openises/TicketsCAD PR #10 changes its severity.
 * On the hardened hosts that PR targets, exec() was removed by
 * disable_functions, so an HTTP-triggered migration run fataled at the first
 * subprocess and got no further. After the PR, proc_open() succeeds and the
 * run completes over HTTP. A fix that makes a script work everywhere also
 * makes it work from places it was never meant to be reachable from.
 *
 * ── WHY THIS IS A CONVENTION GAP, NOT A DESIGN QUESTION ──────────────
 *
 * Fourteen other CLI scripts in this project already carry the guard —
 * tools/create_admin.php, tools/backup_run.php, tools/restore.php,
 * tools/mint_external_api_token.php, sql/run_tts_engines.php,
 * proxy/zello-proxy.php and more. Nothing web-facing includes or executes the
 * three unguarded ones: api/migrations-check.php and migrations.php only
 * DISPLAY the command for an admin to run over SSH. Adding the guard breaks
 * nothing.
 *
 * The fix is one line per file, above the first require:
 *
 *     if (PHP_SAPI !== 'cli') { http_response_code(403); fwrite(STDERR, "CLI only\n"); exit(1); }
 *
 * The second half of this test asserts the convention still holds in scripts
 * that already have it, so the gate protects what exists as well as what is
 * missing — a fix that "passed" by someone deleting the other guards would be
 * worse than the bug.
 */

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');

$tests = 0;
$fails = 0;

function cli(bool $cond, string $label): void
{
    global $tests, $fails;
    $tests++;
    if (!$cond) {
        $fails++;
        echo "FAIL: $label\n";
    }
}

/**
 * Locate a SAPI guard and report the token offset it sits at, or null.
 * Comments are stripped first — a comment describing a guard is not a guard.
 */
function find_sapi_guard(array $toks): ?int
{
    $n = count($toks);
    for ($i = 0; $i < $n; $i++) {
        // PHP_SAPI !== 'cli'   /   PHP_SAPI != 'cli'
        if ($toks[$i]['id'] === T_STRING && $toks[$i]['text'] === 'PHP_SAPI') {
            for ($j = $i + 1; $j < min($i + 5, $n); $j++) {
                if (in_array($toks[$j]['id'], [T_IS_NOT_IDENTICAL, T_IS_NOT_EQUAL], true)) return $i;
            }
        }
        // php_sapi_name() !== 'cli'
        if ($toks[$i]['id'] === T_STRING && strtolower($toks[$i]['text']) === 'php_sapi_name') {
            for ($j = $i + 1; $j < min($i + 7, $n); $j++) {
                if (in_array($toks[$j]['id'], [T_IS_NOT_IDENTICAL, T_IS_NOT_EQUAL], true)) return $i;
            }
        }
    }
    return null;
}

/** First `require`/`require_once`/`include` token offset, or null. */
function first_require(array $toks): ?int
{
    foreach ($toks as $i => $t) {
        if (in_array($t['id'], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
            return $i;
        }
    }
    return null;
}

function tokens_no_comments(string $src): array
{
    $out = [];
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_WHITESPACE || $t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
            $out[] = ['id' => $t[0], 'text' => $t[1], 'line' => $t[2]];
        } else {
            $out[] = ['id' => null, 'text' => $t, 'line' => 0];
        }
    }
    return $out;
}

// ── The three that need the guard ────────────────────────────────────
// Each mutates the database and/or spawns a PHP subprocess, and each sits in
// a directory that is served by default.
$mustGuard = [
    'sql/run_migrations.php' => 'applies every pending migration; spawns a PHP subprocess per migration',
    'tools/install_fresh.php' => 'runs the fresh-install / upgrade schema migration',
    'tools/check-schema.php'  => 'with --repair re-applies schema migrations; discloses schema state otherwise',
];

// ── Scripts that already carry the guard (protect the convention) ────
$controlGroup = [
    'tools/create_admin.php',
    'tools/backup_run.php',
    'tools/restore.php',
    'tools/mint_external_api_token.php',
    'sql/run_tts_engines.php',
];

foreach ($mustGuard as $relPath => $why) {
    $abs = $root . '/' . $relPath;
    if (!is_file($abs)) {
        cli(false, "{$relPath} exists");
        continue;
    }
    $toks  = tokens_no_comments((string) file_get_contents($abs));
    $guard = find_sapi_guard($toks);

    cli($guard !== null,
        "{$relPath} refuses to run under a web SAPI ({$why}) — "
        . 'add: if (PHP_SAPI !== \'cli\') { http_response_code(403); exit(1); }');

    if ($guard !== null) {
        $req = first_require($toks);
        cli($req === null || $guard < $req,
            "{$relPath}: the SAPI guard comes BEFORE the first require — a guard placed after "
            . 'config.php has already connected to the database and run its side effects');
    }
}

foreach ($controlGroup as $relPath) {
    $abs = $root . '/' . $relPath;
    if (!is_file($abs)) {
        echo "SKIP: control script {$relPath} not present on this tree\n";
        continue;
    }
    $toks = tokens_no_comments((string) file_get_contents($abs));
    cli(find_sapi_guard($toks) !== null,
        "{$relPath} still carries its CLI-only guard (regression guard for the existing convention)");
}

// ── Defence in depth: a directory-level deny for the two CLI dirs ────
// Advisory, and deliberately NOT counted as a hard requirement on Apache
// alone, because IIS (the platform PR #10 targets) ignores .htaccess. A web
// server config that denies sql/ and tools/ is the belt to the guard's braces.
foreach (['sql', 'tools'] as $dir) {
    $hasDeny = is_file($root . '/' . $dir . '/.htaccess')
        || is_file($root . '/' . $dir . '/web.config');
    if (!$hasDeny) {
        echo "NOTE: {$dir}/ has no .htaccess or web.config deny rule — the PHP_SAPI guard is "
            . "the only thing standing between the web and these scripts.\n";
    }
}

echo "CLI-only script gate: " . ($tests - $fails) . " passed, $fails failed\n";
exit($fails ? 1 : 0);
