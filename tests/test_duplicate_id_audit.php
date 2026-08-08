<?php
/**
 * Duplicate DOM id audit regression gate (GH#37 follow-up, 2026-08-08).
 *
 * Runs tools/duplicate_id_audit.php — which finds every literal `id="..."`
 * that appears more than once in settings.php — and fails if any NEW finding
 * (not in tools/duplicate_id_audit_exceptions.txt) appears. This is the guard
 * against the exact bug a QA review caught before release: the new GH#37
 * Audit Log export dropdown reused id="btnAuditExport", already used by the
 * unrelated Roles & Permissions -> Audit Trail export button. Because
 * document.getElementById() silently resolves to whichever element comes
 * first in the DOM, the collision broke one button's click handler and made
 * the other one fire an unrelated CSV download as a side effect — nothing in
 * the test suite could have caught that except reading the rendered HTML.
 *
 * If this test fails: rename one of the colliding ids to something specific
 * to its own panel (the fix here renamed the new dropdown toggle to
 * btnAuditLogExport). This is a same-page collision, not a bug in either
 * feature considered alone — both work fine in isolation, which is exactly
 * why it's easy to miss without this gate.
 *
 * Usage: php tests/test_duplicate_id_audit.php
 */
$base = realpath(__DIR__ . '/..');
$php  = PHP_BINARY;

echo "=== Duplicate DOM id audit gate ===\n\n";

exec(escapeshellarg($php) . ' ' . escapeshellarg($base . '/tools/duplicate_id_audit.php') . ' 2>&1', $out, $code);
$tail = array_slice($out, -30);
echo implode("\n", $tail) . "\n\n";

if ($code === 0) {
    echo "[PASS] no new duplicate DOM id findings\n";
    echo "\n=== 1 passed, 0 failed ===\n";
    exit(0);
}
echo "[FAIL] duplicate DOM id audit found NEW findings (see above) — two elements\n"
   . "       on the same page share an id, which means getElementById() will\n"
   . "       silently wire one feature's click handler to the other's button.\n"
   . "       Rename one of the ids before merging.\n";
echo "\n=== 0 passed, 1 failed ===\n";
exit(1);
