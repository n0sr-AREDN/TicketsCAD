<?php
/**
 * Channel: Telegram
 *
 * Posts incident / PAR / system alerts to a Telegram chat via a bot,
 * using the Telegram Bot API (sendMessage). Outbound only.
 *
 * Configuration (Settings → Telegram):
 *   telegram_bot_token = Bot token from @BotFather
 *   telegram_chat_id   = Target group chat ID (negative, e.g. -100123456789)
 *
 * Setup: docs/TELEGRAM-SETUP-GUIDE.md
 */

broker_register('telegram', [
    'name'    => 'Telegram',
    'send'    => '_telegram_send',
    'receive' => '_telegram_receive',
    'status'  => '_telegram_status'
]);

/**
 * Telegram bot tokens are `<numeric bot id>:<35-ish char secret>`. Chat ids
 * are integers, negative for groups and supergroups. Validating both lets a
 * mistyped or whitespace-padded value fail with something an admin can act
 * on, rather than an opaque 404 from Telegram.
 */
define('TELEGRAM_TOKEN_RE',   '/^\d+:[A-Za-z0-9_-]{20,}$/');
define('TELEGRAM_CHAT_ID_RE', '/^-?\d{1,20}$/');

function _telegram_send(array $message) {
    $config = _telegram_get_config();
    $token  = trim((string) ($config['telegram_bot_token'] ?? ''));

    // The destination is bound to the bot credential and comes from
    // configuration ONLY — deliberately not overridable per message.
    //
    // inc/router.php forwards a matched message array wholesale to the
    // destination adapter (_router_transform() rewrites body/priority/type
    // and leaves every other key intact), and two receive handlers return
    // raw third-party JSON into that path — _slack_receive() returns
    // $data['messages'], _sms_receive() returns $data['threads']. Neither
    // provider currently lets a message author set an arbitrary top-level
    // key, so an override here is unreachable today; but that is a property
    // of someone else's response schema, not of this codebase, and nothing
    // tells us when it changes. Reading the chat id from config removes the
    // question entirely.
    //
    // A per-message destination may be worth having later (routing weather
    // to one chat and dispatch to another), but it needs an admin-configured
    // allowlist plus the router's _is_routed_forward trust marker — not an
    // unchecked key. See openises/TicketsCAD#10 review.
    $chatId = trim((string) ($config['telegram_chat_id'] ?? ''));

    if ($token === '' || $chatId === '') {
        return ['success' => false, 'error' => 'Telegram not configured'];
    }
    if (!preg_match(TELEGRAM_TOKEN_RE, $token)) {
        return ['success' => false, 'error' => 'Telegram bot token is malformed (expected "123456:ABC-DEF..." from @BotFather)'];
    }
    if (!preg_match(TELEGRAM_CHAT_ID_RE, $chatId)) {
        return ['success' => false, 'error' => 'Telegram chat ID is malformed (expected an integer; group IDs are negative, e.g. -100123456789)'];
    }

    $body = $message['body'] ?? '';
    if (!$body) {
        return ['success' => false, 'error' => 'Message body required'];
    }

    $payload = json_encode([
        'chat_id' => $chatId,
        'text'    => $body,
    ]);

    $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
    // Stated explicitly rather than relying on defaults: the defaults are
    // safe, but a host with unusual curl.* ini settings changes that, and a
    // reader cannot otherwise tell "safe by default" from "nobody checked".
    // Matches inc/webhooks.php and api/dmr-lookup.php.
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_POST            => true,
        CURLOPT_POSTFIELDS      => $payload,
        CURLOPT_HTTPHEADER      => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_FOLLOWLOCATION  => false,
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT  => 5,
        // Sends are synchronous inside broker_send(), so a route fanning out
        // to Telegram adds up to this many seconds to the request that
        // triggered it.
        CURLOPT_TIMEOUT         => 10,
    ]);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['success' => false, 'error' => 'Telegram request failed: ' . $err];
    }

    $data = json_decode($resp, true);
    if (!empty($data['ok'])) {
        return ['success' => true, 'message_id' => $data['result']['message_id'] ?? null];
    }
    // Surface Telegram's own `description` when it sends one — those are
    // actionable ("chat not found", "bot was blocked by the user"). When it
    // doesn't, return a fixed string rather than echoing the raw body into
    // the admin UI; log the body instead so it's still diagnosable. The
    // token lives in the URL, not the body, so it is not logged here.
    if (isset($data['description'])) {
        return ['success' => false, 'error' => 'Telegram API: ' . $data['description']];
    }
    error_log('[telegram] unexpected sendMessage response: ' . substr((string) $resp, 0, 500));
    return ['success' => false, 'error' => 'Telegram API returned an unexpected response (see error log)'];
}

/** No inbound polling — TicketsCAD only posts to Telegram, it doesn't read replies. */
function _telegram_receive($limit = 50) {
    return [];
}

function _telegram_get_config() {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $keys = ['telegram_bot_token', 'telegram_chat_id'];
    $config = [];
    foreach ($keys as $k) {
        try {
            $val = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", [$k]);
            $config[$k] = $val;
        } catch (Exception $e) {
            // Treat as unconfigured (_telegram_send then reports "not
            // configured"), but don't swallow the reason — a settings-table
            // failure here is otherwise indistinguishable from a blank field.
            $config[$k] = null;
            error_log("[telegram] could not read setting '{$k}': " . $e->getMessage());
        }
    }
    return $config;
}

function _telegram_status() {
    $config = _telegram_get_config();
    $token  = trim((string) ($config['telegram_bot_token'] ?? ''));
    $chatId = trim((string) ($config['telegram_chat_id'] ?? ''));
    if ($token === '' || $chatId === '') return 'not_configured';
    // Report malformed credentials as not-configured rather than configured:
    // a status of "configured" that cannot send is worse than an honest one.
    if (!preg_match(TELEGRAM_TOKEN_RE, $token))     return 'not_configured';
    if (!preg_match(TELEGRAM_CHAT_ID_RE, $chatId))  return 'not_configured';
    return 'configured';
}
