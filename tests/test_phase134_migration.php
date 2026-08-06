<?php
/**
 * Phase 134 (2026-08-04) — Inbound routing to the sender's assigned
 * incident (Model 3, GH #23), Step 1 ONLY: dedupe table + comm_modes seed
 * (telegram, slack) + comm_resolve.php reverse-map entries. See
 * specs/phase-134-inbound-routing-model3/{spec.md,plan.md}. Steps 2-5 (the
 * real _telegram_receive()/_slack_receive() implementations, the
 * responder->open-assignment->ticket join, the poller, and
 * router_evaluate() wiring) are separate, later work and have no tests
 * here.
 *
 * Drives the REAL migration script (sql/run_phase134_inbound_routing.php)
 * as a subprocess, twice, and asserts the resulting database state — never
 * hand-seeds comm_modes rows directly. This matters for two reasons:
 *
 *   1. Idempotency here IS enforced by a genuine database-level UNIQUE
 *      constraint (comm_modes.code — verified via SHOW CREATE TABLE before
 *      writing the migration, unlike Phase 132's ticket_disposition, whose
 *      org_id NULLability made a naive UNIQUE key constrain nothing per
 *      Phase 129's lesson). The migration script still does an explicit
 *      existence-check before each INSERT. Re-running the real script
 *      twice and asking the database whether the row count changed is what
 *      actually proves idempotency — not reading the CREATE TABLE and
 *      assuming.
 *   2. The single most important assertion in this file is the END-TO-END
 *      resolution check (section 3 below): create a real member, INSERT a
 *      real member_comm_identifiers row keyed exactly as the seeded
 *      fields_json + inc/comm_resolve.php's reverse map expect, then call
 *      the real comm_resolve_member_by_address() and assert it returns
 *      that member's id. This is the assertion that actually proves the
 *      seed-key-vs-reverse-map-key drift class of bug (CLAUDE.md's most-
 *      repeated failure pattern in this project) did NOT happen here — a
 *      test that only compares two string constants in source would pass
 *      even if the seeded fields_json key and the reverse-map key were
 *      spelled differently but happened to be typed the same by hand in
 *      two places. Driving it through the real writer (member_comm_
 *      identifiers) and the real reader (comm_resolve_member_by_address)
 *      is what closes that gap.
 *
 * Usage: php tests/test_phase134_migration.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/comm_resolve.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  PASS: $m\n"; }
function bad(string $m, string $hint = ''): void {
    global $fail; $fail++; echo "  FAIL: $m" . ($hint !== '' ? " — $hint" : '') . "\n";
}
function chk($cond, string $m, string $hint = ''): void { $cond ? ok($m) : bad($m, $hint); }

echo "\n=== Phase 134 — Inbound routing Model 3 migration (Step 1) ===\n";

$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) {
    echo "\nSKIP: no database available — this test needs one\n";
    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

$prefix       = $GLOBALS['db_prefix'] ?? '';
$dedupeTable  = $prefix . 'inbound_message_dedupe';
$modesTable   = $prefix . 'comm_modes';
$identTable   = $prefix . 'member_comm_identifiers';
$memberTable  = $prefix . 'member';

/** Run the real migration script as a subprocess; return [exitCode, output]. */
function p134_run_migration(): array {
    $php    = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $root   = realpath(__DIR__ . '/..');
    $script = $root . '/sql/run_phase134_inbound_routing.php';
    $output = [];
    $rc = 0;
    exec('"' . $php . '" "' . $script . '" 2>&1', $output, $rc);
    return [$rc, implode("\n", $output)];
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 1. The real migration script runs cleanly and IS idempotent --\n";
[$rc1, $out1] = p134_run_migration();
chk($rc1 === 0, 'first run exits 0', substr($out1, 0, 400));

$haveTable = (int) db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?",
    [$dedupeTable]);
if ($haveTable === 0) {
    bad('inbound_message_dedupe table missing after the migration ran — cannot continue',
        substr($out1, 0, 400));
    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit(1);
}

$modesCountAfter1 = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$modesTable}` WHERE `code` IN ('telegram','slack')");
chk($modesCountAfter1 === 2, 'exactly 2 comm_modes rows (telegram, slack) after first run', (string) $modesCountAfter1);

[$rc2, $out2] = p134_run_migration();
chk($rc2 === 0, 'second run (re-run) exits 0', substr($out2, 0, 400));

$modesCountAfter2 = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$modesTable}` WHERE `code` IN ('telegram','slack')");
chk($modesCountAfter2 === $modesCountAfter1,
    "re-running the migration adds no new comm_modes rows (before={$modesCountAfter1}, after={$modesCountAfter2})");

[$rc3, $out3] = p134_run_migration();
$modesCountAfter3 = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$modesTable}` WHERE `code` IN ('telegram','slack')");
chk($rc3 === 0, 'third run also exits 0', substr($out3, 0, 400));
chk($modesCountAfter3 === $modesCountAfter1,
    "a third run still adds nothing (before={$modesCountAfter1}, after={$modesCountAfter3})");

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. inbound_message_dedupe: real UNIQUE(channel, external_id) genuinely rejects a duplicate --\n";
// Ask the database, not the DDL (Phase 129 discipline): insert the same
// (channel, external_id) pair twice through the REAL migration-created
// table and assert the second attempt is rejected/ignored.
$testChannel = 'telegram';
$testExternalId = 'phase134-test-' . bin2hex(random_bytes(6));
$dedupeRowsBefore = 0;
try {
    db_query(
        "INSERT INTO `{$dedupeTable}` (`channel`, `external_id`) VALUES (?, ?)",
        [$testChannel, $testExternalId]
    );
    $dedupeRowsBefore = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$dedupeTable}` WHERE `channel` = ? AND `external_id` = ?",
        [$testChannel, $testExternalId]
    );
    chk($dedupeRowsBefore === 1, 'first INSERT of (channel, external_id) succeeds', (string) $dedupeRowsBefore);

    // Attempt a genuine duplicate INSERT (no IGNORE) — must throw, proving
    // the constraint is real, not merely application-level discipline.
    $threw = false;
    try {
        db_query(
            "INSERT INTO `{$dedupeTable}` (`channel`, `external_id`) VALUES (?, ?)",
            [$testChannel, $testExternalId]
        );
    } catch (Throwable $e) {
        $threw = true;
    }
    chk($threw, 'a genuine duplicate INSERT (no IGNORE) throws — the UNIQUE constraint is real');

    // INSERT IGNORE of the same duplicate must be silently absorbed — row
    // count must not grow.
    db_query(
        "INSERT IGNORE INTO `{$dedupeTable}` (`channel`, `external_id`) VALUES (?, ?)",
        [$testChannel, $testExternalId]
    );
    $dedupeRowsAfter = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$dedupeTable}` WHERE `channel` = ? AND `external_id` = ?",
        [$testChannel, $testExternalId]
    );
    chk($dedupeRowsAfter === 1,
        'INSERT IGNORE of a duplicate (channel, external_id) is silently absorbed — row count unchanged',
        "before={$dedupeRowsBefore} after={$dedupeRowsAfter}");

    // A different external_id on the same channel is a DIFFERENT pair and
    // must be accepted — proves the key is the pair, not just channel.
    $otherExternalId = 'phase134-test-' . bin2hex(random_bytes(6));
    db_query(
        "INSERT INTO `{$dedupeTable}` (`channel`, `external_id`) VALUES (?, ?)",
        [$testChannel, $otherExternalId]
    );
    $otherRow = db_fetch_value(
        "SELECT COUNT(*) FROM `{$dedupeTable}` WHERE `channel` = ? AND `external_id` = ?",
        [$testChannel, $otherExternalId]
    );
    chk((int) $otherRow === 1, 'a different external_id on the same channel is accepted as a distinct row');

    db_query("DELETE FROM `{$dedupeTable}` WHERE `channel` = ? AND `external_id` IN (?, ?)",
        [$testChannel, $testExternalId, $otherExternalId]);
} catch (Throwable $e) {
    bad('dedupe uniqueness test threw unexpectedly', $e->getMessage());
    try { db_query("DELETE FROM `{$dedupeTable}` WHERE `channel` = ?", [$testChannel]); } catch (Throwable $e2) {}
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. comm_modes rows: code, fields_json key alignment with the reverse map --\n";
$expectedModes = [
    'telegram' => ['fieldKey' => 'username', 'capabilities' => '2T'],
    'slack'    => ['fieldKey' => 'user_id',  'capabilities' => '2T'],
];
foreach ($expectedModes as $code => $info) {
    $row = db_fetch_one("SELECT * FROM `{$modesTable}` WHERE `code` = ?", [$code]);
    chk($row !== null, "comm_modes row '{$code}' exists");
    if ($row === null) continue;

    chk((int) $row['enabled'] === 1, "'{$code}' is enabled");
    chk($row['capabilities'] === $info['capabilities'],
        "'{$code}' capabilities is '{$info['capabilities']}'", (string) $row['capabilities']);

    $fields = json_decode($row['fields_json'] ?? '[]', true);
    chk(is_array($fields) && !empty($fields), "'{$code}' fields_json parses to a non-empty array");
    $keys = is_array($fields) ? array_column($fields, 'key') : [];
    chk(in_array($info['fieldKey'], $keys, true),
        "'{$code}' fields_json declares the key '{$info['fieldKey']}' that comm_resolve.php's reverse map expects",
        'found keys: ' . implode(',', $keys));

    // The declared field must also be marked required — an optional field
    // for the resolution key would silently produce unresolvable members.
    $fieldDef = null;
    foreach ($fields as $f) { if (($f['key'] ?? '') === $info['fieldKey']) { $fieldDef = $f; break; } }
    chk($fieldDef !== null && !empty($fieldDef['required']),
        "'{$code}' field '{$info['fieldKey']}' is marked required");
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. comm_resolve_member_by_address() resolves telegram + slack END TO END --\n";
// Drive this through the REAL writer (a real member row + a real
// member_comm_identifiers row), never a hand-seeded shortcut — per this
// project's repeated "test asserts against state the real writer never
// produces" failure class.
$memberId = 0;
$identRowIds = [];
try {
    // `member` is a legacy table whose name columns are virtual generated
    // columns over fieldN and can't be written directly — a bare INSERT
    // (all defaults) is the portable way to mint a throwaway row, same
    // pattern as tests/test_meshcore_addressing.php.
    db_query("INSERT INTO `{$memberTable}` () VALUES ()");
    $memberId = (int) db_insert_id();
    chk($memberId > 0, 'throwaway member created', (string) $memberId);

    $telegramModeId = (int) db_fetch_value("SELECT id FROM `{$modesTable}` WHERE code = 'telegram'");
    $slackModeId    = (int) db_fetch_value("SELECT id FROM `{$modesTable}` WHERE code = 'slack'");
    chk($telegramModeId > 0, 'telegram comm_mode id resolved', (string) $telegramModeId);
    chk($slackModeId > 0, 'slack comm_mode id resolved', (string) $slackModeId);

    $telegramHandle = 'PhaseB134Test_' . bin2hex(random_bytes(4));
    $slackUserId    = 'U' . strtoupper(bin2hex(random_bytes(5)));

    db_query(
        "INSERT INTO `{$identTable}` (member_id, comm_mode_id, label, values_json, is_primary, created_at)
         VALUES (?, ?, 'T', ?, 1, NOW())",
        [$memberId, $telegramModeId, json_encode(['username' => $telegramHandle])]
    );
    $identRowIds[] = (int) db_insert_id();

    db_query(
        "INSERT INTO `{$identTable}` (member_id, comm_mode_id, label, values_json, is_primary, created_at)
         VALUES (?, ?, 'T', ?, 1, NOW())",
        [$memberId, $slackModeId, json_encode(['user_id' => $slackUserId, 'display_name' => 'Phase134 Tester'])]
    );
    $identRowIds[] = (int) db_insert_id();

    // ── The load-bearing assertions ──
    $resolvedTelegram = comm_resolve_member_by_address('telegram', $telegramHandle);
    chk($resolvedTelegram === $memberId,
        'comm_resolve_member_by_address(telegram, handle) resolves to the real member id',
        'expected ' . $memberId . ' got ' . var_export($resolvedTelegram, true));

    $resolvedSlack = comm_resolve_member_by_address('slack', $slackUserId);
    chk($resolvedSlack === $memberId,
        'comm_resolve_member_by_address(slack, user_id) resolves to the real member id',
        'expected ' . $memberId . ' got ' . var_export($resolvedSlack, true));

    // Case-insensitivity, matching the existing zello/dmr/etc. behaviour.
    $resolvedTelegramLower = comm_resolve_member_by_address('telegram', strtolower($telegramHandle));
    chk($resolvedTelegramLower === $memberId, 'telegram resolution is case-insensitive');

    $resolvedSlackLower = comm_resolve_member_by_address('slack', strtolower($slackUserId));
    chk($resolvedSlackLower === $memberId, 'slack resolution is case-insensitive');

    // An unknown handle on either transport must resolve to null, not a
    // stray match — proves the lookup is scoped by transport+handle, not
    // just "any identifier exists for this member".
    $unknownTelegram = comm_resolve_member_by_address('telegram', 'nobody_uses_this_handle_' . bin2hex(random_bytes(4)));
    chk($unknownTelegram === null, 'an unknown telegram handle resolves to null');

    $unknownSlack = comm_resolve_member_by_address('slack', 'U' . bin2hex(random_bytes(8)));
    chk($unknownSlack === null, 'an unknown slack user id resolves to null');

    // Cross-transport: the slack user_id string must NOT resolve when
    // queried against telegram (and vice versa) — proves the two new
    // reverse-map entries are genuinely separate, not accidentally aliased.
    $crossResolve = comm_resolve_member_by_address('telegram', $slackUserId);
    chk($crossResolve === null,
        "the slack user_id does not resolve when queried as a telegram handle (no accidental aliasing)");
} finally {
    if ($identRowIds) {
        $in = implode(',', array_fill(0, count($identRowIds), '?'));
        try { db_query("DELETE FROM `{$identTable}` WHERE id IN ($in)", $identRowIds); } catch (Throwable $e) {}
    }
    if ($memberId) {
        try { db_query("DELETE FROM `{$memberTable}` WHERE id = ?", [$memberId]); } catch (Throwable $e) {}
    }
}

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
