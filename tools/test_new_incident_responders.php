<?php
/**
 * New Incident form's responder-assignment list must exclude soft-deleted
 * responders, same as the facilities list right above it in the same file.
 *
 * GH#40 (Chris Byrd, 2026-08-07): "All assign responders are duplicated" on
 * the New Incident screen. api/incident-types.php's responders query
 * filtered the legacy `hide` flag but never `deleted_at`, unlike its own
 * facilities query a few lines above. A responder soft-deleted and
 * re-added under the same name (a common cleanup step) showed up
 * alongside its replacement -- reading as a duplicate. Kept as a literal
 * query string here (not an include of the API file, which requires an
 * authenticated session) so this test fails loudly if a future edit drops
 * the filter, rather than silently exercising whatever the file says.
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

echo "=== New Incident responder list — soft-delete exclusion (GH#40) ===\n\n";

function fetchAssignableResponders(string $prefix): array {
    $hasHide = (bool) db_fetch_value(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'hide'",
        [$prefix . 'responder']
    );
    $hasDeletedAt = (bool) db_fetch_value(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'deleted_at'",
        [$prefix . 'responder']
    );
    $filters = [];
    if ($hasHide) $filters[] = '(`r`.`hide` = 0 OR `r`.`hide` IS NULL)';
    if ($hasDeletedAt) $filters[] = '`r`.`deleted_at` IS NULL';
    $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';

    return db_fetch_all(
        "SELECT `r`.`id`, `r`.`name`, `r`.`handle`, `r`.`type`,
                `s`.`description` AS `status`,
                (SELECT COUNT(*) FROM `{$prefix}assigns` `a`
                 WHERE `a`.`responder_id` = `r`.`id` AND `a`.`clear` IS NULL) AS `active_assignments`
         FROM `{$prefix}responder` `r`
         LEFT JOIN `{$prefix}un_status` `s` ON `r`.`un_status_id` = `s`.`id`
         {$where}
         ORDER BY `r`.`name`"
    );
}

$marker = 'gh40_' . getmypid();
$id = null;
try {
    // description is legacy text NOT NULL with no default.
    db_query("INSERT INTO `{$prefix}responder` (name, description) VALUES (?, ?)", [$marker, '']);
    $id = (int) db_insert_id();

    $before = fetchAssignableResponders($prefix);
    $foundBefore = false;
    foreach ($before as $r) if ((int) $r['id'] === $id) $foundBefore = true;
    $foundBefore
        ? ok('a live, non-hidden responder appears in the assignable list')
        : bad('live responder should appear', 'not found among ' . count($before) . ' rows');

    db_query("UPDATE `{$prefix}responder` SET deleted_at = NOW() WHERE id = ?", [$id]);
    $after = fetchAssignableResponders($prefix);
    $foundAfter = false;
    foreach ($after as $r) if ((int) $r['id'] === $id) $foundAfter = true;
    (!$foundAfter)
        ? ok('a soft-deleted responder no longer appears — this is the fix for GH#40')
        : bad('soft-deleted responder should be excluded', 'still present after deleted_at was set');

    (count($after) === count($before) - 1)
        ? ok('the list shrinks by exactly one, not zero (proves the filter is actually applied, not coincidental)')
        : bad('list size delta', 'before=' . count($before) . ' after=' . count($after));
} catch (Throwable $e) {
    bad('functional round-trip', $e->getMessage());
} finally {
    if ($id) { try { db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$id]); } catch (Throwable $e) {} }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
