<?php
/**
 * A channel adapter must pin its credential-scoped destination to config.
 *
 * The rule, stated once so the next adapter does not have to rediscover it:
 *
 *     Where a channel sends is bound to the CREDENTIAL, not to the message.
 *     A Slack channel id, a Telegram chat id, a DMR talkgroup — these are
 *     properties of the configured integration and must come from config only.
 *     A per-message RECIPIENT (`to`) is a different thing and is fine.
 *
 * Where this came from: an adversarial pre-merge review of the Telegram adapter
 * in openises/TicketsCAD#10 found
 *
 *     $chatId = $message['telegram_chat_id'] ?? $config['telegram_chat_id'] ?? '';
 *
 * and then found the adapter had cloned it faithfully from
 * inc/channels/slack.php, which had carried the same shape since it was
 * written. Fixing only the new one would have left the pattern in place for
 * whoever writes the next adapter.
 *
 * WHY THIS IS GATED WHEN IT IS NOT EXPLOITABLE TODAY
 *
 * It genuinely is not. Every broker_send() call site builds its message from a
 * fixed, hard-coded key list. The single path that forwards a message array
 * wholesale is the routing engine's forward (inc/router.php), and
 * _router_transform() rewrites only body, priority and type — so every other
 * key on a source message survives into the destination adapter verbatim. Two
 * receive handlers return raw third-party JSON into that path
 * (_slack_receive() -> Slack conversations.history objects, _sms_receive() ->
 * Pushbullet threads), and neither provider lets a message author add an
 * arbitrary TOP-LEVEL key. So the override is unreachable — but that safety is
 * a property of someone else's response schema, not of anything this project
 * controls.
 *
 * This project's own history is the argument for gating it anyway.
 * assigns.rec_facility_id was a column "nothing writes", which turned out to be
 * a lost mass-casualty capability. un_status.extra_data_target was an ENUM
 * widened for a value nothing ever set. Both were in exactly this state —
 * correct today, one small change from being wrong — and both cost rounds of
 * "still not working" before anyone looked.
 *
 * What it would cost if it became reachable: every routed message — incident
 * type, dispatch address, patient counts, responder identity and last-known
 * location — delivered to an attacker-chosen destination, silently, with the
 * routing log recording success.
 */

$root = dirname(__DIR__);

$pass = 0;
$fail = 0;

function test(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "[PASS] $name\n";
    } else {
        $fail++;
        echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

$dir = $root . '/inc/channels';
if (!is_dir($dir)) {
    echo "SKIP: inc/channels/ not present\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

$adapters = glob($dir . '/*.php');
test('channel adapters are present', $adapters !== [] && $adapters !== false,
    'found none in ' . $dir);

// Keys a message MAY legitimately carry. `to` is a per-message recipient, which
// is the whole point of a messaging channel; the rest are content, not routing.
$messageOwned = ['to', 'body', 'subject', 'type', 'priority', 'from', 'attachments'];

// ── 1. No adapter lets a message choose a config-scoped destination ─────────
echo "\n-- Destinations come from config, not from the message --\n";

$offences = [];
foreach ($adapters as $file) {
    $src   = file_get_contents($file);
    $lines = preg_split('/\r?\n/', $src);

    foreach ($lines as $i => $line) {
        // Strip comments before matching: several of these files explain the
        // rule by quoting the very pattern it forbids, and a gate that fires on
        // its own documentation gets muted.
        $code = preg_replace('~^\s*(//|\*|/\*).*$~', '', $line);
        if ($code === null || trim($code) === '') {
            continue;
        }

        // $message['x'] ?? $config['x']  — the message overriding configuration
        if (preg_match_all(
                '/\$message\[\s*[\'"]([a-z0-9_]+)[\'"]\s*\]\s*\?\?\s*\$config\[/i',
                $code, $m)) {
            foreach ($m[1] as $key) {
                if (!in_array($key, $messageOwned, true)) {
                    $offences[] = basename($file) . ':' . ($i + 1) . '  $message[\'' . $key
                                . '\'] overrides $config[\'' . $key . '\']';
                }
            }
        }
    }
}

test('no adapter takes a config-scoped destination from the message array',
    $offences === [],
    "\n        " . implode("\n        ", $offences)
    . "\n        A destination bound to the credential must read from \$config only."
    . "\n        If a per-message destination is genuinely wanted, gate it on the"
    . "\n        router's \$message['_is_routed_forward'] marker AND an"
    . "\n        admin-configured allowlist — not an unchecked key.");

// ── 2. Slack specifically — the adapter the pattern came from ───────────────
echo "\n-- Slack --\n";

$slack = $dir . '/slack.php';
if (!is_file($slack)) {
    echo "[SKIP] inc/channels/slack.php not present\n";
} else {
    $src = file_get_contents($slack);
    $code = preg_replace('~^\s*(//|\*|/\*).*$~m', '', $src);

    test('slack_channel is read from config only',
        strpos($code, "\$message['slack_channel']") === false
        && strpos($code, '$message["slack_channel"]') === false);
    test('slack still has a configured default so nothing regresses to silence',
        strpos($src, "\$config['slack_channel']") !== false);
    test('the reasoning is recorded in the file, not only in a commit message',
        stripos($src, 'pinned to configuration') !== false
        || stripos($src, 'bound to the bot credential') !== false
        || stripos($src, 'bound to the credential') !== false);
}

// ── 3. Telegram, when it lands ──────────────────────────────────────────────
//
// The adapter is not in this tree yet (openises/TicketsCAD#10 is unmerged). It
// is checked conditionally rather than left out entirely, so the rule is
// already waiting when the file arrives instead of being remembered.
echo "\n-- Telegram (checked if present) --\n";

$telegram = $dir . '/telegram.php';
if (!is_file($telegram)) {
    echo "[SKIP] inc/channels/telegram.php is not in this tree yet — "
       . "the rule above will apply to it automatically when it lands\n";
} else {
    $code = preg_replace('~^\s*(//|\*|/\*).*$~m', '', file_get_contents($telegram));
    test('telegram_chat_id is read from config only',
        strpos($code, "\$message['telegram_chat_id']") === false
        && strpos($code, '$message["telegram_chat_id"]') === false);
}

// ── 4. The router still only rewrites content, never destination ────────────
//
// The pin above is one half. The other half is that the forwarding path does
// not start writing routing keys onto messages it passes along. If
// _router_transform() ever gained the ability to set arbitrary keys, every
// adapter would be back to trusting its input.
echo "\n-- The forwarding path rewrites content only --\n";

$router = $root . '/inc/router.php';
if (!is_file($router)) {
    echo "[SKIP] inc/router.php not present\n";
} else {
    $src = file_get_contents($router);
    $fnStart = strpos($src, 'function _router_transform');
    $fn = $fnStart === false ? '' : substr($src, $fnStart, 3000);
    test('_router_transform() exists', $fn !== '');
    if ($fn !== '') {
        // It may write body / priority / type. Anything else assigned onto the
        // forwarded message is a new capability that needs thinking about.
        $written = [];
        if (preg_match_all('/\$(?:out|msg|message|m)\[\s*[\'"]([a-z0-9_]+)[\'"]\s*\]\s*=/i', $fn, $mm)) {
            foreach ($mm[1] as $k) {
                if (!in_array($k, ['body', 'priority', 'type'], true)) {
                    $written[] = $k;
                }
            }
        }
        test('_router_transform() writes only body/priority/type onto a forwarded message',
            $written === [],
            'also writes: ' . implode(', ', array_unique($written))
            . ' — if one of those is a destination, the pin above is defeated');
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
