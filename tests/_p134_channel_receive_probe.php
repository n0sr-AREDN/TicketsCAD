<?php
/**
 * Cold-process probe for tests/test_phase134_poller.php.
 *
 * get_variable() caches every `settings` row in a function-static array on
 * its FIRST call and never re-reads the table for the rest of the process
 * (inc/functions.php) — the same problem tests/_par_setting_probe.php and
 * tests/_p132_settings_probe.php exist for. channel_receive_run() reads
 * `{channel}_poll_inbound` through get_variable() (per the standing GH #79
 * rule), so a test that writes that setting and then calls
 * channel_receive_run() again IN THE SAME PROCESS would observe its own
 * process-local cache, not what a real poller tick (a fresh `php
 * tools/channel_receive_tick.php` invocation every time) would see. This
 * probe is a fresh interpreter per call, so every scenario proves the real
 * cross-process behaviour.
 *
 * Registers ONE synthetic, throwaway broker channel per call — never
 * telegram/slack, never a live HTTP call — with a receive() callback whose
 * behaviour is chosen by argv, so the parent test can drive every branch
 * of channel_receive_run() (opted out, opted in + healthy, opted in +
 * throws) without touching a real channel or the network.
 *
 * File name starts with `_` so tools/test_all.php, which globs
 * test_*.php, does not try to run it as a test.
 *
 * Usage:
 *   php tests/_p134_channel_receive_probe.php <channel_code> <behavior>
 *     behavior: 'ok'    -> receive() returns one fixed test message, no
 *                          dedupe_key declared (this probe never exercises
 *                          dedup — that is covered directly, in-process, by
 *                          test_phase134_poller.php against broker_receive()
 *                          itself).
 *               'throw' -> receive() always throws a RuntimeException.
 *   Prints JSON: {"receive_called": N, "result": <channel_receive_run() return>}
 *
 *   php tests/_p134_channel_receive_probe.php --sched-required <code1,code2,...>
 *   Registers each comma-separated code as a synthetic pollable channel
 *   (receive() is never invoked — sched_job_required() only reads the
 *   'pollable' flag + the `{code}_poll_inbound` setting, never polls), then
 *   prints JSON: <sched_job_required('channel_receive_tick') return>.
 *   The caller is responsible for having written `{code}_poll_inbound`
 *   BEFORE spawning this probe (a fresh process = a fresh get_variable()
 *   cache reading current DB state).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/sse.php';    // broker_send()'s local_chat path needs sse_publish() defined
require_once __DIR__ . '/../inc/channel-receive.php'; // pulls in inc/broker.php

if (($argv[1] ?? '') === '--sched-required') {
    require_once __DIR__ . '/../inc/scheduled-jobs.php';
    $codes = array_filter(array_map('trim', explode(',', (string) ($argv[2] ?? ''))));
    foreach ($codes as $code) {
        broker_register($code, [
            'name'     => ucfirst($code) . ' (Phase134 probe)',
            'send'     => null,
            'receive'  => function ($limit = 50) { return []; },
            'status'   => null,
            'pollable' => true,
        ]);
    }
    echo json_encode(sched_job_required('channel_receive_tick'));
    exit(0);
}

$channel  = (string) ($argv[1] ?? '');
$behavior = (string) ($argv[2] ?? 'ok');

if ($channel === '') {
    fwrite(STDERR, "Usage: php tests/_p134_channel_receive_probe.php <channel_code> <ok|throw>\n"
        . "   or: php tests/_p134_channel_receive_probe.php --sched-required <code1,code2,...>\n");
    exit(1);
}

// A file-scoped counter the synthetic receive() closure increments — this
// process never calls it more than once per run, but counting rather than
// booleaning makes a future multi-channel probe call trivially reusable.
$GLOBALS['__p134_probe_receive_calls'] = 0;

if ($behavior === 'throw') {
    broker_register($channel, [
        'name'     => 'Phase134 Probe (throw)',
        'send'     => null,
        'receive'  => function ($limit = 50) {
            $GLOBALS['__p134_probe_receive_calls']++;
            throw new RuntimeException('phase134 probe: synthetic receive() failure');
        },
        'status'   => null,
        'pollable' => true,
        // Deliberately NO dedupe_key — this probe is about backoff/opt-in
        // behaviour, not dedup (covered separately, in-process).
    ]);
} else {
    broker_register($channel, [
        'name'     => 'Phase134 Probe (ok)',
        'send'     => null,
        'receive'  => function ($limit = 50) use ($channel) {
            $GLOBALS['__p134_probe_receive_calls']++;
            return [[
                'from' => 'phase134_probe_sender',
                'body' => 'phase134 probe message',
                'to'   => $channel,
                'type' => 'message',
            ]];
        },
        'status'   => null,
        'pollable' => true,
    ]);
}

$result = channel_receive_run();

echo json_encode([
    'receive_called' => $GLOBALS['__p134_probe_receive_calls'],
    'result'          => $result,
]);
exit(0);
