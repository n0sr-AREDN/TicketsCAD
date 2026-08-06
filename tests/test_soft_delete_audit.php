<?php
/**
 * Soft-delete audit regression gate (GH public issue #25 follow-up sweep).
 *
 * Runs tools/soft_delete_audit.php — which finds every SELECT that reads
 * `ticket` without excluding soft-deleted incidents — and fails if any NEW
 * finding (not in tools/soft_delete_audit_exceptions.txt) appears. This is
 * the guard against the class of bug named in issue #25: a read site added
 * later that forgets the `deleted_at` exclusion and silently starts
 * serving (or acting on) soft-deleted incidents again.
 *
 * If this test fails: either add the exclusion term to the new query (the
 * usual case — match the exact idiom already used elsewhere:
 * `(deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')`), or — for a
 * genuinely legitimate case that must still see deleted rows (an
 * incident-number collision check, an audit/history view) — add a line to
 * tools/soft_delete_audit_exceptions.txt with a stated reason.
 *
 * Usage: php tests/test_soft_delete_audit.php
 */
$base = realpath(__DIR__ . '/..');
$php  = PHP_BINARY;

echo "=== Soft-delete audit gate ===\n\n";

exec(escapeshellarg($php) . ' ' . escapeshellarg($base . '/tools/soft_delete_audit.php') . ' 2>&1', $out, $code);
$tail = array_slice($out, -30);
echo implode("\n", $tail) . "\n\n";

if ($code === 0) {
    echo "[PASS] no new soft-delete read-site findings\n";
    echo "\n=== 1 passed, 0 failed ===\n";
    exit(0);
}
echo "[FAIL] soft-delete audit found NEW findings (see above) — a `ticket` read\n"
   . "       site appears to serve/act on soft-deleted incidents. Add the\n"
   . "       exclusion term, or a reasoned exception, before merging.\n";
echo "\n=== 0 passed, 1 failed ===\n";
exit(1);
