<?php
/**
 * Security gate for the Telegram channel adapter (openises/TicketsCAD PR #10).
 *
 * A channel adapter is a machine that takes incident traffic — addresses,
 * patient counts, responder callsigns and coordinates — and puts it on the
 * public internet. The properties below are what keep it pointed at the
 * operator's own group instead of somebody else's.
 *
 * ── WHY ASSERTION GROUP A EXISTS ─────────────────────────────────────
 *
 * As originally submitted, the adapter resolved its destination like this:
 *
 *     $chatId = $message['telegram_chat_id'] ?? $config['telegram_chat_id'] ?? '';
 *
 * No path could populate that key — every broker_send() call site in the tree
 * builds its message array from a fixed key list, and api/chat.php's
 * test_channel action is admin + CSRF gated and passes exactly five literal
 * keys. So it was not a live vulnerability.
 *
 * It was a live HAZARD, because inc/router.php forwards the ENTIRE source
 * message array to the destination adapter — _router_transform() rewrites
 * body/priority/type and leaves every other key intact — and two receive
 * handlers already return RAW third-party JSON into that path
 * (_slack_receive() returns $data['messages'], _sms_receive() returns
 * $data['threads']). The adapter's safety therefore rested on a third party's
 * object schema, which is not a control this project owns. The day a provider
 * allows an arbitrary top-level key, or somebody adds an ingest endpoint that
 * json_decode()s a request body into a message array, every routed message
 * goes to an attacker-chosen chat and the routing log says "forwarded".
 *
 * This project has been here before. CLAUDE.md documents assigns.rec_facility_id
 * ("a column nothing writes" that was in fact a lost mass-casualty capability)
 * and un_status.extra_data_target (an ENUM widened for a value nothing ever
 * set). "Unreachable today" is exactly the state those were in.
 *
 * telegram_chat_id is not a per-message recipient like `to`. It is the
 * destination BOUND TO THE BOT CREDENTIAL — the same class of value as
 * slack_channel. It belongs in configuration and nowhere else.
 *
 * ── THE PIN IS PROVEN BEHAVIOURALLY, NOT BY READING THE SOURCE ───────
 *
 * An earlier draft of this gate asserted the pin by grepping the adapter, on
 * the reasoning that a behavioural test would need a configured token and
 * would then put real traffic on the wire (the cURL handle initialiser is an
 * internal function and cannot be stubbed). That reasoning was wrong, and a
 * source-level assertion about a security property is the weaker thing this
 * project has repeatedly been bitten by — a test that passes against a state
 * the real code never produces.
 *
 * _telegram_send() checks its guards in a fixed order:
 *
 *     1. token or chat id empty      -> "Telegram not configured"
 *     2. token fails TOKEN_RE        -> "bot token is malformed"
 *     3. chat id fails CHAT_ID_RE    -> "chat ID is malformed"
 *     4. body empty                  -> "Message body required"
 *     5. curl_exec()
 *
 * So drive the REAL _telegram_send() with a well-formed token, a MALFORMED
 * CONFIGURED chat id, a WELL-FORMED override in the message array, and an
 * empty body. Both outcomes stop at a guard and neither reaches curl_exec():
 *
 *     pin holds   -> guard 3 -> "chat ID is malformed"   (config was used)
 *     pin removed -> guard 4 -> "Message body required"  (override was used)
 *
 * The returned error names which value was resolved, no packet leaves the
 * machine, and the assertion is about behaviour rather than about text in a
 * file.
 *
 * ── THE NEGATIVE CONTROL ─────────────────────────────────────────────
 *
 * An assertion that has never been seen to fail is not evidence. Group A
 * therefore also takes the REAL adapter source, substitutes the pinned
 * $chatId assignment back to the vulnerable form, runs the mutant through the
 * same harness, and asserts it reports "Message body required" — i.e. that
 * the caller's chat id won. If that mutant were to fail closed too, the
 * positive assertion above would be passing for some unrelated reason and
 * this gate would be worthless. The mutation itself is verified to have
 * changed the file before the mutant is run, so a regex that silently stopped
 * matching cannot turn the control into a no-op.
 *
 * Both arms run in a child PHP process with broker_register() and
 * db_fetch_value() stubbed, so the two differ in exactly one thing: the pin.
 *
 * Groups B-E cover the rest of what the pre-merge review required (F-3, F-4),
 * the masking contract for the bot token, and the bound on a hanging upstream.
 * None of them touches the database, a web server, or the network — with the
 * single deliberate exception documented at group D.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$file = $root . '/inc/channels/telegram.php';

$tests = 0;
$fails = 0;

function tg(bool $cond, string $label): void
{
    global $tests, $fails;
    $tests++;
    if (!$cond) {
        $fails++;
        echo "FAIL: $label\n";
    }
}

// ── Prerequisite ─────────────────────────────────────────────────────
// A gate that invents a pass on a missing file is worse than no gate.
if (!is_file($file)) {
    echo "SKIP: inc/channels/telegram.php not present — the Telegram adapter "
        . "is not on this tree, so there is no adapter to check (0/0)\n";
    echo "Telegram channel security gate: 0 passed, 0 failed\n";
    exit(0);
}

$src = file_get_contents($file);
if ($src === false || $src === '') {
    echo "FAIL: inc/channels/telegram.php exists but could not be read\n";
    echo "Telegram channel security gate: 0 passed, 1 failed\n";
    exit(1);
}

/** Source with // and /* comments stripped, so a comment can never satisfy an assertion. */
$code = '';
foreach (token_get_all($src) as $tok) {
    if (is_array($tok)) {
        if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
            $code .= "\n";
            continue;
        }
        $code .= $tok[1];
    } else {
        $code .= $tok;
    }
}

// ─────────────────────────────────────────────────────────────────────
// A. THE PIN — behavioural, with a negative control
// ─────────────────────────────────────────────────────────────────────

/**
 * Run the real _telegram_send() from $adapterPath in a child process, with
 * broker_register() and db_fetch_value() stubbed, and return its result array.
 *
 * The child is handed a well-formed token, a malformed CONFIGURED chat id, a
 * well-formed override on the message, and no body. See the header: every
 * path through this returns at a guard, before curl_exec().
 */
function tg_drive_send(string $adapterPath): array
{
    $harness = <<<'PHP'
<?php
// Stubs. The adapter self-registers at include time and reads its config
// through db_fetch_value(); neither needs to be real to resolve a chat id.
function broker_register($name, $spec) { return true; }
function db_fetch_value($sql, $params = []) {
    $key = is_array($params) ? ($params[0] ?? '') : '';
    // A syntactically valid bot token (fake), and a chat id that is NOT.
    // The malformed chat id is the whole point: if the pin holds, the send
    // must fail on it even though the message carries a valid one.
    $fixture = [
        'telegram_bot_token' => '123456789:AAHt-abcdefGHIJKLmnopQRSTuvwx-yz12345',
        'telegram_chat_id'   => 'CONFIG-VALUE-IS-NOT-A-NUMBER',
    ];
    return $fixture[$key] ?? null;
}
require $argv[1];
$result = _telegram_send([
    'body'             => '',                 // empty: guard 4 stops the mutant
    'telegram_chat_id' => '-1009999999999',   // well-formed attacker override
]);
echo json_encode($result);
PHP;

    $harnessFile = tempnam(sys_get_temp_dir(), 'tgh') . '.php';
    file_put_contents($harnessFile, $harness);

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    // Array form: no shell is involved, so no argument can become syntax.
    $proc = proc_open([PHP_BINARY, $harnessFile, $adapterPath], $descriptors, $pipes);
    if (!is_resource($proc)) {
        @unlink($harnessFile);
        return ['__harness_error' => 'could not start the child PHP process'];
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    foreach ($pipes as $p) { fclose($p); }
    proc_close($proc);
    @unlink($harnessFile);

    $decoded = json_decode((string) $out, true);
    if (!is_array($decoded)) {
        return ['__harness_error' => 'child produced no result array: '
            . trim(substr((string) $out . ' ' . (string) $err, 0, 300))];
    }
    return $decoded;
}

$live = tg_drive_send($file);

tg(!isset($live['__harness_error']),
    'the adapter can be driven in isolation'
    . (isset($live['__harness_error']) ? ' — ' . $live['__harness_error'] : ''));

$liveError = (string) ($live['error'] ?? '');

// A1 — the send must fail closed on the CONFIGURED (malformed) chat id, which
//      it can only have done by ignoring the well-formed one on the message.
tg(($live['success'] ?? null) === false && stripos($liveError, 'chat ID is malformed') !== false,
    'a caller-supplied telegram_chat_id cannot override the configured destination: '
    . '_telegram_send() resolved the CONFIGURED (malformed) chat id and failed closed '
    . '— got: ' . ($liveError !== '' ? $liveError : var_export($live, true)));

// A2 — and it must not have got as far as needing a body, which is the guard
//      immediately after the one it should have stopped at.
tg(stripos($liveError, 'Message body required') === false,
    'the send stopped at the chat-id guard, not past it (reaching the body guard '
    . 'would mean the caller-supplied chat id had been accepted as valid)');

// A3 — NEGATIVE CONTROL. Same harness, same inputs, one difference: the pin is
//      removed from a copy of the real source. If this mutant ALSO fails
//      closed, A1 is passing for some reason other than the pin, and this gate
//      is not measuring what it claims to.
$mutantSrc = preg_replace(
    '/\$chatId\s*=\s*[^;]+;/s',
    '$chatId = trim((string) ($message[\'telegram_chat_id\'] ?? $config[\'telegram_chat_id\'] ?? \'\'));',
    $src,
    1,
    $mutationCount
);

tg($mutationCount === 1 && is_string($mutantSrc) && $mutantSrc !== $src,
    'the negative control could re-introduce the $chatId override into a copy of the '
    . 'real source (if this fails the control below is vacuous — the adapter\'s shape '
    . 'changed and this gate needs re-reviewing by hand)');

if ($mutationCount === 1 && is_string($mutantSrc) && $mutantSrc !== $src) {
    $mutantFile = tempnam(sys_get_temp_dir(), 'tgm') . '.php';
    file_put_contents($mutantFile, $mutantSrc);
    $mutant = tg_drive_send($mutantFile);
    @unlink($mutantFile);

    $mutantError = (string) ($mutant['error'] ?? '');
    tg(stripos($mutantError, 'Message body required') !== false,
        'NEGATIVE CONTROL: with the pin removed, the same call resolves the '
        . 'CALLER-supplied chat id and gets past the chat-id guard — proving A1 '
        . 'is detecting the pin and not something incidental '
        . '— got: ' . ($mutantError !== '' ? $mutantError : var_export($mutant, true)));
} else {
    tg(false, 'NEGATIVE CONTROL could not be run (see the mutation assertion above)');
}

// A4 — belt and braces at the source level, so a rename of the key cannot slip
//      past the behavioural check by resolving to the same guard by accident.
if (preg_match('/\$chatId\s*=\s*([^;]+);/s', $code, $m)) {
    tg(strpos($m[1], '$message') === false,
        'the $chatId assignment expression does not reference $message at all');
} else {
    tg(false, 'a $chatId assignment could be located in the adapter (source shape changed — re-review by hand)');
}

// ─────────────────────────────────────────────────────────────────────
// B. NO SSRF SURFACE, AND TLS IS NEVER WEAKENED
// ─────────────────────────────────────────────────────────────────────
// Only $token is interpolated into the URL, and it lands in the PATH, after
// the authority — so it cannot re-point the request at another host. That
// stays true only as long as the host itself is never built from a variable.

tg(strpos($code, "'https://api.telegram.org/bot'") !== false
   || strpos($code, '"https://api.telegram.org/bot"') !== false,
    'the Telegram API base URL is a hard-coded https://api.telegram.org literal');

tg(preg_match('/curl_init\s*\(\s*\$/', $code) !== 1,
    'curl_init() is never handed a bare variable URL (would open an SSRF surface)');

tg(preg_match('/CURLOPT_SSL_VERIFYPEER\s*(,|=>)\s*(false|0)\b/i', $code) !== 1,
    'CURLOPT_SSL_VERIFYPEER is never set to false/0');

tg(preg_match('/CURLOPT_SSL_VERIFYHOST\s*(,|=>)\s*(false|0)\b/i', $code) !== 1,
    'CURLOPT_SSL_VERIFYHOST is never set to false/0');

// Review finding F-3: cURL's defaults are safe, but every other outbound
// caller in this codebase states them (inc/webhooks.php, api/dmr-lookup.php,
// tools/aprs-poller.php). Explicit means a reader can tell "verified safe"
// from "nobody looked", and it survives a host with an unusual curl.* ini.
tg(preg_match('/CURLOPT_SSL_VERIFYPEER\s*(,|=>)\s*true\b/i', $code) === 1,
    'CURLOPT_SSL_VERIFYPEER is explicitly set to true (F-3)');

tg(preg_match('/CURLOPT_SSL_VERIFYHOST\s*(,|=>)\s*2\b/', $code) === 1,
    'CURLOPT_SSL_VERIFYHOST is explicitly set to 2 (F-3)');

tg(preg_match('/CURLOPT_FOLLOWLOCATION\s*(,|=>)\s*(true|1)\b/i', $code) !== 1,
    'CURLOPT_FOLLOWLOCATION is never enabled (F-3)');

// ─────────────────────────────────────────────────────────────────────
// C. CREDENTIALS ARE FORMAT-VALIDATED, AND THE TOKEN NEVER ESCAPES
// ─────────────────────────────────────────────────────────────────────
// Review finding F-4. Fails closed on a pasted-with-whitespace token instead
// of producing an opaque Telegram 404.

tg(preg_match('/preg_match\s*\(.{0,160}?\$token\b/s', $code) === 1,
    'the bot token is format-validated before being placed in the request URL (F-4)');

tg(preg_match('/preg_match\s*\(.{0,160}?\$chatId\b/s', $code) === 1,
    'the chat id is format-validated before being sent (F-4)');

// Driven, not read: a malformed token must stop the send before curl.
$badTokenRun = (static function () use ($file): array {
    $harness = <<<'PHP'
<?php
function broker_register($name, $spec) { return true; }
function db_fetch_value($sql, $params = []) {
    $key = is_array($params) ? ($params[0] ?? '') : '';
    $fixture = [
        'telegram_bot_token' => 'not a bot token',
        'telegram_chat_id'   => '-1001234567890',
    ];
    return $fixture[$key] ?? null;
}
require $argv[1];
echo json_encode(_telegram_send(['body' => 'x']));
PHP;
    $hf = tempnam(sys_get_temp_dir(), 'tgt') . '.php';
    file_put_contents($hf, $harness);
    $d = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = proc_open([PHP_BINARY, $hf, $file], $d, $pipes);
    if (!is_resource($p)) { @unlink($hf); return []; }
    $out = stream_get_contents($pipes[1]);
    foreach ($pipes as $pi) { fclose($pi); }
    proc_close($p);
    @unlink($hf);
    $j = json_decode((string) $out, true);
    return is_array($j) ? $j : [];
})();

tg(($badTokenRun['success'] ?? null) === false
   && stripos((string) ($badTokenRun['error'] ?? ''), 'malformed') !== false,
    'a malformed bot token fails closed with an actionable message, before any '
    . 'request is made — got: ' . var_export($badTokenRun, true));

// The token lives in the URL, so it leaks the moment anything logs the
// effective URL or interpolates $token into a message the admin UI shows.
$tokenLeaks = [];
foreach (preg_split('/\r?\n/', $code) as $i => $line) {
    if (strpos($line, '$token') === false) continue;
    if (preg_match('/\b(error_log|trigger_error|var_dump|print_r|echo)\b/', $line)
        || preg_match("/'error'\s*=>/", $line)) {
        $tokenLeaks[] = ($i + 1) . ': ' . trim($line);
    }
}
tg($tokenLeaks === [],
    'the bot token is never passed to a logger or embedded in a returned error string'
    . ($tokenLeaks ? ' — found: ' . implode(' | ', $tokenLeaks) : ''));

// The masking contract, driven through the REAL classifier that api/config-admin.php
// consults on GET: a secret key is returned to the browser only as `<key>_set`.
require_once $root . '/inc/settings-secrets.php';

tg(function_exists('is_secret_setting_key'),
    'inc/settings-secrets.php provides is_secret_setting_key()');

if (function_exists('is_secret_setting_key')) {
    tg(is_secret_setting_key('telegram_bot_token') === true,
        'telegram_bot_token is classified secret, so GET settings emits '
        . 'telegram_bot_token_set and never the token itself');

    // Without this the assertion above could be true of every string.
    tg(is_secret_setting_key('telegram_chat_id') === false,
        'CONTROL: telegram_chat_id is NOT classified secret (it must round-trip '
        . 'to the form, or the panel cannot show the configured destination)');

    // The blank-means-keep backstop, so saving the panel with the masked token
    // box empty cannot wipe a working credential. See openises/TicketsCAD#7.
    tg(function_exists('is_masked_secret_value') && is_masked_secret_value('') === true,
        'an empty value for a secret key is treated as "not retyped", not as "clear it"');
}

// api/config-admin.php is the endpoint that applies the classifier on GET.
// Assert the secret branch emits the sentinel and not the value.
$adminSrc = (string) @file_get_contents($root . '/api/config-admin.php');
tg(preg_match('/is_secret_setting_key\s*\(\s*\$name\s*\)/', $adminSrc) === 1
   && preg_match('/\$map\[\s*\$name\s*\.\s*[\'"]_set[\'"]\s*\]/', $adminSrc) === 1,
    'api/config-admin.php maps a secret setting to <name>_set on GET rather than '
    . 'returning its value');

// ─────────────────────────────────────────────────────────────────────
// D. A HANGING UPSTREAM CANNOT WEDGE A DISPATCH ACTION
// ─────────────────────────────────────────────────────────────────────
// broker_send() is synchronous: a route that fans out to Telegram delays
// whatever dispatch action triggered it. CURLOPT_TIMEOUT bounds the whole
// transfer; CURLOPT_CONNECTTIMEOUT bounds the part that hangs longest in
// practice (a host that accepts the packet and never answers).

preg_match('/CURLOPT_TIMEOUT\s*(?:,|=>)\s*(\d+)/', $code, $tMatch);
preg_match('/CURLOPT_CONNECTTIMEOUT\s*(?:,|=>)\s*(\d+)/', $code, $ctMatch);
$totalTimeout   = isset($tMatch[1]) ? (int) $tMatch[1] : 0;
$connectTimeout = isset($ctMatch[1]) ? (int) $ctMatch[1] : 0;

tg($totalTimeout > 0 && $totalTimeout <= 30,
    'CURLOPT_TIMEOUT bounds the whole transfer at a sane value — got: ' . $totalTimeout);

tg($connectTimeout > 0 && $connectTimeout <= $totalTimeout,
    'CURLOPT_CONNECTTIMEOUT is set and no larger than the total timeout — got: '
    . $connectTimeout . ' vs ' . $totalTimeout);

// Now prove those numbers actually bound a hang, rather than merely being
// present. The adapter's own values are applied to a live handle aimed at
// 192.0.2.1 — TEST-NET-1, reserved by RFC 5737 for documentation and
// guaranteed not to route anywhere. Nothing reaches a third party.
//
// The assertion is one-sided (must not EXCEED the bound), so an offline box
// that fails instantly with "network unreachable" passes it too. It cannot
// flake; it can only catch a genuinely unbounded wait.
if (function_exists('curl_init') && $connectTimeout > 0) {
    $started = microtime(true);
    $ch = curl_init('https://192.0.2.1/bot123/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '{}',
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT        => $totalTimeout,
    ]);
    curl_exec($ch);
    curl_close($ch);
    $elapsed = microtime(true) - $started;

    // Generous margin: this asserts "bounded", not "fast".
    $ceiling = $totalTimeout + 5;
    tg($elapsed <= $ceiling,
        'the adapter\'s own timeout values bound an unresponsive upstream: a request '
        . 'to a non-routable address returned in ' . round($elapsed, 2) . 's '
        . '(ceiling ' . $ceiling . 's) — an unbounded wait here would hold open the '
        . 'dispatch action that triggered the route');
} else {
    tg(false, 'ext-curl is available to verify the timeout bound behaviourally');
}

// ─────────────────────────────────────────────────────────────────────
// E. CORRECT SETTINGS STORE, AND OUTBOUND ONLY
// ─────────────────────────────────────────────────────────────────────
// CLAUDE.md GH #79: there are TWO stores. `settings` (name/value, read by
// get_variable(), written by the Settings UI) is the correct one; the tiny
// `config` table read by get_setting() is not, and reading it would return
// the default forever.

tg(preg_match('/FROM\s+`?\{?\$?\w*\}?settings`?/i', $code) === 1,
    'the adapter reads its configuration from the `settings` table');

tg(preg_match('/get_setting\s*\(/', $code) !== 1,
    'the adapter does NOT use get_setting() (that reads the separate `config` table '
    . 'the Settings UI never writes — see CLAUDE.md GH #79)');

tg(preg_match('/WHERE\s+`?name`?\s*=\s*\?/i', $code) === 1,
    'the settings lookup uses a bound parameter, not string interpolation');

tg(preg_match("/broker_register\s*\(\s*'telegram'/", $code) === 1,
    'the adapter self-registers with broker_register(\'telegram\', …)');

if (preg_match('/function\s+_telegram_receive\s*\([^)]*\)\s*\{(.*?)\n\}/s', $code, $rm)) {
    tg(preg_match('/return\s*\[\s*\]\s*;/', $rm[1]) === 1,
        '_telegram_receive() returns an empty array — the adapter is outbound only, '
        . 'so no untrusted inbound payload can enter the routing engine through it');
} else {
    tg(false, '_telegram_receive() could be located (source shape changed — re-review by hand)');
}

echo "Telegram channel security gate: " . ($tests - $fails) . " passed, $fails failed\n";
exit($fails ? 1 : 0);
