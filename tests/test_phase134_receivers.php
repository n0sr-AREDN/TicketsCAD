<?php
/**
 * Phase 134 (2026-08) — Inbound routing Model 3 (GH #23), Step 2 ONLY:
 * the real _telegram_receive()/_slack_receive() implementations. See
 * specs/phase-134-inbound-routing-model3/{spec.md,plan.md} §3. Steps 3-5
 * (the responder->open-assignment->ticket join, the poller, and
 * router_evaluate() wiring) are separate, later work and have no tests
 * here.
 *
 * ── WHY THIS FILE TESTS PURE FUNCTIONS, NOT curl-DRIVEN ONES ───────────
 *
 * _telegram_receive() and _slack_receive() both make a real HTTP call.
 * This codebase has no curl-mocking convention: grepping tests/*.php for
 * how existing curl-based adapters (_telegram_send(), _slack_send(),
 * _dmr_send(), etc.) are tested shows exactly two patterns —
 *
 *   1. Not tested at all except live (most sends).
 *   2. Driven through a child PHP process with db_fetch_value()/
 *      broker_register() stubbed, exercised ONLY along code paths that
 *      return before curl_exec() is ever reached (see
 *      tests/test_telegram_channel_security.php group A/F — the security
 *      gate proves fail-closed behaviour this same way).
 *
 * Neither pattern lets a test drive the actual RESPONSE-PARSING logic
 * (chat-id filtering, offset advancement, bot-message filtering, cursor
 * advancement) without truly contacting Telegram/Slack. So both adapters
 * split that logic into pure functions with no curl, no database, and no
 * globals — _telegram_parse_updates() and _slack_parse_messages() (plus
 * _slack_should_resolve_channel() for the channel-name cache) — which
 * this file drives directly with hand-built fake API-response arrays.
 * _telegram_receive()/_slack_receive() themselves remain thin wrappers
 * (fetch, then delegate) and are NOT called here — doing so would require
 * either a live network call (forbidden) or mocking curl (no convention
 * exists, and inventing one is out of scope for this step).
 *
 * The persistence helpers (_telegram_set_update_offset(),
 * _slack_set_last_ts(), _slack_set_resolved_channel()) ARE exercised
 * directly against the real `settings` table, because they contain no
 * network call — only a database write — and per GH #79 the write/read
 * round-trip through the correct store is exactly what must be proven,
 * not inferred from reading the code. Every settings row this file
 * touches is restored to its pre-test value in a register_shutdown_
 * function (test_phase132_writer.php's pattern), so a fatal mid-run
 * cannot leave the shared dev DB poisoned for a later test file.
 *
 * Usage: php tests/test_phase134_receivers.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/sse.php';    // broker_send()'s local_chat path needs sse_publish() defined
require_once __DIR__ . '/../inc/broker.php'; // auto-loads inc/channels/*.php (telegram.php, slack.php) + router.php

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  PASS: $m\n"; }
function bad(string $m, string $hint = ''): void {
    global $fail; $fail++; echo "  FAIL: $m" . ($hint !== '' ? " — $hint" : '') . "\n";
}
function chk($cond, string $m, string $hint = ''): void { $cond ? ok($m) : bad($m, $hint); }

echo "\n=== Phase 134 — Inbound routing Model 3 receivers (Step 2) ===\n";

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 1. broker_register(): pollable + dedupe_key capability flags --\n";
// ─────────────────────────────────────────────────────────────────────────
// Read directly from the registry broker.php populates, rather than
// re-deriving from source text — this is the actual state the future
// poller (Step 4) will read.
global $_broker_channels;

chk(isset($_broker_channels['telegram']),
    'telegram is registered in the broker');
chk(($_broker_channels['telegram']['pollable'] ?? null) === true,
    "telegram's registration declares pollable => true",
    var_export($_broker_channels['telegram']['pollable'] ?? null, true));
chk(($_broker_channels['telegram']['dedupe_key'] ?? null) === 'update_id',
    "telegram's registration declares dedupe_key => 'update_id'",
    var_export($_broker_channels['telegram']['dedupe_key'] ?? null, true));

chk(isset($_broker_channels['slack']),
    'slack is registered in the broker');
chk(($_broker_channels['slack']['pollable'] ?? null) === true,
    "slack's registration declares pollable => true",
    var_export($_broker_channels['slack']['pollable'] ?? null, true));
chk(($_broker_channels['slack']['dedupe_key'] ?? null) === 'ts',
    "slack's registration declares dedupe_key => 'ts'",
    var_export($_broker_channels['slack']['dedupe_key'] ?? null, true));

// local_chat (and any other non-Phase-134 channel) must NOT have picked up
// pollable by accident — proves the flag is additive, not a default.
if (isset($_broker_channels['local_chat'])) {
    chk(($_broker_channels['local_chat']['pollable'] ?? null) !== true,
        'local_chat is NOT pollable (structurally excluded from any future poller — no allowlist needed)');
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. _telegram_parse_updates(): chat-id filter + offset advancement --\n";
// ─────────────────────────────────────────────────────────────────────────
$configuredChatId = '-100123456789';

$telegramFixture = [
    'ok' => true,
    'result' => [
        [
            'update_id' => 5001,
            'message' => [
                'message_id' => 1,
                'from' => ['id' => 111, 'username' => 'alice'],
                'chat' => ['id' => -100123456789, 'type' => 'group'],
                'date' => 1700000000,
                'text' => 'hello from alice',
            ],
        ],
        [
            // Unrelated chat — must be excluded from messages but still
            // counted toward the offset advance.
            'update_id' => 5002,
            'message' => [
                'message_id' => 2,
                'from' => ['id' => 222, 'username' => 'mallory'],
                'chat' => ['id' => -999999999999, 'type' => 'group'],
                'date' => 1700000001,
                'text' => 'traffic from a totally different group',
            ],
        ],
        [
            'update_id' => 5003,
            'message' => [
                'message_id' => 3,
                // No username — sender must fall back to numeric id.
                'from' => ['id' => 333],
                'chat' => ['id' => -100123456789, 'type' => 'group'],
                'date' => 1700000002,
                'text' => 'hello from a user with no username',
            ],
        ],
    ],
];

[$msgs, $newOffset] = _telegram_parse_updates($telegramFixture, $configuredChatId, 999);

chk(count($msgs) === 2,
    'only the 2 updates matching the configured chat id are returned as messages',
    'got ' . count($msgs) . ': ' . var_export($msgs, true));

chk($newOffset === 5004,
    'the new offset advances past ALL 3 update_ids (max=5003) — including the unrelated chat — not just the 2 matches',
    "got {$newOffset}, expected 5004");

chk(($msgs[0]['from'] ?? null) === 'alice',
    "message shape's 'from' is populated with the sender's Telegram username",
    var_export($msgs[0]['from'] ?? null, true));

chk(($msgs[1]['from'] ?? null) === '333',
    "message shape's 'from' falls back to the numeric user id when no username is present",
    var_export($msgs[1]['from'] ?? null, true));

chk(($msgs[0]['body'] ?? null) === 'hello from alice', "message body is the update's text");
chk(($msgs[0]['update_id'] ?? null) === 5001,
    "the message carries its own update_id (the declared dedupe_key field)");
chk(($msgs[0]['to'] ?? null) === $configuredChatId, "message 'to' is the matched chat id");

// No updates at all -> offset is returned UNCHANGED, not reset to 0/1.
[$emptyMsgs, $unchangedOffset] = _telegram_parse_updates(['ok' => true, 'result' => []], $configuredChatId, 4242);
chk($emptyMsgs === [], 'an empty result array yields no messages');
chk($unchangedOffset === 4242, 'an empty result array leaves the offset unchanged (nothing to advance past)',
    "got {$unchangedOffset}");

// Every update is unrelated-chat traffic -> zero messages, but the offset
// STILL advances past all of them (the exact "don't get pinned" case).
$allUnrelated = [
    'ok' => true,
    'result' => [
        ['update_id' => 7001, 'message' => ['from' => ['id' => 1], 'chat' => ['id' => -1], 'text' => 'x']],
        ['update_id' => 7002, 'message' => ['from' => ['id' => 2], 'chat' => ['id' => -1], 'text' => 'y']],
    ],
];
[$noneMsgs, $advancedPastAll] = _telegram_parse_updates($allUnrelated, $configuredChatId, 1);
chk($noneMsgs === [], 'all-unrelated-chat updates produce zero messages');
chk($advancedPastAll === 7003,
    'the offset still advances past unrelated-only traffic (max update_id + 1) — proves an unrelated chat can never pin the cursor',
    "got {$advancedPastAll}");

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. _slack_parse_messages(): bot/subtype filter + cursor advancement --\n";
// ─────────────────────────────────────────────────────────────────────────
$slackFixture = [
    'ok' => true,
    'messages' => [
        ['type' => 'message', 'user' => 'U100', 'text' => 'real user message one', 'ts' => '1700000000.000100'],
        // The bot's own post — must never be returned as an inbound message.
        ['type' => 'message', 'bot_id' => 'B999', 'text' => 'posted by our own bot', 'ts' => '1700000001.000200'],
        // A system subtype (channel_join) — also must be filtered.
        ['type' => 'message', 'subtype' => 'channel_join', 'user' => 'U200', 'text' => '<@U200> has joined the channel', 'ts' => '1700000002.000300'],
        ['type' => 'message', 'user' => 'U300', 'text' => 'real user message two', 'ts' => '1700000003.000400'],
    ],
];

[$slackMsgs, $newLastTs] = _slack_parse_messages($slackFixture, 'C0123456789');

chk(count($slackMsgs) === 2,
    'only the 2 real user messages are returned (bot_id post and channel_join subtype excluded)',
    'got ' . count($slackMsgs) . ': ' . var_export($slackMsgs, true));

chk(($slackMsgs[0]['from'] ?? null) === 'U100' && ($slackMsgs[1]['from'] ?? null) === 'U300',
    'the two surviving messages are from U100 and U300, in order',
    var_export(array_column($slackMsgs, 'from'), true));

chk($newLastTs === '1700000003.000400',
    'the cursor advances to the newest ts in the RAW response (the last message), even though a filtered '
    . 'bot/subtype message could otherwise have been newer — proving the max is computed before filtering',
    "got " . var_export($newLastTs, true));

chk(($slackMsgs[0]['to'] ?? null) === 'C0123456789', "message 'to' is the resolved channel id passed in");
chk(($slackMsgs[0]['ts'] ?? null) === '1700000000.000100', "message carries its own ts (the declared dedupe_key field)");

// The bot's message is strictly NEWER (by ts) than both surviving user
// messages that follow it — proving cursor advancement uses the newest ts
// across the WHOLE raw response, not just "the last surviving message".
$botIsNewest = [
    'ok' => true,
    'messages' => [
        ['type' => 'message', 'user' => 'U100', 'text' => 'first', 'ts' => '1700000000.000000'],
        ['type' => 'message', 'bot_id' => 'B999', 'text' => 'bot chatter, but LATEST ts', 'ts' => '1700000999.999999'],
    ],
];
[$msgsB, $lastTsB] = _slack_parse_messages($botIsNewest, 'C0123456789');
chk(count($msgsB) === 1, 'only the one real user message survives when the bot post is newest');
chk($lastTsB === '1700000999.999999',
    "the cursor still advances to the bot's ts (the newest in the raw response) so the bot's own chatter can never pin it",
    "got " . var_export($lastTsB, true));

// No messages at all -> newLastTs is null (nothing to advance past), not '0'.
[$emptySlack, $nullTs] = _slack_parse_messages(['ok' => true, 'messages' => []], 'C0123456789');
chk($emptySlack === [], 'an empty messages array yields no messages');
chk($nullTs === null, 'an empty messages array leaves the cursor null (caller must not overwrite the stored one)');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. _slack_should_resolve_channel(): the resolver's short-circuit decision --\n";
// ─────────────────────────────────────────────────────────────────────────
chk(_slack_should_resolve_channel('', null, null) === false,
    'an empty configured channel never triggers a resolution attempt');

chk(_slack_should_resolve_channel('C0123456789', null, null) === false,
    'a channel already shaped like an ID never triggers a resolution attempt (no cache needed at all)');
chk(_slack_should_resolve_channel('G0123456789', 'stale', 'stale-for') === false,
    'a G-prefixed (private/MPIM) ID shape also short-circuits regardless of any stale cache present');

chk(_slack_should_resolve_channel('#general', null, null) === true,
    'a channel NAME with no cache at all must resolve');
chk(_slack_should_resolve_channel('#general', '', '') === true,
    'a channel NAME with an empty-string cache must resolve (not just null)');

chk(_slack_should_resolve_channel('#general', 'C0999999999', '#general') === false,
    'a channel NAME with a cache recorded for the SAME configured name reuses the cache — no resolution');

chk(_slack_should_resolve_channel('#general', 'C0999999999', '#other-channel') === true,
    'a channel NAME with a cache recorded for a DIFFERENT configured name must re-resolve '
    . '(an admin changed the Settings field since the cache was written)');

// Now the integration-level proof: _slack_resolve_channel_id() itself, in
// the two scenarios that must NEVER touch curl (already-an-ID, and a
// matching cache) — driven for real, not just the decision function.
$resolvedFromId = _slack_resolve_channel_id(['slack_channel' => 'C0123456789']);
chk($resolvedFromId === 'C0123456789',
    '_slack_resolve_channel_id() returns an already-ID-shaped channel unchanged, with no token needed '
    . 'and (by construction — see _slack_should_resolve_channel above) no conversations.list call attempted',
    var_export($resolvedFromId, true));

$resolvedFromCache = _slack_resolve_channel_id([
    'slack_channel' => '#ops-general',
    'slack_resolved_channel_id' => 'C0777777777',
    'slack_resolved_channel_for' => '#ops-general',
    'slack_token' => 'xoxb-fake-token-never-used',
]);
chk($resolvedFromCache === 'C0777777777',
    '_slack_resolve_channel_id() reuses a matching cached id without attempting conversations.list '
    . '(a bogus token is supplied above — if a network call were attempted this would fail or hang, not '
    . 'return the cached value)',
    var_export($resolvedFromCache, true));

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 5. Persisted cursors/cache round-trip through the REAL `settings` table --\n";
// ─────────────────────────────────────────────────────────────────────────
$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}

if (!$haveDb) {
    echo "SKIP: no database available for section 5 — settings round-trip needs one\n";
} else {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $restoreKeys = [
        'telegram_update_offset',
        'slack_last_ts',
        'slack_resolved_channel_id',
        'slack_resolved_channel_for',
    ];
    // db_fetch_value()'s underlying PDO::fetchColumn() returns FALSE for
    // "no row" — indistinguishable from a legitimately empty string/NULL
    // value if used here. db_fetch_one() returns a real null for "no row"
    // and an array (whose 'value' may itself be null) when the row exists,
    // so existence and value are captured as two separate, unambiguous
    // facts. (Caught in review of this very file: an earlier draft used
    // db_fetch_value() + `=== null`, which is never true for a missing
    // row, so cleanup always took the "restore this value" branch and
    // left behind a real settings row with value='' for a key that had
    // never existed before the test ran.)
    $originalExists = [];
    $originalValues = [];
    foreach ($restoreKeys as $k) {
        try {
            $row = db_fetch_one("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", [$k]);
        } catch (Throwable $e) {
            $row = null;
        }
        $originalExists[$k] = ($row !== null);
        $originalValues[$k] = $row['value'] ?? null;
    }

    // Restored no matter how this file exits (fatal, early exit, or a
    // normal finish) — mirrors tests/test_phase132_writer.php's pattern so
    // this shared dev DB is never left holding test-only cursor values.
    register_shutdown_function(function () use ($prefix, $restoreKeys, $originalExists, $originalValues) {
        foreach ($restoreKeys as $k) {
            try {
                if (!$originalExists[$k]) {
                    db_query("DELETE FROM `{$prefix}settings` WHERE `name` = ?", [$k]);
                } else {
                    db_query(
                        "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                        [$k, $originalValues[$k]]
                    );
                }
            } catch (Throwable $e) { /* best effort — this is the last line of defense */ }
        }
    });

    _telegram_set_update_offset(4242424242);
    $tgConfig = _telegram_get_config();
    chk(((string) ($tgConfig['telegram_update_offset'] ?? null)) === '4242424242',
        '_telegram_set_update_offset() writes to the `settings` table and _telegram_get_config() reads it back',
        var_export($tgConfig['telegram_update_offset'] ?? null, true));

    // Write again with a different value — proves the ON DUPLICATE KEY
    // UPDATE path (a second run of a real poller tick), not just a first
    // INSERT, actually updates the value.
    _telegram_set_update_offset(5353535353);
    $tgConfig2 = _telegram_get_config();
    chk(((string) ($tgConfig2['telegram_update_offset'] ?? null)) === '5353535353',
        'a second call to _telegram_set_update_offset() updates the existing row (ON DUPLICATE KEY UPDATE), not error/duplicate');

    _slack_set_last_ts('1700000123.000456');
    $slConfig = _slack_get_config();
    chk(($slConfig['slack_last_ts'] ?? null) === '1700000123.000456',
        '_slack_set_last_ts() writes to the `settings` table and _slack_get_config() reads it back',
        var_export($slConfig['slack_last_ts'] ?? null, true));

    _slack_set_resolved_channel('C0888888888', '#phase134-test-channel');
    $slConfig2 = _slack_get_config();
    chk(($slConfig2['slack_resolved_channel_id'] ?? null) === 'C0888888888',
        '_slack_set_resolved_channel() persists the resolved id');
    chk(($slConfig2['slack_resolved_channel_for'] ?? null) === '#phase134-test-channel',
        '_slack_set_resolved_channel() persists which configured name it was resolved for '
        . '(so a later Settings change invalidates the cache — see section 4)');

    // And the resolver itself now picks the cache back up end-to-end.
    $endToEnd = _slack_resolve_channel_id([
        'slack_channel' => '#phase134-test-channel',
        'slack_resolved_channel_id' => $slConfig2['slack_resolved_channel_id'],
        'slack_resolved_channel_for' => $slConfig2['slack_resolved_channel_for'],
        'slack_token' => 'xoxb-fake-token-never-used',
    ]);
    chk($endToEnd === 'C0888888888',
        'the just-persisted cache is honoured by _slack_resolve_channel_id() on the next call, end to end');
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 6. broker_channel_statuses() regression — unaffected by the new registration keys --\n";
// ─────────────────────────────────────────────────────────────────────────
// broker_channel_statuses() only reads handler['name'] and handler['status']
// (verified directly against inc/broker.php before writing this assertion —
// not assumed) — adding 'pollable'/'dedupe_key' to the registration array
// must not have disturbed it.
$statuses = broker_channel_statuses();
$byCode = [];
foreach ($statuses as $s) {
    if (isset($s['code'])) $byCode[$s['code']] = $s;
}

chk(isset($byCode['telegram']), 'telegram still appears in broker_channel_statuses()');
if (isset($byCode['telegram'])) {
    chk(array_key_exists('name', $byCode['telegram'])
        && array_key_exists('enabled', $byCode['telegram'])
        && array_key_exists('status', $byCode['telegram']),
        "telegram's status row still carries code/name/enabled/status",
        var_export($byCode['telegram'], true));
    chk($byCode['telegram']['name'] === 'Telegram', "telegram's display name is unaffected");
}

chk(isset($byCode['slack']), 'slack still appears in broker_channel_statuses()');
if (isset($byCode['slack'])) {
    chk(array_key_exists('name', $byCode['slack'])
        && array_key_exists('enabled', $byCode['slack'])
        && array_key_exists('status', $byCode['slack']),
        "slack's status row still carries code/name/enabled/status",
        var_export($byCode['slack'], true));
    chk($byCode['slack']['name'] === 'Slack', "slack's display name is unaffected");
}

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
