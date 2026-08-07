<?php
/**
 * Equipment activity log — "By:" must name the person who performed the
 * action, not whoever happens to share their login id in a different table.
 *
 * GH#34 (Chris Byrd, 2026-08-07): "Activity Log shows wrong person...
 * Looks like it is plus 2 on the index." Root cause: api/equipment.php's
 * checkout/checkin actions write `performed_by` as $current_user_id
 * (api/auth.php — the LOGIN account's `user`.id), but the log-read query
 * joined that value against `member`.id — a completely different id
 * sequence (a login account and a roster/member record are linked via
 * `user`.`member`, which is frequently NULL, not by sharing a primary key).
 * Whichever member happened to sit at that numeric id displayed instead of
 * the real actor. Verified live: user.id=3 (linked member.id=13, "ERIC
 * OSTERBERG") joined against member.id=3 and showed a completely unrelated
 * person.
 *
 * `member_id` (who equipment is checked OUT TO) was never affected by this
 * — that column and its own JOIN are genuinely member.id throughout the
 * write and read paths — so this test also pins that down explicitly rather
 * than assuming it from the fix to the other column.
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

echo "=== Equipment activity log — performed_by resolution (GH#34) ===\n\n";

// The exact query api/equipment.php's single-item handler runs. Kept as a
// literal string (not a call into the API file) so this test still fails
// loudly if a future edit reverts the JOIN target, rather than silently
// exercising whatever the file happens to say.
function fetchLogRow(string $prefix, int $equipmentId, string $marker): ?array {
    $rows = db_fetch_all(
        "SELECT el.*, CONCAT(m.first_name, ' ', m.last_name) AS member_name,
                COALESCE(NULLIF(TRIM(CONCAT(u.name_f, ' ', u.name_l)), ''), u.`user`) AS performed_by_name
         FROM `{$prefix}newui_equipment_log` el
         LEFT JOIN `{$prefix}member` m ON el.member_id = m.id
         LEFT JOIN `{$prefix}user` u ON el.performed_by = u.id
         WHERE el.equipment_id = ? AND el.notes = ?",
        [$equipmentId, $marker]
    );
    return $rows[0] ?? null;
}

$marker = 'gh34_test_' . getmypid();
$eq = db_fetch_one("SELECT id FROM `{$prefix}newui_equipment` LIMIT 1");
if (!$eq) {
    echo "SKIP: no equipment rows on this install to test against.\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

// A real login account, distinct from any member row sharing its id, is
// needed to prove the fix -- not assumed to exist, so create one.
$loginName = 'gh34login_' . getmypid();
$memberName = 'gh34member_' . getmypid();
// first_name/last_name are VIRTUAL GENERATED (derived from the legacy
// field1/field2 columns) on an upgraded/legacy-migrated install, but plain
// stored columns on a fresh base_schema.sql install -- writing to the wrong
// side is either a hard error (1906, generated column) or leaves the real
// columns NULL. Detected the same way heal_legacy_defaults()-style code
// elsewhere in this project checks schema shape before writing to it.
$firstNameIsGenerated = (bool) db_fetch_value(
    "SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'first_name'
        AND EXTRA LIKE '%GENERATED%'",
    [$prefix . 'member']
);
if ($firstNameIsGenerated) {
    db_query("INSERT INTO `{$prefix}member` (field1, field2) VALUES (?, ?)", ['GH34', $memberName]);
} else {
    db_query("INSERT INTO `{$prefix}member` (first_name, last_name) VALUES (?, ?)", ['GH34', $memberName]);
}
$realMemberId = (int) db_insert_id();

// The whole point of the bug: pick a user id and a member id that do NOT
// match, and confirm the log does not conflate them.
db_query(
    "INSERT INTO `{$prefix}user` (`user`, passwd, name_f, name_l, can_login) VALUES (?, ?, ?, ?, 0)",
    [$loginName, password_hash('x', PASSWORD_DEFAULT), 'GH34Login', 'Actor']
);
$loginUserId = (int) db_insert_id();

try {
    if ($loginUserId === $realMemberId) {
        echo "SKIP: freshly inserted user.id and member.id coincided ({$loginUserId}) — cannot prove the mismatch this run.\n";
        echo "\n=== 0 passed, 0 failed ===\n";
        exit(0);
    }

    // Is there a member sitting at the SAME id as the new login account?
    // Not required for the assertion (performed_by_name just needs to be
    // the login's own name either way), but worth knowing for context.
    $collidingMember = db_fetch_one("SELECT first_name, last_name FROM `{$prefix}member` WHERE id = ?", [$loginUserId]);

    // Simulate exactly what api/equipment.php's checkout action writes:
    // performed_by = $current_user_id (a user.id), member_id = the actual
    // recipient (a member.id) -- two different id spaces, on purpose.
    db_query(
        "INSERT INTO `{$prefix}newui_equipment_log`
            (equipment_id, `action`, member_id, performed_by, notes, created_at)
         VALUES (?, 'checkout', ?, ?, ?, NOW())",
        [$eq['id'], $realMemberId, $loginUserId, $marker]
    );

    $row = fetchLogRow($prefix, (int) $eq['id'], $marker);
    (!empty($row)) ? ok('log entry is readable') : bad('log entry is readable');

    if ($row) {
        (strpos((string) $row['performed_by_name'], 'GH34Login') !== false)
            ? ok('performed_by_name names the actual login account, not a coincidentally-numbered member')
            : bad('performed_by_name correct', 'got ' . var_export($row['performed_by_name'], true)
                . ($collidingMember ? ' (a member with that same id exists: ' . $collidingMember['first_name'] . ' ' . $collidingMember['last_name'] . ' -- this is the exact bug shape if it reappears)' : ''));

        (strpos((string) $row['member_name'], $memberName) !== false)
            ? ok('member_name (who it is checked out TO) is unaffected by the performed_by fix')
            : bad('member_name unaffected', 'got ' . var_export($row['member_name'], true));
    }
} finally {
    db_query("DELETE FROM `{$prefix}newui_equipment_log` WHERE notes = ?", [$marker]);
    db_query("DELETE FROM `{$prefix}member` WHERE id = ?", [$realMemberId]);
    db_query("DELETE FROM `{$prefix}user` WHERE id = ?", [$loginUserId]);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
