<?php
/**
 * GH#37 (Chris Byrd, 2026-08-08): "I would be glad to export the log but I
 * do not see a function to do that."
 *
 * Confirmed by reading api/audit-log.php before building anything: it was
 * GET/browse-only, no export action existed at all. Added action=export
 * (format=csv|json), reusing the SAME filter-parsing the browse view
 * already had, admin-only (stricter than the action.view_audit permission
 * that gates paginated browsing — matches the precedent api/places.php's
 * own export already set).
 *
 * This test drives the export query as a literal copy of what
 * api/audit-log.php runs (same convention as this project's other API
 * tests — a CLI test can't carry a real PHP session, so the SQL-building
 * and row-shaping logic is what's under test here; the RBAC gate itself is
 * checked by inspecting the source for the exact guard).
 *
 * Usage: php tools/test_gh37_audit_log_export.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/audit.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$pass = 0;
$fail = 0;
function ok(string $name): void { global $pass; echo "  PASS  $name\n"; $pass++; }
function bad(string $name, string $why = ''): void {
    global $fail; echo "  FAIL  $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++;
}

echo "=== GH#37 — Audit Log export (CSV/JSON, filtered, admin-only) ===\n\n";

audit_ensure_table();
$table = db_table('newui_audit_log');

// Literal copy of api/audit-log.php's export query.
function auditExportRows(string $table, array $where, array $params, int $maxRows = 50000): array {
    $whereSQL = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';
    return db_fetch_all(
        "SELECT `id`, `event_time`, `user_id`, `user_name`, `ip_address`,
                `category`, `activity`, `severity`, `target_type`, `target_id`,
                `summary`, `details`
         FROM {$table}
         {$whereSQL}
         ORDER BY `event_time` DESC
         LIMIT ?",
        array_merge($params, [$maxRows])
    );
}

$marker = 'gh37_test_' . getmypid();

// Seed distinguishable rows the way audit_log() actually writes them.
audit_log('gh37cat_a', 'create', 'test', 1, $marker . '_a', ['note' => 'first'], 2);
audit_log('gh37cat_b', 'delete', 'test', 2, $marker . '_b', ['note' => 'second'], 4);

try {
    // ── 1. Export with no filters includes both seeded rows.
    $all = auditExportRows($table, [], []);
    $allSummaries = array_column($all, 'summary');
    $foundA = in_array($marker . '_a', $allSummaries, true);
    $foundB = in_array($marker . '_b', $allSummaries, true);
    ($foundA && $foundB) ? ok('unfiltered export includes both seeded rows') : bad('unfiltered export finds both rows', "a=$foundA b=$foundB");

    // ── 2. Category filter — the exact filter the browse view supports —
    //      narrows the export the same way it narrows the list.
    $filtered = auditExportRows($table, ['`category` = ?'], ['gh37cat_a']);
    $filteredSummaries = array_column($filtered, 'summary');
    (in_array($marker . '_a', $filteredSummaries, true) && !in_array($marker . '_b', $filteredSummaries, true))
        ? ok('category filter narrows the export, not just the browse view')
        : bad('category filter should isolate row a', json_encode($filteredSummaries));

    // ── 3. Severity filter (>= threshold) — row b (severity 4) qualifies,
    //      row a (severity 2) does not, at threshold 3.
    $sevFiltered = auditExportRows($table, ['`severity` >= ?'], [3]);
    $sevSummaries = array_column($sevFiltered, 'summary');
    (in_array($marker . '_b', $sevSummaries, true) && !in_array($marker . '_a', $sevSummaries, true))
        ? ok('severity>=3 filter includes the critical-ish row and excludes the low one')
        : bad('severity filter', json_encode($sevSummaries));

    // ── 4. Row cap is honoured (proves the LIMIT param actually lands in
    //      the right place in the parameter list, not silently dropped).
    $capped = auditExportRows($table, [], [], 1);
    (count($capped) === 1) ? ok('the export row cap is enforced (LIMIT parameter binds correctly)')
        : bad('row cap', 'got ' . count($capped) . ' rows with cap=1');

    // ── 5. CSV field shape — the literal column list + fputcsv escaping the
    //      endpoint uses, proving a summary containing a comma and a quote
    //      round-trips through CSV correctly rather than corrupting columns.
    $tricky = ['id' => 99, 'summary' => 'has, a comma and "quotes"', 'details' => ['x' => 1]];
    $cols = ['id', 'summary', 'details'];
    $fh = fopen('php://memory', 'r+');
    $line = [];
    foreach ($cols as $c) {
        $v = $tricky[$c] ?? '';
        $line[] = is_array($v) ? json_encode($v) : $v;
    }
    fputcsv($fh, $line, ',', '"', '');
    rewind($fh);
    $parsedBack = fgetcsv($fh, 0, ',', '"', '');
    fclose($fh);
    ($parsedBack && $parsedBack[1] === 'has, a comma and "quotes"')
        ? ok('a summary with commas and quotes round-trips through the CSV escaping intact')
        : bad('CSV round-trip', json_encode($parsedBack));
    ($parsedBack && $parsedBack[2] === '{"x":1}')
        ? ok('a details array is flattened to one JSON-string CSV column, not exploded into extra columns')
        : bad('details column shape', json_encode($parsedBack[2] ?? null));

    // ── 6. RBAC: export must be gated STRICTER than plain browsing —
    //      admin-only, not the action.view_audit permission alone.
    $src = file_get_contents(__DIR__ . '/../api/audit-log.php');
    (strpos($src, "\$action === 'export' && !is_admin()") !== false)
        ? ok('export is gated on is_admin() specifically, not merely action.view_audit')
        : bad('export RBAC gate', 'expected an explicit is_admin() check on the export branch');
    (strpos($src, "!is_admin() && !rbac_can('action.view_audit')") !== false)
        ? ok('plain browsing still accepts either admin or action.view_audit (unchanged)')
        : bad('browse RBAC gate should be unchanged');
} finally {
    db_query("DELETE FROM {$table} WHERE summary IN (?, ?)", [$marker . '_a', $marker . '_b']);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
