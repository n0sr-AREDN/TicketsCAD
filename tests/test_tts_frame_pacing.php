<?php
/**
 * openises/TicketsCAD#28 (@rjonesbsink) — TTS frame pacing assumed the timer
 * was a guarantee.
 *
 * ZelloProxyApp::pumpTtsFrame() sent exactly ONE frame per periodic-timer tick.
 * That is only correct if ticks land on schedule, and a periodic timer is a
 * floor, not a promise. Windows' default timer quantum is ~15.6 ms, so a 20 ms
 * request rounds up to two quanta. Measured on this project's Windows dev box
 * against the real React\EventLoop\StreamSelectLoop:
 *
 *     nominal 20ms -> mean 31.15ms  median 30.67ms  (1.56x)
 *     nominal 10ms -> mean 15.32ms  median 15.04ms  (1.53x)
 *
 * A 608-frame clip (12,160 ms of audio) therefore took 18.29 s of wall time,
 * with every frame arriving after the receiver needed it — 608/608 modelled
 * underruns, and audibly choppy on a live channel. After the fix the same clip
 * measured 12.06 s (0.99x) with 1/608 (frame 0, which cannot arrive early).
 *
 * ── WHAT IS ASSERTED, AND WHY IN THIS SHAPE ──────────────────────────────
 *
 * The arithmetic lives in proxy/tts_pacer.php — deliberately dependency-free,
 * because ZelloProxyApp implements a Ratchet interface and CI does not run
 * `composer install`, so the class cannot be loaded there. Putting the pacing
 * decision in a plain function means the REAL code the proxy runs is under test
 * on every platform, rather than a re-implementation of it in a test file.
 *
 * Two layers:
 *   1. Deterministic, everywhere — feed zello_tts_frames_due() a clock and
 *      assert the answers. A one-frame-per-tick pacer is modelled alongside as
 *      the positive control, so the numbers that used to be produced are right
 *      there next to the ones produced now.
 *   2. Measured, where vendor/ exists — drive the real beginTtsStream() and
 *      pumpTtsFrame() under a real ReactPHP loop with a recording upstream, and
 *      assert the wall-vs-audio ratio. This is the layer that would catch the
 *      helper being computed and then ignored, which a source grep would not.
 *
 * Run: /c/xampp/8.2.4/php/php.exe tests/test_tts_frame_pacing.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$base = dirname(__DIR__);
require_once $base . '/proxy/tts_pacer.php';

$pass = 0;
$fail = 0;

function t(string $label, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { echo "[PASS] {$label}\n"; $pass++; }
    else       { echo "[FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
}

echo "=== openises/TicketsCAD#28 — TTS frame pacing ===\n";
echo "PHP " . PHP_VERSION . " on " . PHP_OS . "\n\n";

$FRAME_MS = 20;
$LEAD     = (int) round(ZELLO_TTS_LEAD_MS / $FRAME_MS);   // 5 frames of pre-roll

// ─────────────────────────────────────────────────────────────────────────
// 1. The clock decides, not the tick count.
// ─────────────────────────────────────────────────────────────────────────
echo "-- 1. frames due are derived from the wall clock --\n";

$t0 = 1000.0;   // any fixed origin; the function only uses the difference

t('a freshly opened stream owes exactly the pre-roll, not the whole clip',
    zello_tts_frames_due($t0, $t0, $FRAME_MS, 600) === $LEAD,
    'got ' . zello_tts_frames_due($t0, $t0, $FRAME_MS, 600) . ", expected {$LEAD}");

t('the pre-roll is 100 ms of audio (the value confirmed by ear on a live channel)',
    $LEAD * $FRAME_MS === 100);

// One second in, 50 frames of audio have played; +lead.
t('one second in, 55 frames are due (50 played + 5 pre-roll)',
    zello_tts_frames_due($t0, $t0 + 1.0, $FRAME_MS, 600) === 55,
    'got ' . zello_tts_frames_due($t0, $t0 + 1.0, $FRAME_MS, 600));

t('the answer is capped at the clip length',
    zello_tts_frames_due($t0, $t0 + 999.0, $FRAME_MS, 600) === 600);

t('a clock that steps backwards owes the pre-roll, never a negative index',
    zello_tts_frames_due($t0, $t0 - 5.0, $FRAME_MS, 600) === $LEAD);

t('a zero frame_ms falls back to 20 ms rather than dividing by zero',
    zello_tts_frames_due($t0, $t0 + 1.0, 0, 600) === 55);

t('an empty clip owes nothing',
    zello_tts_frames_due($t0, $t0 + 1.0, $FRAME_MS, 0) === 0);

// The property that makes this safe: it can fall behind and catch up, but it
// can never run FAST, because the number comes from the clock.
$overrun = false;
for ($ms = 0; $ms <= 12000; $ms += 37) {
    $due = zello_tts_frames_due($t0, $t0 + $ms / 1000.0, $FRAME_MS, 100000);
    if ($due * $FRAME_MS > $ms + ZELLO_TTS_LEAD_MS) { $overrun = true; break; }
}
t('across 12 s of clock it never gets ahead of real time by more than the pre-roll',
    !$overrun);

// ─────────────────────────────────────────────────────────────────────────
// 2. POSITIVE CONTROL — one frame per tick, the pre-fix pacer.
// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. positive control: one frame per tick --\n";

/**
 * The pre-fix pacer, modelled exactly: pumpTtsFrame() advanced the index by one
 * on every call and consulted no clock at all.
 */
function prefix_frames_after_ticks(int $ticks, int $total): int
{
    return min($ticks, $total);
}

// Replay 3 s of audio (150 frames at 20 ms) through both pacers, using the tick
// interval Windows actually delivers for a 20 ms request (31.15 ms measured).
$total     = 150;
$audioMs   = $total * $FRAME_MS;                 // 3000
$winTickMs = 31.15;

$ticksIn3s   = (int) floor($audioMs / $winTickMs);
$prefixFrames = prefix_frames_after_ticks($ticksIn3s, $total);
$fixedFrames  = zello_tts_frames_due($t0, $t0 + $audioMs / 1000.0, $FRAME_MS, $total);

printf("       after %d ms of audio at a %.2f ms tick: pre-fix has sent %d/%d frames, "
    . "wall-clock pacing has sent %d/%d\n",
    $audioMs, $winTickMs, $prefixFrames, $total, $fixedFrames, $total);

t('control: one-frame-per-tick is still short of the clip when the audio should have finished',
    $prefixFrames < $total,
    'the control kept up, so it no longer reproduces the bug');

t('wall-clock pacing has delivered the whole clip by then',
    $fixedFrames === $total, "got {$fixedFrames}/{$total}");

$prefixRatio = $total / max(1, $prefixFrames);
printf("       pre-fix stretches the clip by %.2fx (reporter measured 1.56x, we measured 1.50x)\n",
    $prefixRatio);
t('control: the pre-fix stretch is material, not rounding',
    $prefixRatio > 1.3, sprintf('%.2fx', $prefixRatio));

// The burst cap exists so a long stall does not dump the backlog in one write.
t('a catch-up burst is bounded (the upstream drops a burst — that is why the '
    . 'original paced at all)',
    ZELLO_TTS_MAX_BURST > 1 && ZELLO_TTS_MAX_BURST <= 32,
    'ZELLO_TTS_MAX_BURST=' . ZELLO_TTS_MAX_BURST);

// ─────────────────────────────────────────────────────────────────────────
// 3. The proxy must actually USE it.
// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. wiring --\n";

$zpa = (string) @file_get_contents($base . '/proxy/ZelloProxyApp.php');

t('ZelloProxyApp loads the pacer',
    strpos($zpa, "require_once __DIR__ . '/tts_pacer.php'") !== false);
t('pumpTtsFrame asks the pacer how many frames are due',
    strpos($zpa, 'zello_tts_frames_due(') !== false);
t('the stream records a wall-clock origin to pace against',
    preg_match("/'t0'\s*=>\s*microtime\(true\)/", $zpa) === 1);
t('the timer ticks at half the frame interval so a backlog always has a tick to ride out on',
    strpos($zpa, '$frameMs / 2000.0') !== false);
t('the completion log reports the wall-vs-audio ratio (what made this measurable)',
    strpos($zpa, 'ms audio in ') !== false && strpos($zpa, '$ratio') !== false);
t('pumpTtsFrame no longer advances by exactly one frame per call',
    preg_match('/function pumpTtsFrame.*?while\s*\(\s*\$state\[.idx.\]\s*<\s*\$due/s', $zpa) === 1);

// ─────────────────────────────────────────────────────────────────────────
// 4. MEASURED — the real functions under a real event loop.
// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. measured against a real ReactPHP loop --\n";

if (!is_file($base . '/vendor/autoload.php')) {
    echo "       (skipped: vendor/ absent — ZelloProxyApp implements a Ratchet\n"
       . "        interface and CI does not run composer install. The arithmetic\n"
       . "        above is the same code this would drive.)\n";
} else {
    require_once $base . '/vendor/autoload.php';
    if (!function_exists('plog')) { function plog($m) {} }
    require_once $base . '/proxy/ZelloProxyApp.php';

    $frames = 150;                       // 3 s of audio — long enough to measure
    $sends  = [];

    $upstream = new class($sends) {
        public $log;
        public function __construct(&$log) { $this->log = &$log; }
        public function isConnected(): bool { return true; }
        public function sendBinary($p) { $this->log[] = microtime(true); }
        public function sendCommand(array $c) {}
    };

    $rc  = new ReflectionClass(NewUI\Proxy\ZelloProxyApp::class);
    $app = $rc->newInstanceWithoutConstructor();
    $set = function (string $prop, $val) use ($rc, $app) {
        $p = $rc->getProperty($prop); $p->setAccessible(true); $p->setValue($app, $val);
    };
    $loop = React\EventLoop\Loop::get();
    $set('loop', $loop);
    $set('upstream', $upstream);
    $set('clients', new SplObjectStorage());
    $set('clientAuth', []);
    $set('pdo', null);
    $set('ttsStreamCounter', 1);

    // Exactly what handleUpstreamEvent leaves behind when Zello assigns a
    // stream id — then the REAL beginTtsStream() sets up the REAL timer.
    $pend = $rc->getProperty('pendingTtsStarts'); $pend->setAccessible(true);
    $pend->setValue($app, [7 => [
        'frames'   => array_fill(0, $frames, str_repeat("\x00", 40)),
        'channel'  => 'pacing-test', 'text' => 'pacing test', 'outbox_id' => 0,
        'frame_ms' => $FRAME_MS, 'local_id' => 1, 'started' => time(),
    ]]);

    $begin = $rc->getMethod('beginTtsStream'); $begin->setAccessible(true);
    $tStart = microtime(true);
    $begin->invoke($app, 7, 99);

    // Stop as soon as the last frame is out — the finalise branch writes to the
    // database, which this harness deliberately does not have.
    $wd = null;
    $wd = $loop->addPeriodicTimer(0.005, function () use (&$sends, $frames, $loop, &$wd, $tStart, $FRAME_MS) {
        $capMs = $frames * $FRAME_MS * 3 + 3000;      // never hang the suite
        if (count($sends) >= $frames || (microtime(true) - $tStart) * 1000 > $capMs) {
            $loop->cancelTimer($wd);
            $loop->stop();
        }
    });
    $loop->run();

    $wallMs  = (microtime(true) - $tStart) * 1000.0;
    $audioMs2 = $frames * $FRAME_MS;
    $ratio   = $wallMs / $audioMs2;

    // Model receiver buffer occupancy: it drains in real time from frame 0.
    $under = 0;
    foreach ($sends as $i => $ts) {
        if (($ts - $tStart) > ($i * $FRAME_MS / 1000.0) + 0.0005) $under++;
    }

    printf("       %d/%d frames, %d ms audio in %d ms wall (%.2fx), %d modelled underruns\n",
        count($sends), $frames, $audioMs2, (int) round($wallMs), $ratio, $under);

    t('the real pump delivers every frame', count($sends) === $frames,
        count($sends) . " of {$frames}");

    // The defect measured 1.50x here. Anything at or below ~1.15x means frames
    // are keeping up with the audio; the tolerance is for loaded CI boxes.
    t('the clip streams in about real time, not stretched by the timer quantum',
        $ratio <= 1.15, sprintf('%.2fx — pre-fix measured 1.50x on this machine', $ratio));

    // And it must not run FAST either: finishing early means stop_stream fires
    // while the receiver still holds audio, and the tail clips.
    t('and it does not run ahead of the audio', $ratio >= 0.90, sprintf('%.2fx', $ratio));

    t('the receiver is not starved between frames',
        $under <= max(2, (int) ($frames * 0.02)), "{$under} of {$frames} frames arrived late");
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
