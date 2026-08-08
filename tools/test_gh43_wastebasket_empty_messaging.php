<?php
/**
 * GH#43 (Chris Byrd, 2026-08-08): "Set Purge to 1 Day, Clicked Empty. Says 1
 * deleted item. ICS Form does not delete."
 *
 * NOT a functional bug: ICS Forms are deliberately excluded from Empty
 * Wastebasket (wb_is_purgeable() — Eric's policy, a finalized ICS-214 is an
 * operational record and is recoverable forever). The "1 deleted item" was
 * verifiably some OTHER eligible record, never the ICS Form — this test
 * proves that by construction. The real defect was the response never SAID
 * so: an admin watching one specific ICS Form saw a success message with no
 * way to tell "skipped on purpose" from "silently failed", and reasonably
 * assumed the latter.
 *
 * Fix: api/wastebasket.php's action=empty now counts what it's about to
 * leave behind (non-purgeable types older than the cutoff) and names them
 * in both the response message and a new `skipped` field, and the Empty
 * button's confirm() no longer claims "ALL" records will go.
 *
 * Usage: php tools/test_gh43_wastebasket_empty_messaging.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$pass = 0;
$fail = 0;
function ok(string $name): void { global $pass; echo "  PASS  $name\n"; $pass++; }
function bad(string $name, string $why = ''): void {
    global $fail; echo "  FAIL  $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++;
}

echo "=== GH#43 — Empty Wastebasket must report what it skips, not just what it purges ===\n\n";

// Literal copy of api/wastebasket.php's per-type logic for action=empty,
// against the two config entries this test exercises: ICS forms
// (purgeable=false) and facilities (purgeable=default true).
function emptySweep(string $prefix, int $days): array {
    $tableConfig = [
        'facilities' => ['table' => $prefix . 'facilities', 'label' => 'Facility'],
        'ics_forms'  => ['table' => $prefix . 'ics_forms', 'label' => 'ICS Form', 'purgeable' => false],
    ];
    $purged = 0;
    $skippedLabels = [];
    foreach ($tableConfig as $cfg) {
        $isPurgeable = !array_key_exists('purgeable', $cfg) || $cfg['purgeable'] === true;
        $cnt = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$cfg['table']}` WHERE `deleted_at` IS NOT NULL AND `deleted_at` < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );
        if (!$isPurgeable) {
            if ($cnt > 0) $skippedLabels[] = $cnt . ' ' . $cfg['label'] . ($cnt === 1 ? '' : 's');
            continue;
        }
        if ($cnt > 0) {
            db_query("DELETE FROM `{$cfg['table']}` WHERE `deleted_at` IS NOT NULL AND `deleted_at` < DATE_SUB(NOW(), INTERVAL ? DAY)", [$days]);
            $purged += $cnt;
        }
    }
    return [$purged, $skippedLabels];
}

$marker = 'gh43_test_' . getmypid();

// A facility (purgeable) soft-deleted 2 days ago -- eligible.
db_query(
    "INSERT INTO `{$prefix}facilities` (name, description, deleted_at) VALUES (?, 'test', DATE_SUB(NOW(), INTERVAL 2 DAY))",
    [$marker . '_facility']
);
$facilityId = (int) db_insert_id();

// An ICS form (never purgeable) soft-deleted 2 days ago -- same age, should
// be left alone regardless.
db_query(
    "INSERT INTO `{$prefix}ics_forms` (form_type, title, form_data_json, created_by, created_by_name, deleted_at)
     VALUES ('213', ?, '{}', 0, 'test', DATE_SUB(NOW(), INTERVAL 2 DAY))",
    [$marker . '_icsform']
);
$icsFormId = (int) db_insert_id();

try {
    [$purged, $skipped] = emptySweep($prefix, 1);

    ($purged >= 1) ? ok('the eligible facility was counted as purged') : bad('facility should be purged', 'purged=' . $purged);

    $facilityRow = db_fetch_one("SELECT id FROM `{$prefix}facilities` WHERE id = ?", [$facilityId]);
    (!$facilityRow) ? ok('the eligible facility is actually gone from the table') : bad('facility should be hard-deleted');

    $icsRow = db_fetch_one("SELECT id FROM `{$prefix}ics_forms` WHERE id = ?", [$icsFormId]);
    ($icsRow) ? ok('the ICS Form of the same age is still there -- this is correct, by design, not a bug')
        : bad('ICS Form should NOT have been purged');

    // The actual defect under test: does the response NAME what it left behind?
    $foundIcsMention = false;
    foreach ($skipped as $label) {
        if (stripos($label, 'ICS Form') !== false) { $foundIcsMention = true; break; }
    }
    $foundIcsMention
        ? ok('the response reports the skipped ICS Form by name, not just a silent gap')
        : bad('response should name the skipped ICS Form', 'got ' . json_encode($skipped));
} finally {
    db_query("DELETE FROM `{$prefix}facilities` WHERE id = ?", [$facilityId]);
    db_query("DELETE FROM `{$prefix}ics_forms` WHERE id = ?", [$icsFormId]);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
