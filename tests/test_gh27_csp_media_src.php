<?php
/**
 * Public issue #27 — the CSP had no `media-src`, so no <audio> source
 * that is not plain same-origin http(s) could ever load.
 *
 * ── WHAT HAPPENED ────────────────────────────────────────────────────
 *
 * `inc/security-headers.php` emitted a policy with img-src, style-src,
 * script-src, font-src and connect-src, and no media-src at all. Absent
 * that directive, media falls back to `default-src 'self'` — and 'self'
 * does NOT cover the data: or blob: schemes, because those are opaque
 * origins. (That is exactly why img-src and script-src in the same
 * policy already have to name them explicitly.)
 *
 * Two shipped features were refused by that gap:
 *
 *   * api/tts.php returns the Voice & Speech test sample as
 *     `data:audio/wav;base64,...` and voice-speech.php feeds it to
 *     <audio id="ttsTestAudio">. Synthesis succeeded, the API answered
 *     success:true, the UI toasted "Playing sample", and nothing played.
 *     Chromium's wording is "Media load rejected by URL safety check",
 *     which is a CSP refusal, not a codec problem.
 *   * assets/js/zello-widget.js binds a MediaSource to <audio> through
 *     URL.createObjectURL() for live incoming voice — a blob: URL, same
 *     refusal, silently broken by the same missing directive.
 *
 * ── WHY THIS TEST IS SHAPED THIS WAY ─────────────────────────────────
 *
 * The CSP already had regression tests. Every one of them grepped this
 * file's SOURCE for a substring — "does it contain default-src 'self'",
 * "does img-src mention rainviewer". A directive that was never there
 * passes a grep for the directives that were, so the whole policy could
 * be (and was) missing a fetch directive with the gate green. That is
 * the `tile_mode` lesson in CLAUDE.md: assert on an observable output,
 * not on wiring.
 *
 * So this test does not read the file. It calls build_csp_policy() —
 * the real builder, the same string set_security_headers() puts on the
 * wire — parses it, and asks whether the URLs the app really produces
 * would be allowed. Under the CLI SAPI header() is a no-op and
 * headers_list() is empty, which is why the builder was split out of
 * set_security_headers() in the first place.
 *
 * And because a matcher that only ever answers "allowed" would pass
 * whatever it was handed, the first thing asserted is a positive
 * control: the pre-fix policy (media-src deleted) must be reported as
 * BLOCKING the data: URI. A gate that cannot fail proves nothing.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/security-headers.php';

$root = str_replace('\\', '/', dirname(__DIR__));

echo "=== GH #27 — CSP media-src covers the audio the app actually emits ===\n\n";

$pass = 0; $fail = 0;
function ok(string $what) { global $pass; echo "  PASS  $what\n"; $pass++; }
function bad(string $what, string $why = '') {
    global $fail; echo "  FAIL  $what" . ($why ? " — $why" : '') . "\n"; $fail++;
}

/** Split a policy string into ['directive' => [tokens]]. */
function csp_parse(string $policy): array {
    $out = [];
    foreach (explode(';', $policy) as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '') continue;
        $tokens = preg_split('/\s+/', $chunk);
        $name = strtolower((string) array_shift($tokens));
        $out[$name] = $tokens;
    }
    return $out;
}

/**
 * Would $url be allowed for $directive under $parsed?
 *
 * Only the rules this test needs: scheme-source match, the opaque-origin
 * rule that makes 'self' insufficient for data:/blob:, and default-src
 * fallback when the directive is absent.
 */
function csp_allows(array $parsed, string $directive, string $url): bool {
    $list = $parsed[$directive] ?? $parsed['default-src'] ?? [];
    $list = array_map('strtolower', $list);

    if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $url, $m)) {
        $scheme = strtolower($m[1]) . ':';
        if (in_array($scheme, $list, true)) return true;
        // Opaque origins: 'self' never covers these, they must be named.
        if (in_array($scheme, ['data:', 'blob:', 'filesystem:'], true)) return false;
        // http(s) absolute URLs would need a host match; nothing in this
        // test uses one, so treat as not-allowed rather than guessing.
        return false;
    }
    // Relative URL → same origin.
    return in_array("'self'", $list, true);
}

// ─────────────────────────────────────────────────────────────────────
// 0. Positive control — the matcher must be able to report a refusal
// ─────────────────────────────────────────────────────────────────────

$prefixPolicy = "default-src 'self'; img-src 'self' data: blob:; script-src 'self' blob:";
$preFix = csp_parse($prefixPolicy);

if (!csp_allows($preFix, 'media-src', 'data:audio/wav;base64,UklGRg==')) {
    ok('positive control: with no media-src, a data: audio URI is reported BLOCKED');
} else {
    bad('positive control failed — the matcher cannot detect the original bug, '
        . 'so every assertion below is worthless');
}
if (!csp_allows($preFix, 'media-src', 'blob:http://localhost/9f2c-1')) {
    ok('positive control: with no media-src, a blob: audio URL is reported BLOCKED');
} else {
    bad('positive control failed for blob:');
}
// ...and the matcher must still say YES to something, or it is just a
// constant. img-src in the same pre-fix policy DOES carry data:.
if (csp_allows($preFix, 'img-src', 'data:image/svg+xml;base64,AAAA')) {
    ok('positive control: the matcher allows data: where the directive grants it');
} else {
    bad('matcher refuses a granted scheme — it is answering "no" unconditionally');
}

// ─────────────────────────────────────────────────────────────────────
// 1. The real policy, from the real builder
// ─────────────────────────────────────────────────────────────────────

if (!function_exists('build_csp_policy')) {
    bad('build_csp_policy() is not defined — the policy cannot be asserted on');
    echo "\n=== $pass passed, $fail failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

$policy = build_csp_policy();
$csp    = csp_parse($policy);

if (isset($csp['media-src'])) {
    ok('the emitted policy carries a media-src directive');
} else {
    bad('no media-src in the emitted policy — <audio> still falls back to default-src');
}

if (csp_allows($csp, 'media-src', 'data:audio/wav;base64,UklGRg==')) {
    ok('media-src permits the data:audio/wav sample api/tts.php returns');
} else {
    bad('media-src refuses data: — the Voice & Speech Test button still cannot play');
}

if (csp_allows($csp, 'media-src', 'blob:http://localhost/9f2c-1')) {
    ok('media-src permits blob: — the Zello MediaSource live-voice stream');
} else {
    bad('media-src refuses blob: — live Zello audio is still CSP-blocked');
}

if (csp_allows($csp, 'media-src', 'api/dmr-audio.php?msg_id=1')) {
    ok("media-src permits same-origin media ('self') — DMR/Zello archive clips");
} else {
    bad("media-src lost 'self' — archive clip playback would break");
}

// ─────────────────────────────────────────────────────────────────────
// 2. Narrowness — the grant must not be wider than what is needed
// ─────────────────────────────────────────────────────────────────────

$media = array_map('strtolower', $csp['media-src'] ?? []);
$allowedTokens = ["'self'", 'data:', 'blob:'];
$extra = array_values(array_diff($media, $allowedTokens));
if (!$extra) {
    ok('media-src grants exactly ' . implode(' ', $allowedTokens) . ' and nothing else');
} else {
    bad('media-src is wider than needed', 'unexpected token(s): ' . implode(' ', $extra));
}
foreach (["*", 'https:', 'http:', "'unsafe-inline'", "'unsafe-eval'", "'none'"] as $forbidden) {
    if (!in_array($forbidden, $media, true)) {
        ok("media-src does not contain {$forbidden}");
    } else {
        bad("media-src contains {$forbidden}");
    }
}

// The fix must be a NEW directive, not a loosening of the fallback.
$default = $csp['default-src'] ?? [];
if ($default === ["'self'"]) {
    ok("default-src is still exactly 'self' — the fallback was not widened");
} else {
    bad("default-src changed", implode(' ', $default));
}

// Directives the rest of the app depends on are still present, so a
// future edit to this array cannot quietly drop one.
foreach (['img-src', 'script-src', 'style-src', 'font-src', 'connect-src',
          'frame-ancestors', 'form-action', 'base-uri', 'object-src'] as $d) {
    if (isset($csp[$d])) ok("policy still carries {$d}");
    else                 bad("policy lost {$d}");
}

// ─────────────────────────────────────────────────────────────────────
// 3. Coupling — the grant must match what the endpoint really emits
// ─────────────────────────────────────────────────────────────────────
//
// Derived from api/tts.php rather than hardcoded, so that if the
// endpoint is ever changed to serve the sample from a short-lived URL
// instead of a data: URI, this asserts the NEW shape is covered rather
// than continuing to assert a grant nobody needs any more.

$ttsPath = $root . '/api/tts.php';
if (!is_file($ttsPath)) {
    bad('api/tts.php missing — cannot verify the policy against the real payload');
} else {
    $ttsSrc = (string) file_get_contents($ttsPath);
    if (preg_match("/'audio'\s*=>\s*'([^']*)'/", $ttsSrc, $m)) {
        $payload = $m[1] . 'UklGRg==';
        if (csp_allows($csp, 'media-src', $payload)) {
            ok('media-src covers the payload api/tts.php actually returns (' . $m[1] . '…)');
        } else {
            bad('api/tts.php returns a media payload the policy refuses', $m[1] . '…');
        }
    } else {
        bad('could not find the audio payload literal in api/tts.php — '
            . 'the coupling assertion is stale and should be updated');
    }
}

// The consumers that justify each scheme. If one of these disappears,
// the corresponding grant above should be revisited rather than kept
// out of habit.
$voice = @file_get_contents($root . '/voice-speech.php');
if ($voice !== false && strpos($voice, 'id="ttsTestAudio"') !== false) {
    ok('voice-speech.php still has the <audio id="ttsTestAudio"> element (justifies data:)');
} else {
    bad('the TTS test <audio> element is gone — re-check whether media-src still needs data:');
}

$zello = @file_get_contents($root . '/assets/js/zello-widget.js');
if ($zello !== false && preg_match('/audio\.src\s*=\s*URL\.createObjectURL/', $zello)) {
    ok('zello-widget.js still binds an object URL to <audio> (justifies blob:)');
} else {
    bad('the Zello object-URL playback path is gone — re-check whether media-src still needs blob:');
}

// ─────────────────────────────────────────────────────────────────────
// 4. The playback failure must not be discarded (issue #27, part 2)
// ─────────────────────────────────────────────────────────────────────
//
// This half is what made part 1 undiagnosable: the browser explained
// the refusal and the code deleted the explanation.

$ttsJs = @file_get_contents($root . '/assets/js/tts-config.js');
if ($ttsJs === false) {
    bad('assets/js/tts-config.js missing');
} else {
    if (!preg_match('/\.play\(\)\s*\.catch\(function\s*\(\s*\)\s*\{\s*\}\s*\)/', $ttsJs)) {
        ok('tts-config.js no longer swallows the playback rejection');
    } else {
        bad('tts-config.js still discards the play() rejection with an empty catch');
    }
    if (strpos($ttsJs, 'a.onerror') !== false) {
        ok('tts-config.js surfaces the <audio> element error');
    } else {
        bad('tts-config.js does not report the audio element error');
    }
    if (strpos($ttsJs, "toast('Playing sample from engine") === false) {
        ok('tts-config.js no longer claims playback succeeded before calling play()');
    } else {
        bad('tts-config.js still toasts success before playback is attempted');
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
