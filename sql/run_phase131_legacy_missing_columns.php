<?php
/**
 * Columns that only a FRESH install ever received.
 *
 * Found 2026-07-31 on the your deployment production install, where the
 * Status page reported SCHEMA BEHIND CODE and `tools/check-schema.php
 * --repair` correctly answered that it could not fix it:
 *
 *     STILL BEHIND after re-applying the migrations.
 *     That means a migration does not cover one of the columns above.
 *
 * It was right. Two columns had a schema file and no way to reach an
 * existing install:
 *
 *   ticket.signal    sql/alter_ticket_add_signal.sql
 *   warnings.radius  sql/alter_warnings_radius.sql
 *
 * Both files are idempotent and both are referenced by
 * tools/install_fresh.php — and by NOTHING ELSE. install_fresh.php runs
 * once, on an empty database. So a brand-new install got both columns
 * and every upgraded install got neither, silently, for over a month:
 * warnings.radius was reported by a beta tester on 2026-06-26 ("Unknown
 * column 'radius'" on the first Save of a warn location) and
 * ticket.signal on 2026-06-27 (the new-incident form's signal select
 * was discarded on create). Both were "fixed" by adding a .sql file
 * that upgrades never executed.
 *
 * This is the documented "feature .sql files must be wired into the
 * install pipeline" pitfall in CLAUDE.md, which cost a week on
 * facilities.bed_auto_mode. Every schema change needs a run_*.php
 * wrapper so BOTH paths converge — a file that only install_fresh.php
 * names is a file that only new installs get.
 *
 * Implemented directly here rather than by executing the .sql files:
 * those use PREPARE/EXECUTE blocks, and this project's .sql importer
 * splits on ';' (see the run_03_location_providers.php note about a
 * semicolon inside a string truncating an INSERT). Re-expressing the
 * guards in PHP avoids handing the splitter something it can mangle.
 *
 * Idempotent. Safe to re-run. Verifies its own outcome and exits
 * non-zero if a column it was asked to add is still absent — a
 * migration that reports success it did not achieve is how the RBAC
 * one-time migration went unnoticed for months.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 131 — columns only a fresh install received\n";
echo "=================================================\n\n";

/**
 * Columns to ensure. `after` is a hint only: if that anchor column is
 * absent on this install (schemas vary by age), the column is appended
 * instead of failing the whole migration on a cosmetic detail.
 */
$wanted = [
    [
        'table'  => 'ticket',
        'column' => 'signal',
        'ddl'    => 'VARCHAR(8) NULL',
        'after'  => 'nine_one_one',
        'why'    => 'new-incident signal select had nowhere to store its value',
    ],
    [
        'table'  => 'warnings',
        'column' => 'radius',
        'ddl'    => 'INT DEFAULT 500',
        'after'  => 'lng',
        'why'    => 'warn-location save INSERTs radius; without it the first Save fails',
    ],
];

function p131_col_exists(string $table, string $column): bool
{
    $row = db_fetch_one(
        "SELECT COUNT(*) AS n
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
            AND COLUMN_NAME  = ?",
        [$table, $column]
    );
    return ((int) ($row['n'] ?? 0)) > 0;
}

function p131_table_exists(string $table): bool
{
    $row = db_fetch_one(
        "SELECT COUNT(*) AS n
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?",
        [$table]
    );
    return ((int) ($row['n'] ?? 0)) > 0;
}

/**
 * Whole TABLES in the same position: a schema file that only
 * tools/install_fresh.php names. `owntracks_outbox` was lifted out of a
 * lazy CREATE inside api/owntracks-config.php on 2026-06-26 precisely so
 * it would exist before an admin first pushed config — and then it was
 * wired to the fresh-install path only, so upgraded installs kept the
 * bug it was written to fix.
 *
 * Each file here must contain exactly ONE statement, guarded with
 * IF NOT EXISTS, so it can be handed to the driver whole. Do not add a
 * multi-statement file to this list.
 */
$wantedTables = [
    [
        'table' => 'owntracks_outbox',
        'file'  => __DIR__ . '/owntracks_outbox.sql',
        'why'   => 'OwnTracks Diagnostics SELECTs it; without it the panel errors',
    ],
];

$added   = 0;
$present = 0;
$skipped = 0;
$failed  = [];

foreach ($wantedTables as $t) {
    $table = $prefix . $t['table'];

    if (p131_table_exists($table)) {
        echo "  [ok]   {$table} already present\n";
        $present++;
        continue;
    }

    if (!is_readable($t['file'])) {
        echo "  [FAIL] {$table} — schema file missing: {$t['file']}\n";
        $failed[] = $table;
        continue;
    }

    $sql = (string) file_get_contents($t['file']);

    // Guard the one-statement contract rather than trusting the comment
    // above: this project has been bitten by a naive ';' splitter before.
    $stripped = preg_replace('/^\s*--.*$/m', '', $sql);
    if (substr_count((string) $stripped, ';') > 1) {
        echo "  [FAIL] {$table} — schema file has more than one statement; "
            . "it needs its own migration\n";
        $failed[] = $table;
        continue;
    }

    try {
        db_query(rtrim(trim((string) $stripped), ';'));
        echo "  [add]  {$table}  ({$t['why']})\n";
        $added++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$table} — " . $e->getMessage() . "\n";
        $failed[] = $table;
    }
}

foreach ($wanted as $w) {
    $table  = $prefix . $w['table'];
    $column = $w['column'];
    $label  = "{$table}.{$column}";

    if (!p131_table_exists($table)) {
        // Not every install has every optional table. Nothing to widen,
        // and creating it here is another migration's job.
        echo "  [skip] {$label} — table not present on this install\n";
        $skipped++;
        continue;
    }

    if (p131_col_exists($table, $column)) {
        echo "  [ok]   {$label} already present\n";
        $present++;
        continue;
    }

    $after = '';
    if ($w['after'] !== '' && p131_col_exists($table, $w['after'])) {
        $after = ' AFTER `' . $w['after'] . '`';
    }

    // Identifiers are literals from $wanted above, never user input;
    // information_schema lookups are parameterised.
    $sql = "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$w['ddl']}{$after}";

    try {
        db_query($sql);
        echo "  [add]  {$label}  ({$w['why']})\n";
        $added++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$label} — " . $e->getMessage() . "\n";
        $failed[] = $label;
    }
}

// Verify the OUTCOME rather than trusting that the statements ran.
echo "\nVerifying…\n";
$stillMissing = [];
foreach ($wantedTables as $t) {
    if (!p131_table_exists($prefix . $t['table'])) {
        $stillMissing[] = $prefix . $t['table'];
    }
}
foreach ($wanted as $w) {
    $table = $prefix . $w['table'];
    if (!p131_table_exists($table)) {
        continue;
    }
    if (!p131_col_exists($table, $w['column'])) {
        $stillMissing[] = "{$table}.{$w['column']}";
    }
}

echo "\nSummary: {$added} added, {$present} already present, {$skipped} skipped.\n";

if ($stillMissing || $failed) {
    echo "\nSTILL MISSING after this migration: "
        . implode(', ', $stillMissing ?: $failed) . "\n";
    echo "This migration did not achieve what it claims. Do not treat it as applied.\n";
    exit(1);
}

echo "Schema for these columns matches what the code writes.\n";
exit(0);
