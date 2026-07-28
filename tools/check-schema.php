<?php
/**
 * Check (and optionally repair) this install's database structure.
 *
 * Phase 125 (2026-07-26). Answers the question no other tool could: does my
 * database actually have the columns this version of TicketsCAD writes to?
 *
 * The migration runner's tracker records whether a script RAN. It cannot know
 * whether the schema that script produced still exists — so a table dropped
 * during crash recovery, or a database restored from an older backup, reports
 * as "0 pending" while saves fail with an unexplained HTTP 400.
 *
 * Usage:
 *   php tools/check-schema.php              report only (default; changes nothing)
 *   php tools/check-schema.php --repair     re-apply the schema migrations, then re-check
 *   php tools/check-schema.php --quiet       print only problems (for cron)
 *
 * Exit codes:  0 = schema matches   1 = schema is behind   2 = could not check
 */

declare(strict_types=1);

chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/db.php';
require_once 'inc/functions.php';
require_once 'inc/schema-verify.php';

$argvv  = $argv ?? [];
$repair = in_array('--repair', $argvv, true);
$quiet  = in_array('--quiet', $argvv, true);

function say(string $s = ''): void
{
    echo $s . "\n";
}

/**
 * Print a verification result. Returns true when the schema is fine.
 */
function report(array $v, bool $quiet): bool
{
    if (!$v['available']) {
        say('Could not check the schema: ' . ($v['error'] ?? 'unknown reason'));
        say('This is not a sign that anything is wrong with your data.');
        return false;
    }
    if ($v['ok']) {
        if (!$quiet) {
            say('Schema OK.');
            say(sprintf('  %d tables and %d columns verified against this version.',
                $v['checked_tables'], $v['checked_columns']));
        }
        return true;
    }

    say('SCHEMA IS BEHIND THE CODE');
    say();
    say('Your data is intact. The database STRUCTURE is missing things this');
    say('version of TicketsCAD writes to, so the affected screens will fail to');
    say('save until it is brought up to date.');
    say();
    foreach ($v['missing_tables'] as $t) {
        say("  missing table   `{$t}`");
    }
    // Pad the table names so the column lists line up in a column — with a
    // handful of tables listed, ragged output is noticeably harder to scan.
    $width = 0;
    foreach (array_keys($v['missing_columns']) as $t) { $width = max($width, strlen($t)); }
    foreach ($v['missing_columns'] as $t => $cols) {
        say('  missing on ' . str_pad("`{$t}`:", $width + 3) . ' ' . implode(', ', $cols));
    }
    say();
    return false;
}

$before = schema_verify();

if (!$before['available']) {
    report($before, $quiet);
    exit(2);
}

if ($before['ok']) {
    report($before, $quiet);
    exit(0);
}

report($before, $quiet);

if (!$repair) {
    say('To fix this, run:');
    say();
    say('    php tools/check-schema.php --repair');
    say();
    say('That re-applies the schema migrations. They are idempotent and do not');
    say('delete data. Take a backup first if you want to be certain:');
    say();
    say('    php tools/backup_run.php');
    say();
    exit(1);
}

// ── Repair ───────────────────────────────────────────────────────────────
say('REPAIRING');
say();
say('Re-applying the schema migrations. Nothing is deleted; each migration');
say('checks the live schema and only adds what is absent.');
say();

$runner = __DIR__ . '/../sql/run_migrations.php';
if (!is_file($runner)) {
    say('Could not find sql/run_migrations.php — repair cannot continue.');
    exit(2);
}

$php = PHP_BINARY ?: 'php';
$cmd = escapeshellarg($php) . ' ' . escapeshellarg($runner) . ' --force 2>&1';
$out = [];
$rc  = 0;
exec($cmd, $out, $rc);
foreach ($out as $line) {
    say('  ' . $line);
}
say();

if ($rc !== 0) {
    say('The migration run reported a failure (exit ' . $rc . ').');
    say('Read the output above — it names the script that failed.');
    say('Your data has not been deleted. If you are stuck, open an issue with');
    say('that output at https://github.com/openises/TicketsCAD/issues');
    exit(1);
}

// Re-check in a FRESH process: this one has a cached schema view and a cached
// manifest, and a repair that "passes" only because of stale state is worthless.
say('Re-checking in a fresh process...');
say();
$out2 = [];
$rc2  = 0;
exec(escapeshellarg($php) . ' ' . escapeshellarg(__FILE__) . ' 2>&1', $out2, $rc2);
foreach ($out2 as $line) {
    say('  ' . $line);
}
say();

if ($rc2 === 0) {
    say('REPAIRED — the database now matches this version of the code.');
    exit(0);
}

say('STILL BEHIND after re-applying the migrations.');
say();
say('That means a migration does not cover one of the columns listed above —');
say('a real gap in TicketsCAD, not something you did wrong. Please open an');
say('issue with the output above:');
say('    https://github.com/openises/TicketsCAD/issues');
exit(1);
