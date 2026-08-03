<?php
/**
 * Wall-clock pacing for outbound TTS voice frames.
 *
 * openises/TicketsCAD#28 (@rjonesbsink) — ZelloProxyApp::pumpTtsFrame() used to
 * send exactly one frame per timer tick, which is only correct if ticks land on
 * schedule. A periodic timer is a floor, not a promise. Windows' default timer
 * quantum is ~15.6 ms, so a 20 ms request rounds up to two quanta. Measured
 * against the real React\EventLoop\StreamSelectLoop on Windows:
 *
 *     nominal 20ms -> mean 31.15ms  median 30.67ms  (1.56x)
 *     nominal 10ms -> mean 15.32ms  median 15.04ms  (1.53x)
 *
 * One frame per tick therefore played a 608-frame clip (12,160 ms of audio) over
 * 18.29 s of wall time — every single frame arriving after the receiver needed
 * it, which is audibly choppy.
 *
 * This file is deliberately dependency-free: no namespace, no class, no Ratchet,
 * no ReactPHP. ZelloProxyApp cannot be loaded without the composer vendor tree
 * (it implements a Ratchet interface), and CI does not run `composer install`.
 * Keeping the arithmetic here means the REAL function the proxy calls is the one
 * under test everywhere, instead of a copy of it in a test file — which is the
 * failure mode this project keeps re-learning (see CLAUDE.md on tests that pass
 * against a state the real writer never produces).
 */

/**
 * How many frames a long stall may release in a single tick.
 *
 * The backlog must not be dumped at once: the upstream drops a burst, which is
 * the failure the original one-frame-per-tick pacing existed to avoid. This
 * bounds the catch-up without capping the average rate, because any frames still
 * owed simply come out on the following ticks.
 */
if (!defined('ZELLO_TTS_MAX_BURST')) {
    define('ZELLO_TTS_MAX_BURST', 8);
}

/**
 * Milliseconds of audio to run ahead of the wall clock.
 *
 * Not optional, and not obvious. Fixing the RATE alone measures a clean 1.00x
 * and still sounds rough: the receiver begins playback on frame 0 with nothing
 * buffered, so every subsequent timer wobble is an audible gap. Leading by
 * 100 ms banks a jitter buffer.
 *
 * Worth not over-leading. Larger values finish the stream progressively early
 * (0.96x at 500 ms), so stop_stream fires while the receiver still holds
 * buffered audio and the tail risks clipping. 100 ms is margin without that; the
 * reporter confirmed this value by ear on a real transmission to a live channel.
 */
if (!defined('ZELLO_TTS_LEAD_MS')) {
    define('ZELLO_TTS_LEAD_MS', 100);
}

/**
 * How many frames of a clip are DUE to have been sent by now.
 *
 * Derived from the wall clock, never from a count of timer ticks. That makes it
 * self-correcting in one direction and incapable of error in the other: a late
 * tick emits the backlog so the average rate stays real-time, and it can never
 * run FAST, because the answer comes from the clock rather than from how often
 * we were called.
 *
 * @param float $t0      microtime(true) when the stream opened.
 * @param float $now     microtime(true) at this tick.
 * @param int   $frameMs Audio duration of one frame (20 ms for Zello/Opus).
 * @param int   $total   Frames in the clip; the answer is capped here.
 * @param int   $leadMs  Pre-roll in milliseconds. Defaults to ZELLO_TTS_LEAD_MS.
 * @return int           Frame index that should have been reached, 0..$total.
 */
function zello_tts_frames_due(float $t0, float $now, int $frameMs, int $total, int $leadMs = ZELLO_TTS_LEAD_MS): int
{
    if ($frameMs <= 0) $frameMs = 20;
    if ($total <= 0)   return 0;

    // At least one frame of lead: a zero-lead stream starts the receiver with an
    // empty buffer and underruns on the first wobble.
    $lead = (int) max(1, (int) round($leadMs / $frameMs));

    $elapsedMs = ($now - $t0) * 1000.0;
    if ($elapsedMs < 0) $elapsedMs = 0.0;   // clock stepped backwards

    $due = (int) floor($elapsedMs / $frameMs) + $lead;

    if ($due > $total) $due = $total;
    if ($due < 0)      $due = 0;
    return $due;
}
