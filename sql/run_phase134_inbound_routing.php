<?php
/**
 * Phase 134 — Inbound routing to the sender's assigned incident (Model 3,
 * GH #23) — Step 1 ONLY: dedupe table + comm_modes seed (telegram, slack).
 *
 * specs/phase-134-inbound-routing-model3/{spec.md,plan.md}. This script does
 * exactly plan.md §9 step 1 — "Dedupe table + comm_modes seed +
 * comm_resolve.php reverse-map entries + migration. Independently testable,
 * no poller yet." — and nothing from steps 2-5 (the real _telegram_receive()/
 * _slack_receive() implementations, the responder->open-assignment->ticket
 * join, the poller itself, or router_evaluate() wiring). Those are separate,
 * later work.
 *
 * WHAT THIS CREATES:
 *   - `inbound_message_dedupe` table: `channel` + `external_id`, UNIQUE
 *     KEY on the pair, `seen_at`. Empty at install time — populated at
 *     runtime by the (not-yet-built) poller via INSERT IGNORE before
 *     logging/routing any inbound message, per plan.md §2. This is the
 *     keystone that lets a poller advance its cursor eagerly right after a
 *     successful fetch without risking duplicate ingestion on a crash-and-
 *     retry.
 *   - Two `comm_modes` rows — `telegram` and `slack` — shaped exactly like
 *     the existing `zello`/`traccar` rows (fields_json defines the per-
 *     member form fields; the existing Roster -> member -> Comm/Location
 *     IDs UI already renders any comm_mode row generically, so no UI code
 *     is needed here). See plan.md §0 + §5.
 *
 * FIELD KEY <-> REVERSE-MAP ALIGNMENT (read this before touching either
 * side): `inc/comm_resolve.php`'s `comm_resolve_member_by_address()` gets
 * two new `$reverseKeys` entries in the same change as this script:
 *
 *   comm_modes.code   values_json field key   reverse-map key
 *   ---------------   ---------------------   ---------------
 *   telegram          username                 'telegram' => 'username'
 *   slack             user_id                  'slack'    => 'user_id'
 *
 * The seeded fields_json's `key` for each mode MUST match the reverse-map's
 * value character-for-character, or resolution silently returns null
 * forever. This exact class of bug (schema-mismatch: a key the writer uses
 * that the reader never matches) is CLAUDE.md's most-repeated failure
 * pattern in this project — tests/test_phase134_migration.php asserts the
 * alignment by parsing the seeded fields_json and comparing the literal
 * key, and separately proves resolution end-to-end through a real
 * member_comm_identifiers row, not just that the two constants happen to
 * read the same in source.
 *
 * capabilities = '2T' for both (not '1T'): unlike APRS's short fixed-length
 * beacon text ('1T', one-way/short-form), Telegram and Slack are ordinary
 * back-and-forth chat with essentially unbounded message length — the same
 * judgment call that gave Meshtastic/DMR/MeshCore '2T'. This is a cosmetic
 * classification field only (drives badge/legend display), not load-bearing
 * for resolution or routing.
 *
 * sort_order 110 / 120 — after the highest existing seeded row (`radio` at
 * 99, confirmed live via `SELECT code, sort_order FROM comm_modes ORDER BY
 * sort_order`), so the two new rows sort last without disturbing any
 * existing row's position.
 *
 * UNIQUENESS — unlike Phase 132's `ticket_disposition` (org_id NULLable,
 * so a naive UNIQUE(org_id, code) would have constrained nothing per Phase
 * 129's NULL-in-unique-index lesson), `comm_modes.code` is a genuine
 * `varchar(32) NOT NULL UNIQUE` column — verified directly via `SHOW CREATE
 * TABLE comm_modes` before writing this script, not assumed from a .sql
 * file that may have drifted from the live schema. INSERT IGNORE would
 * therefore actually be safe here. This script still does an explicit
 * existence-check before each INSERT (same shape as Phase 132's disposition
 * seed) for consistent idempotent-run logging and because Phase 129's
 * discipline is "ask the database" — which is exactly what the SHOW CREATE
 * TABLE check above did, and what tests/test_phase134_migration.php
 * verifies again by running this script twice and asking the database
 * whether the row count changed.
 *
 * `inbound_message_dedupe`'s own UNIQUE KEY (channel, external_id) has no
 * such NULLable trap either — both columns are NOT NULL, so every row
 * genuinely participates in the constraint. Still verified by the real
 * writer (INSERT then a duplicate INSERT IGNORE), never by reading the DDL
 * alone — tests/test_phase134_migration.php inserts the same pair twice
 * through the real table and asserts the second is silently ignored.
 *
 * Idempotent — safe to re-run. VERIFIES ITS OWN OUTCOME (CLAUDE.md, Phase
 * 128 A9: a migration step that catches its own exception and exits 0 is a
 * step that never ran) — the last thing this does is re-ask the database
 * whether the table and both comm_modes rows actually exist, with the
 * expected field keys, and exits non-zero if not.
 *
 * Usage: php sql/run_phase134_inbound_routing.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

$prefix      = $GLOBALS['db_prefix'] ?? '';
$dedupeTable = $prefix . 'inbound_message_dedupe';
$modesTable  = $prefix . 'comm_modes';
$fail        = [];

echo "Phase 134 — Inbound Routing Model 3 (Step 1: dedupe table + comm_modes seed)\n";
echo "==============================================================================\n\n";

// ─────────────────────────────────────────────────────────────────────────
// 1. inbound_message_dedupe — shape per plan.md §2 / spec.md "In scope"
// ─────────────────────────────────────────────────────────────────────────
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$dedupeTable}` (
        `id`          INT AUTO_INCREMENT PRIMARY KEY,
        `channel`     VARCHAR(64)  NOT NULL,
        `external_id` VARCHAR(255) NOT NULL,
        `seen_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_channel_external_id` (`channel`, `external_id`),
        KEY `idx_seen_at` (`seen_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] table `{$dedupeTable}` present\n";
} catch (Exception $e) {
    $fail[] = 'create table inbound_message_dedupe: ' . $e->getMessage();
    echo "[FAIL] create table inbound_message_dedupe: " . $e->getMessage() . "\n";
}

// ─────────────────────────────────────────────────────────────────────────
// 2. Seed comm_modes: telegram, slack
// ─────────────────────────────────────────────────────────────────────────
// fields_json shape mirrors the existing zello/traccar rows. The `key` of
// the required field on each MUST match inc/comm_resolve.php's
// $reverseKeys entry for that transport — see the docblock table above.
$modes = [
    [
        'code'         => 'telegram',
        'name'         => 'Telegram',
        'icon'         => 'telegram',
        'color'        => '#26A5E4',
        'capabilities' => '2T',
        'lookup_url'   => null,
        'sort_order'   => 110,
        'notes'        => 'Telegram — inbound group/chat messages route to the sender\'s open '
            . 'assignment when their Telegram username is on file (Phase 134, Model 3).',
        'fields_json'  => json_encode([
            [
                'key'         => 'username',
                'label'       => 'Telegram Username',
                'type'        => 'text',
                'placeholder' => 'N0NKI_dispatch',
                'maxlength'   => 64,
                'required'    => true,
                'hint'        => 'The member\'s Telegram @username (without the @), NOT their numeric '
                    . 'user id or display name — this is what inbound-message sender resolution '
                    . 'matches against.',
            ],
        ]),
    ],
    [
        'code'         => 'slack',
        'name'         => 'Slack',
        'icon'         => 'slack',
        'color'        => '#4A154B',
        'capabilities' => '2T',
        'lookup_url'   => null,
        'sort_order'   => 120,
        'notes'        => 'Slack — inbound channel messages route to the sender\'s open assignment '
            . 'when their Slack member id is on file (Phase 134, Model 3).',
        'fields_json'  => json_encode([
            [
                'key'         => 'user_id',
                'label'       => 'Slack Member ID',
                'type'        => 'text',
                'placeholder' => 'U0123456',
                'maxlength'   => 32,
                'required'    => true,
                'hint'        => 'Slack\'s stable member id (starts with U or W — find it via a '
                    . 'member\'s profile "Copy member ID"), NOT their display name. Display names '
                    . 'change; this id does not, which is why resolution keys off it.',
            ],
            [
                'key'         => 'display_name',
                'label'       => 'Display Name (reference only)',
                'type'        => 'text',
                'placeholder' => '',
                'maxlength'   => 64,
                'required'    => false,
                'hint'        => 'Optional — for the dispatcher\'s own reference. Not used for resolution.',
            ],
        ]),
    ],
];

foreach ($modes as $m) {
    try {
        $already = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$modesTable}` WHERE `code` = ?", [$m['code']]);
        if ($already === 0) {
            db_query(
                "INSERT INTO `{$modesTable}`
                    (`code`, `name`, `icon`, `color`, `fields_json`, `capabilities`, `lookup_url`, `enabled`, `sort_order`, `notes`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)",
                [$m['code'], $m['name'], $m['icon'], $m['color'], $m['fields_json'],
                 $m['capabilities'], $m['lookup_url'], $m['sort_order'], $m['notes']]
            );
            echo "  [+] comm_mode seeded: {$m['code']} ({$m['name']})\n";
        } else {
            echo "  [skip] comm_mode exists: {$m['code']}\n";
        }
    } catch (Exception $e) {
        $fail[] = "seed comm_mode {$m['code']}: " . $e->getMessage();
        echo "  [FAIL] seed comm_mode {$m['code']}: " . $e->getMessage() . "\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────
// 3. Verify the OUTCOME — not that the script ran, but that it worked
// ─────────────────────────────────────────────────────────────────────────
try {
    $tableThere = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$dedupeTable]);
    if ($tableThere === 0) $fail[] = "verify: table `{$dedupeTable}` does not exist";

    $uniqueKeyThere = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            AND INDEX_NAME = 'uk_channel_external_id' AND NON_UNIQUE = 0",
        [$dedupeTable]);
    if ($uniqueKeyThere === 0) $fail[] = "verify: uk_channel_external_id unique index does not exist on `{$dedupeTable}`";

    $expectedFieldKeys = ['telegram' => 'username', 'slack' => 'user_id'];
    foreach ($expectedFieldKeys as $code => $expectedKey) {
        $row = db_fetch_one("SELECT `fields_json`, `capabilities`, `sort_order` FROM `{$modesTable}` WHERE `code` = ?", [$code]);
        if ($row === null) {
            $fail[] = "verify: comm_modes row '{$code}' does not exist";
            continue;
        }
        $fields = json_decode($row['fields_json'] ?? '[]', true);
        $keys = is_array($fields) ? array_column($fields, 'key') : [];
        if (!in_array($expectedKey, $keys, true)) {
            $fail[] = "verify: comm_modes '{$code}' fields_json does not declare key '{$expectedKey}' "
                . "(found: " . implode(',', $keys) . ") — this MUST match inc/comm_resolve.php's reverse map";
        }
    }
} catch (Exception $e) {
    $fail[] = 'verify: ' . $e->getMessage();
}

if ($fail) {
    echo "\nFAILED:\n  - " . implode("\n  - ", $fail) . "\n";
    exit(1);   // non-zero, so sql/run_migrations.php records a real failure
}

echo "\nDone. Inbound routing Model 3 (Step 1: dedupe table + comm_modes seed) installed.\n";
exit(0);
