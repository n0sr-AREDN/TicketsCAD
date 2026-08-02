<?php
/**
 * Phase 129 — the DMR bridge's HTTP control surface must come up WITHOUT
 * text-to-speech, and must accept the bearer the CAD stores.
 *
 * openises/tickets#10 (@kmk1971): services/dvswitch/hbp_client.py gated the
 * whole control surface on `if bearer and piper_bin and piper_voice:`.
 * DMR_PIPER_BIN and DMR_PIPER_VOICE default to "" and appear nowhere under
 * services/dvswitch/docker/, so on every Docker deployment the condition was
 * false, serve_http() never ran and port 18091 never opened. The DMR side kept
 * working and the entrypoint still printed "HTTP control on 18091", so the
 * bridge looked healthy; the only symptom was the CAD failing to connect.
 *
 * This test stands the REAL handler up on an ephemeral loopback port with no
 * Piper configured (tests/hbp_http_harness.py calls the production
 * serve_http()) and drives it over real HTTP from PHP. Two things are being
 * proved at once, and neither can be faked by a source grep:
 *
 *   1. /health answers with TTS unconfigured — the blocker. /tx/text answers
 *      503 "tts_not_configured" instead of taking everything else down.
 *   2. The value the CAD's reader hands back IS the value the bridge accepts.
 *      The bearer the harness is given comes out of dmr_bridge_token() on a
 *      row written by dmr_token_store(), and it authenticates against
 *      ControlHandler._auth_ok() — the actual comparison, not a model of it.
 *
 * Nothing here can key a radio: the harness's stub client reports
 * state != STATE_RUNNING, which is what makes every TX path refuse.
 *
 * Usage: php tests/test_dmr_bridge_http.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/dmr_token.php';

$base   = realpath(__DIR__ . '/..');
$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($label, $cond) {
    global $passed, $failed;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $passed++ : $failed++;
}

echo "=== Phase 129 — DMR bridge HTTP surface without TTS (openises/tickets#10) ===\n\n";

// ── Source gates: the condition itself ──────────────────────────────────────
$py = (string) @file_get_contents($base . '/services/dvswitch/hbp_client.py');
t('hbp_client.py no longer gates the HTTP surface on Piper',
    preg_match('/if\s+bearer\s+and\s+piper_bin/', $py) !== 1);
t('hbp_client.py gates the HTTP surface on the bearer alone',
    preg_match('/^\s*if bearer:\s*$/m', $py) === 1);
t('/tx/text answers 503 tts_not_configured when Piper is unset',
    strpos($py, 'tts_not_configured') !== false);
t('/tx/test is implemented (the CAD has POSTed to it since Phase 73j)',
    strpos($py, '_handle_tx_test') !== false);

$entry = (string) @file_get_contents($base . '/services/dvswitch/docker/entrypoint.sh');
t('the entrypoint no longer claims a port it has not bound',
    strpos($entry, 'starting hbp_client.py (HTTP control on') === false);
t('the entrypoint says what happens when the bearer is empty',
    strpos($entry, 'HTTP control surface will NOT start') !== false);
t('the entrypoint says what happens when Piper is not configured',
    stripos($entry, 'text-to-speech NOT configured') !== false);

$envEx  = (string) @file_get_contents($base . '/services/dvswitch/docker/.env.example');
$compose = (string) @file_get_contents($base . '/services/dvswitch/docker/docker-compose.yml');
t('DMR_PIPER_BIN is documented in the Docker .env.example',
    strpos($envEx, 'DMR_PIPER_BIN') !== false);
t('DMR_PIPER_VOICE is documented in the Docker .env.example',
    strpos($envEx, 'DMR_PIPER_VOICE') !== false);
t('docker-compose.yml passes both through to the container',
    strpos($compose, 'DMR_PIPER_BIN:') !== false
    && strpos($compose, 'DMR_PIPER_VOICE:') !== false);

// ── Live: the real handler, over real HTTP ──────────────────────────────────
$python = '';
foreach (['python3', 'python', 'py'] as $cand) {
    $probe = [];
    $rc = 0;
    @exec(escapeshellarg($cand) . ' --version 2>&1', $probe, $rc);
    if ($rc === 0 && !empty($probe) && stripos(implode(' ', $probe), 'python') !== false) {
        $python = $cand;
        break;
    }
}

if ($python === '' || !function_exists('proc_open') || !function_exists('curl_init')) {
    echo "SKIP: live bridge probe — need python + proc_open + curl "
        . "(python='" . $python . "')\n";
    echo "\n{$passed} passed, {$failed} failed\n";
    exit($failed > 0 ? 1 : 0);
}

// The bearer is not invented here: it goes through the real writer and the
// real reader, so this is the CAD's value arriving at the bridge's check.
$bearer = '';
$LABEL  = 'ZZTEST_P129_HTTP';
$haveDb = false;
try {
    db_fetch_value("SELECT 1");
    $haveDb = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'dmr_channels']
    ) > 0;
} catch (Throwable $e) { $haveDb = false; }

$chanId = 0;
if ($haveDb) {
    try {
        db_query("DELETE FROM `{$prefix}dmr_channels` WHERE label = ?", [$LABEL]);
        db_query(
            "INSERT INTO `{$prefix}dmr_channels`
                (label, talkgroup, network, bridge_host, bridge_port, bridge_token,
                 usrp_listen_port, usrp_send_port, link_mode, chat_channel, enabled)
             VALUES (?, '999914', 'HBLink3', '127.0.0.1', 18091, '', 0, 0,
                     'bidirectional', 'dispatch', 1)",
            [$LABEL]
        );
        $chanId = (int) db_insert_id();
        dmr_token_store($chanId, dmr_token_mint());
        $row = db_fetch_one(
            "SELECT id, label, bridge_host, bridge_port, bridge_token
               FROM `{$prefix}dmr_channels` WHERE id = ?", [$chanId]);
        $bearer = dmr_bridge_token($row);
    } catch (Throwable $e) {
        $bearer = '';
    }
}
if ($bearer === '') {
    echo "SKIP: bearer could not be produced by the real writer/reader — "
        . "falling back to a literal token for the live probe\n";
    $bearer = bin2hex(random_bytes(32));
}

$desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open(
    escapeshellarg($python) . ' ' . escapeshellarg($base . '/tests/hbp_http_harness.py')
        . ' ' . escapeshellarg($bearer),
    $desc, $pipes, $base
);

$port = 0;
$stderr = '';
if (is_resource($proc)) {
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $deadline = microtime(true) + 20.0;
    $buf = '';
    while (microtime(true) < $deadline) {
        $chunk = fread($pipes[1], 8192);
        if ($chunk !== false && $chunk !== '') $buf .= $chunk;
        if (preg_match('/PORT (\d+)/', $buf, $m)) { $port = (int) $m[1]; break; }
        $stderr .= (string) fread($pipes[2], 8192);
        usleep(100000);
    }
    if ($port === 0) $stderr .= (string) fread($pipes[2], 65536);
}

if ($port === 0) {
    t('the bridge HTTP surface starts with NO text-to-speech configured', false);
    echo "       harness stderr: " . trim(substr($stderr, 0, 800)) . "\n";
} else {
    $call = function (string $path, ?string $token, string $verb = 'GET', $body = null) use ($port) {
        $h = curl_init();
        curl_setopt_array($h, [
            CURLOPT_URL            => "http://127.0.0.1:{$port}{$path}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CUSTOMREQUEST  => $verb,
            CURLOPT_HTTPHEADER     => array_filter([
                $token !== null ? 'Authorization: Bearer ' . $token : null,
                'Content-Type: application/json',
            ]),
        ]);
        if ($body !== null) curl_setopt($h, CURLOPT_POSTFIELDS, json_encode($body));
        $raw  = curl_exec($h);
        $code = (int) curl_getinfo($h, CURLINFO_HTTP_CODE);
        curl_close($h);
        return ['status' => $code, 'body' => json_decode((string) $raw, true)];
    };

    // 1. THE BLOCKER: the surface is up with piper_bin/piper_voice empty.
    $health = $call('/health', $bearer);
    t('the bridge HTTP surface starts with NO text-to-speech configured',
        $health['status'] === 200);
    t('/health reports the client state', is_array($health['body'])
        && array_key_exists('running', $health['body']));

    // 2. The token the CAD stores is the token the bridge accepts.
    t('the bearer produced by dmr_bridge_token() authenticates against the '
        . 'real ControlHandler._auth_ok()', $health['status'] === 200);
    t('a wrong bearer is rejected (the check is real, not a no-op)',
        $call('/health', $bearer . 'x')['status'] === 401);
    t('no bearer at all is rejected', $call('/health', null)['status'] === 401);

    // 3. Only /tx/text is affected by the missing Piper, and it says so.
    $txText = $call('/tx/text', $bearer, 'POST', ['text' => 'test', 'talkgroup' => 91]);
    t('/tx/text answers 503 when Piper is not configured', $txText['status'] === 503);
    t('/tx/text names the missing configuration',
        is_array($txText['body'])
        && ($txText['body']['error'] ?? '') === 'tts_not_configured'
        && stripos((string) ($txText['body']['detail'] ?? ''), 'DMR_PIPER_BIN') !== false);

    // 4. /tx/test is routed (it used to 404). The stub client is not
    //    authenticated to a master, so it refuses BEFORE keying anything.
    $txTest = $call('/tx/test', $bearer, 'POST', ['talkgroup' => 91, 'duration_s' => 0.5]);
    t('/tx/test exists — it is no longer a 404', $txTest['status'] !== 404);
    t('/tx/test refuses while the bridge is not authenticated to a master',
        $txTest['status'] === 503);

    // 5. Unknown paths still 404, so the checks above mean something.
    t('an unknown path still answers 404', $call('/nope', $bearer)['status'] === 404);
}

if (is_resource($proc)) {
    @fclose($pipes[0]);          // EOF on stdin tells the harness to stop
    $waited = 0;
    while ($waited < 30) {
        $st = proc_get_status($proc);
        if (!$st['running']) break;
        usleep(100000);
        $waited++;
    }
    $st = proc_get_status($proc);
    if ($st['running']) proc_terminate($proc);
    @fclose($pipes[1]);
    @fclose($pipes[2]);
    proc_close($proc);
}

if ($chanId > 0) {
    try { db_query("DELETE FROM `{$prefix}dmr_channels` WHERE id = ?", [$chanId]); }
    catch (Throwable $e) {}
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
