<?php
/**
 * Phase 134 (2026-08, GH #23 Model 3) — Step 4 ONLY: the poller, the
 * dedup wiring in broker_receive(), the per-channel backoff, and the
 * operator-visible on/off switch's required-check
 * (sched_job_required('channel_receive_tick')).
 *
 * specs/phase-134-inbound-routing-model3/{spec.md,plan.md} §1-4, §8-9.
 * Steps 1-3 (dedupe table + comm_modes seed, the real _telegram_receive()/
 * _slack_receive() implementations, the responder->open-assignment->
 * ticket join) are covered by tests/test_phase134_migration.php,
 * tests/test_phase134_receivers.php and
 * tests/test_phase134_assigned_incidents.php respectively — not repeated
 * here. Step 5 (wiring mi_attach_message_to_assigned_incidents() into
 * router_evaluate()) is not built yet and has no tests here.
 *
 * ── WHY SOME SECTIONS DRIVE A SUBPROCESS ────────────────────────────────
 *
 * get_variable() caches every `settings` row in a function-static array on
 * its FIRST call and never re-reads the table for the rest of the process
 * (inc/functions.php). channel_receive_run() reads `{channel}_poll_inbound`
 * through get_variable() (per the standing GH #79 rule), and
 * sched_job_required('channel_receive_tick') does the same for every
 * pollable channel it enumerates. A test that writes one of those settings
 * and then calls either function again IN THIS PROCESS would only prove
 * its own stale cache, not the real cross-process behaviour a fresh
 * `php tools/channel_receive_tick.php` tick actually sees. Every scenario
 * that depends on `{channel}_poll_inbound` therefore runs through
 * tests/_p134_channel_receive_probe.php — a fresh interpreter per call,
 * matching the established tests/_par_setting_probe.php /
 * tests/_p132_settings_probe.php pattern for exactly this problem.
 *
 * Backoff state (`{channel}_poll_fail_count` / `{channel}_poll_backoff_
 * until`) is deliberately NOT read via get_variable() in production code
 * (inc/channel-receive.php uses direct db_query() reads instead, for
 * exactly this reason) — so the backoff scenarios below run IN this
 * process and just re-query the `settings` table directly to verify the
 * persisted state between probe calls.
 *
 * Every synthetic channel/setting this file creates uses a `p134`-prefixed
 * code (never telegram/slack, never a live HTTP call) so cleanup can be a
 * blanket `LIKE 'p134%'` sweep, run from a register_shutdown_function so a
 * fatal mid-run cannot leave the shared dev DB poisoned for a later test
 * file (tests/test_phase132_writer.php's pattern).
 *
 * Usage: php tests/test_phase134_poller.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/sse.php';    // broker_send()'s local_chat path needs sse_publish() defined
require_once __DIR__ . '/../inc/channel-receive.php'; // pulls in inc/broker.php

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  PASS: $m\n"; }
function bad(string $m, string $hint = ''): void {
    global $fail; $fail++; echo "  FAIL: $m" . ($hint !== '' ? " — $hint" : '') . "\n";
}
function chk($cond, string $m, string $hint = ''): void { $cond ? ok($m) : bad($m, $hint); }

echo "\n=== Phase 134 — Inbound routing Model 3 poller (Step 4) ===\n";

$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) {
    echo "\nSKIP: no database available — this test needs one\n";
    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

$prefix = $GLOBALS['db_prefix'] ?? '';
$php    = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
$probe  = __DIR__ . '/_p134_channel_receive_probe.php';

function p134_inbound_count(string $channel): int {
    global $prefix;
    return (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}messages` WHERE channel = ? AND direction = 'inbound'",
        [$channel]
    );
}

/**
 * Run the probe as a subprocess; returns the decoded JSON (or null on parse
 * failure).
 *
 * MUST NOT merge stderr into the captured output (a bare `shell_exec($cmd .
 * ' 2>&1')` does exactly that). inc/push.php calls error_log() — which PHP's
 * CLI SAPI writes to stderr by default — whenever vendor/autoload.php is
 * absent, and this project's own CI never runs `composer install` (see
 * .github/workflows/qa.yml's comment on the composer-audit step: "this job
 * never runs composer install, so vendor/ does not exist here"). The probe
 * requires inc/channel-receive.php, which pulls in inc/broker.php, which
 * autoloads every inc/channels/*.php including push.php — so on a
 * genuinely fresh CI install that error_log() line fires on EVERY probe
 * invocation and, merged into stdout, corrupts every json_decode() call
 * here. It never reproduced locally because this working tree happens to
 * have vendor/ installed from earlier work, so the warning never fires —
 * exactly the kind of "passes locally, fails on CI's fresh install" gap
 * this project's CI exists to catch (see the sort_order lesson one commit
 * before this one). proc_open() with separate stdout/stderr pipes, same
 * technique as tests/test_telegram_channel_security.php's child-process
 * harness, keeps the two streams apart so a warning on stderr can never
 * corrupt a JSON parse of stdout again.
 */
function p134_run_probe(string $php, string $probe, array $args): ?array {
    $cmd = [$php, $probe, ...$args];
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) return null;
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $decoded = json_decode((string) $stdout, true);
    return is_array($decoded) ? $decoded : null;
}

/** Direct (uncached) read of a settings row, or null if absent. */
function p134_setting(string $name) {
    global $prefix;
    $row = db_fetch_one("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", [$name]);
    return $row === null ? null : $row['value'];
}

function p134_set_setting(string $name, string $value): void {
    global $prefix;
    db_query(
        "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$name, $value]
    );
}

// Cleanup, no matter how this file exits — every synthetic setting/message
// row this file creates is p134-prefixed.
register_shutdown_function(function () use ($prefix) {
    try { db_query("DELETE FROM `{$prefix}settings` WHERE `name` LIKE 'p134%'"); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}messages` WHERE `channel` LIKE 'p134%'"); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}inbound_message_dedupe` WHERE `channel` LIKE 'p134%'"); } catch (Throwable $e) {}
});

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 1. Dedup lives in broker_receive() — a declared dedupe_key channel --\n";
// ─────────────────────────────────────────────────────────────────────────
$dedupACalls = 0;
broker_register('p134_dedup_a', [
    'name'       => 'P134 Dedup A',
    'send'       => null,
    'receive'    => function ($limit = 50) use (&$dedupACalls) {
        $dedupACalls++;
        if ($dedupACalls === 1) {
            return [
                ['from' => 'unitA', 'body' => 'msg1', 'ext_id' => 'p134dedupA-1'],
                ['from' => 'unitA', 'body' => 'msg2', 'ext_id' => 'p134dedupA-2'],
            ];
        }
        // Second poll: the SAME ext_id-2 message is re-delivered (the
        // exact "cursor advanced eagerly, then a crash-and-retry" scenario
        // plan.md §2 exists to make safe) alongside one genuinely new one.
        return [
            ['from' => 'unitA', 'body' => 'msg2-redelivered', 'ext_id' => 'p134dedupA-2'],
            ['from' => 'unitA', 'body' => 'msg3', 'ext_id' => 'p134dedupA-3'],
        ];
    },
    'status'     => null,
    'pollable'   => true,
    'dedupe_key' => 'ext_id',
]);

$beforeA = p134_inbound_count('p134_dedup_a');
$call1A = broker_receive('p134_dedup_a');
chk(count($call1A) === 2, 'first poll: both messages come through (nothing seen yet)',
    'got ' . count($call1A));
$afterCall1A = p134_inbound_count('p134_dedup_a');
chk(($afterCall1A - $beforeA) === 2, 'first poll: exactly 2 rows logged to `messages`',
    "before={$beforeA} after={$afterCall1A}");

$call2A = broker_receive('p134_dedup_a');
chk(count($call2A) === 1,
    'second poll: the RE-DELIVERED duplicate (ext_id -2) is silently absorbed — only the genuinely new message comes through',
    'got ' . count($call2A) . ': ' . var_export($call2A, true));
chk(($call2A[0]['body'] ?? null) === 'msg3',
    'the one message that survives the second poll is the genuinely new one (msg3), not the duplicate');
$afterCall2A = p134_inbound_count('p134_dedup_a');
chk(($afterCall2A - $afterCall1A) === 1,
    'second poll: exactly 1 NEW row logged — the duplicate produced NO log row (not logged once "extra", not logged twice)',
    "afterCall1={$afterCall1A} afterCall2={$afterCall2A}");

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. A channel with NO dedupe_key is completely unaffected --\n";
// ─────────────────────────────────────────────────────────────────────────
broker_register('p134_dedup_b', [
    'name'     => 'P134 Dedup B',
    'send'     => null,
    'receive'  => function ($limit = 50) {
        // Same content both times, on purpose — proves dedup is opt-in per
        // channel (plan.md §2), not a blanket new behaviour that would
        // otherwise absorb these as "duplicates" by content.
        return [
            ['from' => 'unitB', 'body' => 'same content 1'],
            ['from' => 'unitB', 'body' => 'same content 2'],
        ];
    },
    'status'   => null,
    'pollable' => true,
    // Deliberately NO 'dedupe_key' key at all.
]);

$b1 = broker_receive('p134_dedup_b');
chk(count($b1) === 2, 'no-dedupe-key channel, first poll: both messages come through', 'got ' . count($b1));
$b2 = broker_receive('p134_dedup_b');
chk(count($b2) === 2,
    'no-dedupe-key channel, second poll with IDENTICAL content: BOTH messages come through again — dedup never engaged',
    'got ' . count($b2));
$countB = p134_inbound_count('p134_dedup_b');
chk($countB === 4, 'both polls were logged in full — 4 rows total (2 + 2), none absorbed', "got {$countB}");

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. channel_receive_run(): the opt-in default (unset) skips the channel --\n";
// ─────────────────────────────────────────────────────────────────────────
// Deliberately NOT writing p134_poll_off_poll_inbound at all — the setting
// simply does not exist, which is the real state of every install before
// an operator has ever touched the new checkbox.
$offResult = p134_run_probe($php, $probe, ['p134_poll_off', 'ok']);
chk($offResult !== null, 'probe (off scenario) produced parseable JSON', var_export($offResult, true));
if ($offResult !== null) {
    chk(($offResult['receive_called'] ?? -1) === 0,
        "the channel's receive() callback was NEVER invoked while its poll_inbound setting is unset (default off)",
        var_export($offResult['receive_called'] ?? null, true));
    $skippedDisabled = $offResult['result']['skipped_disabled'] ?? [];
    chk(in_array('p134_poll_off', $skippedDisabled, true),
        "channel_receive_run()'s summary records the channel as skipped_disabled",
        var_export($skippedDisabled, true));
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. channel_receive_run(): turning the setting ON causes polling --\n";
// ─────────────────────────────────────────────────────────────────────────
p134_set_setting('p134_poll_on_poll_inbound', '1');
$onResult = p134_run_probe($php, $probe, ['p134_poll_on', 'ok']);
chk($onResult !== null, 'probe (on scenario) produced parseable JSON', var_export($onResult, true));
if ($onResult !== null) {
    chk(($onResult['receive_called'] ?? -1) === 1,
        "the channel's receive() callback WAS invoked exactly once now that poll_inbound='1'",
        var_export($onResult['receive_called'] ?? null, true));
    chk(($onResult['result']['messages_received'] ?? -1) === 1,
        'channel_receive_run() reports 1 message received');
    $polled = array_column($onResult['result']['channels_polled'] ?? [], 'channel');
    chk(in_array('p134_poll_on', $polled, true),
        'channel_receive_run() records the channel in channels_polled', var_export($polled, true));
    chk(empty($onResult['result']['errors']), 'no errors recorded for a healthy poll',
        var_export($onResult['result']['errors'] ?? null, true));
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 5. Per-channel backoff on repeated failure --\n";
// ─────────────────────────────────────────────────────────────────────────
p134_set_setting('p134_backoff_poll_inbound', '1');

// 5a. First failure: recorded, backoff scheduled into the future.
$fail1 = p134_run_probe($php, $probe, ['p134_backoff', 'throw']);
chk($fail1 !== null, 'probe (first throw) produced parseable JSON', var_export($fail1, true));
if ($fail1 !== null) {
    chk(($fail1['receive_called'] ?? -1) === 1, 'receive() WAS attempted on the first failing poll');
    $errs = $fail1['result']['errors'] ?? [];
    $errChans = array_column($errs, 'channel');
    chk(in_array('p134_backoff', $errChans, true),
        'the failure is recorded in channel_receive_run()\'s errors summary', var_export($errs, true));
    foreach ($errs as $e) {
        if (($e['channel'] ?? '') === 'p134_backoff') {
            chk(($e['fail_count'] ?? 0) === 1, 'the recorded fail_count is 1 after the first failure',
                var_export($e['fail_count'] ?? null, true));
        }
    }
}
$failCountAfter1 = p134_setting('p134_backoff_poll_fail_count');
$backoffUntilAfter1 = p134_setting('p134_backoff_poll_backoff_until');
chk($failCountAfter1 === '1', 'settings table: p134_backoff_poll_fail_count persisted as 1',
    var_export($failCountAfter1, true));
chk($backoffUntilAfter1 !== null && (int) $backoffUntilAfter1 > time(),
    'settings table: p134_backoff_poll_backoff_until persisted as a FUTURE timestamp',
    var_export($backoffUntilAfter1, true));

// 5b. Still inside the backoff window: skipped WITHOUT calling receive(),
// even though this probe call's behavior is 'ok' (proves the skip happens
// before ever attempting the channel, not that the channel would have
// failed again).
$stillBackingOff = p134_run_probe($php, $probe, ['p134_backoff', 'ok']);
chk($stillBackingOff !== null, 'probe (still backing off) produced parseable JSON', var_export($stillBackingOff, true));
if ($stillBackingOff !== null) {
    chk(($stillBackingOff['receive_called'] ?? -1) === 0,
        'receive() is NOT invoked while still inside the backoff window',
        var_export($stillBackingOff['receive_called'] ?? null, true));
    $skippedBackoff = array_column($stillBackingOff['result']['skipped_backoff'] ?? [], 'channel');
    chk(in_array('p134_backoff', $skippedBackoff, true),
        'channel_receive_run() records the channel under skipped_backoff',
        var_export($stillBackingOff['result']['skipped_backoff'] ?? null, true));
}
// Backoff bookkeeping must be UNCHANGED by a skip (no new failure was attempted).
chk(p134_setting('p134_backoff_poll_fail_count') === '1',
    'fail_count is unchanged by a skip — only an ATTEMPTED failure increments it');

// 5c. Backoff window elapsed (simulated by moving backoff_until into the
// past): the channel is tried again.
p134_set_setting('p134_backoff_poll_backoff_until', (string) (time() - 10));
$retried = p134_run_probe($php, $probe, ['p134_backoff', 'ok']);
chk($retried !== null, 'probe (window elapsed) produced parseable JSON', var_export($retried, true));
if ($retried !== null) {
    chk(($retried['receive_called'] ?? -1) === 1,
        'receive() IS invoked again once the backoff window has elapsed',
        var_export($retried['receive_called'] ?? null, true));
    $polled = array_column($retried['result']['channels_polled'] ?? [], 'channel');
    chk(in_array('p134_backoff', $polled, true),
        'the channel is polled again and recorded in channels_polled');
}
chk(p134_setting('p134_backoff_poll_fail_count') === null,
    'a SUCCESSFUL poll clears the fail_count row entirely',
    var_export(p134_setting('p134_backoff_poll_fail_count'), true));
chk(p134_setting('p134_backoff_poll_backoff_until') === null,
    'a SUCCESSFUL poll clears the backoff_until row entirely',
    var_export(p134_setting('p134_backoff_poll_backoff_until'), true));

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 6. sched_job_required('channel_receive_tick') — 'shipped default is not usage' --\n";
// ─────────────────────────────────────────────────────────────────────────
// Uses ITS OWN synthetic pollable channels (never telegram/slack), so this
// section is independent of whatever this install's real Telegram/Slack
// poll_inbound settings happen to be, and independent of channel
// REGISTRATION order (which the 'why' string's wording follows — see
// inc/scheduled-jobs.php's _sched_join_and() comment).
$reqNone = p134_run_probe($php, $probe, ['--sched-required', '']);
chk($reqNone !== null, 'probe (--sched-required, no channels) produced parseable JSON', var_export($reqNone, true));
if ($reqNone !== null) {
    chk(($reqNone['required'] ?? null) === false,
        'with no pollable channel opted in, channel_receive_tick is NOT required — '
        . 'the exact "shipped default is not usage" case a fresh/CI install must read as clean',
        var_export($reqNone, true));
}

p134_set_setting('p134reqA_poll_inbound', '1');
$reqOne = p134_run_probe($php, $probe, ['--sched-required', 'p134reqA']);
chk($reqOne !== null, 'probe (--sched-required, one channel) produced parseable JSON', var_export($reqOne, true));
if ($reqOne !== null) {
    chk(($reqOne['required'] ?? null) === true,
        'with exactly one pollable channel opted in, channel_receive_tick IS required');
    $why = (string) ($reqOne['why'] ?? '');
    chk(stripos($why, 'p134reqA') !== false, "the 'why' string names the enabled channel", $why);
    chk(stripos($why, ' is enabled') !== false, "singular grammar ('is enabled') for exactly one channel", $why);
    chk(stripos($why, ' are enabled') === false, "not the plural form when only one channel is enabled", $why);
}

p134_set_setting('p134reqB_poll_inbound', '1');
$reqTwo = p134_run_probe($php, $probe, ['--sched-required', 'p134reqA,p134reqB']);
chk($reqTwo !== null, 'probe (--sched-required, two channels) produced parseable JSON', var_export($reqTwo, true));
if ($reqTwo !== null) {
    chk(($reqTwo['required'] ?? null) === true, 'with two pollable channels opted in, channel_receive_tick IS required');
    $why2 = (string) ($reqTwo['why'] ?? '');
    chk(stripos($why2, 'p134reqA') !== false && stripos($why2, 'p134reqB') !== false,
        "the 'why' string names BOTH enabled channels", $why2);
    chk(stripos($why2, ' are enabled') !== false, "plural grammar ('are enabled') for two channels", $why2);
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 7. sched_job_registry(): the new entry ships with the expected shape --\n";
// ─────────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../inc/scheduled-jobs.php';
$reg = sched_job_registry();
chk(isset($reg['channel_receive_tick']), "sched_job_registry() carries a 'channel_receive_tick' entry",
    'keys: ' . implode(',', array_keys($reg)));
if (isset($reg['channel_receive_tick'])) {
    $def = $reg['channel_receive_tick'];
    chk(($def['interval_s'] ?? null) === 60, 'interval_s is 60 (same cadence as par_tick/pending_messages_tick)');
    chk(($def['grace_mult'] ?? null) === 15, 'grace_mult is 15 (same as the other two 60s jobs)');
    chk(!empty($def['unit']), 'a unit is declared');
    chk(!empty($def['unit_kind']), 'a unit_kind is declared');
    chk(!empty($def['command']) && strpos($def['command'], 'channel_receive_tick.php') !== false,
        'the command names tools/channel_receive_tick.php', var_export($def['command'] ?? null, true));
    $isWin = sched_is_windows();
    chk(($def['unit_kind'] ?? null) === ($isWin ? 'schtasks' : 'systemd'),
        'unit_kind matches this platform');
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 8. local_chat (and dmr/sms/email) are structurally excluded — tokenized source check --\n";
// ─────────────────────────────────────────────────────────────────────────
/** Strip PHP comments via token_get_all (same technique as tests/test_telegram_channel_security.php). */
function p134_strip_comments(string $src): string {
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) { $out .= "\n"; continue; }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

/** Every T_CONSTANT_ENCAPSED_STRING literal in $src, quotes stripped. */
function p134_string_literals(string $src): array {
    $lits = [];
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING) {
            $raw = $tok[1];
            $lits[] = substr($raw, 1, -1);
        }
    }
    return $lits;
}

$crSource = (string) file_get_contents(__DIR__ . '/../inc/channel-receive.php');
$crLiterals = p134_string_literals($crSource);
chk(!in_array('local_chat', $crLiterals, true),
    "inc/channel-receive.php's source never names 'local_chat' as a string literal — "
    . "channel selection is via the missing 'pollable' flag, not a hardcoded allowlist/denylist",
    'string literals found: ' . implode(', ', array_slice($crLiterals, 0, 40)));
foreach (['telegram', 'slack', 'dmr', 'sms', 'email'] as $code) {
    chk(!in_array($code, $crLiterals, true),
        "inc/channel-receive.php's source never names '{$code}' as a string literal either "
        . '(the selection logic is generic, not a list of known channel codes)');
}

foreach (['local_chat', 'dmr', 'sms', 'email'] as $code) {
    $path = __DIR__ . '/../inc/channels/' . $code . '.php';
    if (!is_file($path)) { bad("inc/channels/{$code}.php exists to check"); continue; }
    $stripped = p134_strip_comments((string) file_get_contents($path));
    chk(!preg_match('/[\'"]pollable[\'"]\s*=>\s*true/', $stripped),
        "inc/channels/{$code}.php's registration array never declares pollable => true "
        . 'outside a comment (tokenized, comment-stripped source check)');
}

// Behavioural confirmation, complementing the source-level checks above —
// this process already has telegram.php/slack.php loaded (via
// inc/channel-receive.php's require of inc/broker.php at file top).
$pollableCodes = array_column(channel_receive_pollable_channels(), 'code');
chk(in_array('telegram', $pollableCodes, true) && in_array('slack', $pollableCodes, true),
    'telegram and slack ARE present in the live pollable-channel registry', implode(',', $pollableCodes));
chk(!in_array('local_chat', $pollableCodes, true),
    'local_chat is NOT present in the live pollable-channel registry', implode(',', $pollableCodes));
chk(!in_array('dmr', $pollableCodes, true) && !in_array('sms', $pollableCodes, true)
    && !in_array('email', $pollableCodes, true),
    'dmr/sms/email are NOT present in the live pollable-channel registry either', implode(',', $pollableCodes));

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
