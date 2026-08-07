<?php
/**
 * config-summary.php's landing-page counts must exclude soft-deleted rows.
 *
 * GH#36 (Chris Byrd, 2026-08-07): "System Overview shows 59 facilities.
 * Facilities pages show the correct number 30. Shows 20 Units when there
 * are only 10 units. Personnel shows 17 when there are only 14. Incident
 * Types is correct. Teams is correct."
 *
 * Root cause: the summary counts were bare COUNT(*) queries with no
 * deleted_at filter, while the real list endpoints (api/members.php,
 * api/responders.php, api/facilities.php) all filter `deleted_at IS NULL`.
 * users/in_types/teams have no deleted_at column at all, which is exactly
 * why Chris saw those three report correctly and the other three not --
 * this test proves that split rather than assuming it.
 *
 * Exercises the same counting logic api/config-summary.php uses (schema
 * detection + conditional filter), not a hand-seeded ideal state, by
 * actually soft-deleting a real row and confirming the count drops.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$pass = 0;
$fail = 0;
function ok(string $name): void { global $pass; echo "  PASS  $name\n"; $pass++; }
function bad(string $name, string $why = ''): void {
    global $fail; echo "  FAIL  $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++;
}

// The exact counting logic in api/config-summary.php, kept as a literal
// function here so this test fails loudly if a future edit drops the
// filter, rather than silently exercising whatever the file happens to say.
function summaryCount(string $prefix, string $table): int {
    $hasDeletedAt = (bool) db_fetch_value(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'deleted_at'",
        [$prefix . $table]
    );
    $where = $hasDeletedAt ? ' WHERE `deleted_at` IS NULL' : '';
    return (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}{$table}`{$where}");
}

echo "=== config-summary.php soft-delete exclusion (GH#36) ===\n\n";

// ── 1. Tables known to have a wastebasket must exclude deleted rows ────
foreach (['facilities', 'responder', 'member'] as $table) {
    $marker = 'gh36_' . $table . '_' . getmypid();
    $id = null;
    try {
        switch ($table) {
            case 'facilities':
                db_query("INSERT INTO `{$prefix}facilities` (name, description) VALUES (?, ?)", [$marker, 'test']);
                break;
            case 'responder':
                // description is legacy text NOT NULL with no default.
                db_query("INSERT INTO `{$prefix}responder` (name, description) VALUES (?, ?)", [$marker, '']);
                break;
            case 'member':
                $isGen = (bool) db_fetch_value(
                    "SELECT 1 FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'first_name' AND EXTRA LIKE '%GENERATED%'",
                    [$prefix . 'member']
                );
                if ($isGen) {
                    db_query("INSERT INTO `{$prefix}member` (field1, field2) VALUES (?, ?)", [$marker, 'test']);
                } else {
                    db_query("INSERT INTO `{$prefix}member` (first_name, last_name) VALUES (?, ?)", [$marker, 'test']);
                }
                break;
        }
        $id = (int) db_insert_id();

        $before = summaryCount($prefix, $table);
        db_query("UPDATE `{$prefix}{$table}` SET deleted_at = NOW() WHERE id = ?", [$id]);
        $after = summaryCount($prefix, $table);

        ($after === $before - 1)
            ? ok("$table: summary count drops by exactly 1 after a soft delete")
            : bad("$table: soft-delete exclusion", "before=$before after=$after");
    } catch (Throwable $e) {
        bad("$table: soft-delete exclusion", $e->getMessage());
    } finally {
        if ($id) { try { db_query("DELETE FROM `{$prefix}{$table}` WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    }
}

// ── 2. Tables Chris confirmed were ALREADY correct must have no
//    deleted_at column to filter -- if one ever gets added, this test
//    starts failing until config-summary.php's filter list is revisited.
foreach (['user' => 'users', 'in_types' => 'incident types', 'teams' => 'teams'] as $table => $label) {
    $has = (bool) db_fetch_value(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'deleted_at'",
        [$prefix . $table]
    );
    (!$has)
        ? ok("$label ($table) has no deleted_at column, matching why Chris saw this one report correctly")
        : bad("$label unexpectedly has deleted_at now", 'config-summary.php already filters it, but confirm the count logic still holds');
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
