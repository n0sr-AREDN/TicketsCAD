<?php
/**
 * Generate sql/schema_manifest.json — the columns this version of the code
 * WRITES to, per table.
 *
 * WHY (Phase 125, 2026-07-26). An install's schema can drift away from what the
 * code needs: a table restored from an older backup, a table dropped by hand
 * during crash recovery, a migration that ran before a column was added. When
 * it does, the failure is a raw PDO exception at save time ("Unknown column
 * 'nims_resource_type'") with nothing that could have predicted it — a beta
 * tester hit exactly this on `teams` and it took four rounds to resolve.
 *
 * This generator produces the manifest that inc/schema-verify.php checks a live
 * database against, so an install can be told what is missing BEFORE someone
 * tries to save.
 *
 * SAFETY PROPERTY — no false alarms by construction. A column is recorded only
 * if BOTH:
 *   (a) the code writes to it (INSERT column list or UPDATE SET target), and
 *   (b) it exists in THIS database, which is a known-good developer install.
 * So a parser artifact cannot become a column the manifest demands of users.
 * tests/test_schema_verify.php regenerates and diffs, so the manifest cannot
 * silently go stale.
 *
 * Usage:
 *   php tools/gen_schema_manifest.php            # write sql/schema_manifest.json
 *   php tools/gen_schema_manifest.php --check    # exit 1 if the file is stale
 */

declare(strict_types=1);

chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/db.php';
require_once __DIR__ . '/sql_extract.php';

$checkOnly = in_array('--check', $argv ?? [], true);
$manifestPath = 'sql/schema_manifest.json';

// ── 1. Live schema of this (known-good) install ──────────────────────────
$live = [];
foreach (db_fetch_all(
    "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()"
) as $r) {
    $live[strtolower($r['TABLE_NAME'])][strtolower($r['COLUMN_NAME'])] = true;
}

// ── 2. Scan the writers ──────────────────────────────────────────────────
$files = [];
foreach (['inc', 'api'] as $dir) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $f) {
        if ($f->isFile() && substr($f->getFilename(), -4) === '.php') {
            $files[] = str_replace('\\', '/', $f->getPathname());
        }
    }
}
sort($files);

$required = [];   // table => [col => [files]]
$skipped  = [];   // table.col => reason (code writes it, this DB lacks it)

foreach ($files as $file) {
    $src = file_get_contents($file);
    if ($src === false) { continue; }
    foreach (sql_extract_strings($src) as [$line, $sql]) {
        if (!sql_extract_is_query($sql)) { continue; }
        foreach (sql_extract_written_columns($sql) as $tbl => $cols) {
            if (!isset($live[$tbl])) { continue; }             // not a real table here
            foreach ($cols as $col) {
                if (!isset($live[$tbl][$col])) {
                    // The code writes a column this known-good DB does not have.
                    // Do NOT put it in the manifest — that would demand it of
                    // every user. Report it: it is either a parser artifact or a
                    // genuine bug for schema_audit to adjudicate.
                    $skipped["$tbl.$col"] = basename($file) . ':' . $line;
                    continue;
                }
                $required[$tbl][$col][] = basename($file) . ':' . $line;
            }
        }
    }
}

ksort($required);
$out = ['tables' => []];
$totalCols = 0;
foreach ($required as $tbl => $cols) {
    ksort($cols);
    $out['tables'][$tbl] = array_keys($cols);
    $totalCols += count($cols);
}
$out['_meta'] = [
    'generated_by' => 'tools/gen_schema_manifest.php',
    'purpose'      => 'columns this code writes to; checked by inc/schema-verify.php',
    'tables'       => count($out['tables']),
    'columns'      => $totalCols,
];

$json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

if ($checkOnly) {
    $existing = @file_get_contents($manifestPath);
    if ($existing === false) {
        echo "MISSING: $manifestPath — run php tools/gen_schema_manifest.php\n";
        exit(1);
    }
    if (trim($existing) !== trim($json)) {
        echo "STALE: $manifestPath does not match the current code.\n";
        echo "Run: php tools/gen_schema_manifest.php\n";
        exit(1);
    }
    echo "OK: $manifestPath is current ({$out['_meta']['tables']} tables, {$totalCols} columns).\n";
    exit(0);
}

file_put_contents($manifestPath, $json);
echo "Wrote $manifestPath\n";
echo "  " . count($files) . " PHP files scanned\n";
echo "  {$out['_meta']['tables']} tables, {$totalCols} required columns\n";

if ($skipped) {
    echo "\n" . count($skipped) . " column(s) written by code but absent from THIS database\n";
    echo "  (excluded from the manifest by design — investigate with tools/schema_audit.php):\n";
    foreach ($skipped as $k => $where) {
        echo "    $k   ($where)\n";
    }
}
